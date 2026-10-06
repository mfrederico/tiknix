<?php
/**
 * app\KnowledgeBase: an agent's question finds the entry that answers it and NOT one that only
 * shares a few words — a wrong answer believed is worse than "nothing known". And a notebook
 * lesson is offered as a candidate only when it is about the platform.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\KnowledgeBase as KB;

class KnowledgeBaseTest extends TestCase {
    private const SEED = ['title' => 'git push fails with 403: the only remote, seed, is read-only',
        'symptom' => 'In a hosted app, `git push` answers 403; `git remote -v` shows one remote named `seed` that serves only git-upload-pack. The agent wonders whether the next build task will see its commit.',
        'cause' => 'By design. The checkout IS the running app, and every build task starts as a git worktree of its main at HEAD.',
        'fix' => 'Do not push and do not escalate. Commit on main, check git status is clean.'];
    private const SEEDS = ['title' => 'A seed in database/seeds never runs; the table or permission it makes is missing after a merge or update',
        'symptom' => 'A seeder was written under database/seeds. It worked by hand, but after the merge the table, starter rows or authcontrol rows are not there.',
        'cause' => 'Only services/Schema/Seeds is applied by clitool --build.', 'fix' => 'Move the seed to services/Schema/Seeds with a number from 50 up.'];

    public function testAQuestionInAnAgentsOwnWordsFindsItsEntry(): void {
        $asked = 'The push failed. The only remote is seed and it is read-only: only git-upload-pack is served, then a 403. Will the next task see this commit?';
        $this->assertGreaterThanOrEqual(KB::MIN_SCORE, KB::score($asked, self::SEED));
        $this->assertGreaterThan(KB::score($asked, self::SEEDS), KB::score($asked, self::SEED), 'the push entry, not the one that also says "seed"');
        $this->assertGreaterThanOrEqual(KB::MIN_SCORE, KB::score('git push 403', self::SEED), 'a terse question with the error code');
        $this->assertGreaterThanOrEqual(KB::MIN_SCORE, KB::score('my seeder in database/seeds did not run after the merge, the table is missing', self::SEEDS));
    }

    public function testAQuestionAboutSomethingElseGetsNothing(): void {
        foreach (['How do I add a column to the booking form?', 'Stripe webhook returns 400 signature mismatch', 'the page is slow when listing 5000 customers',
                  'what is the best way to seed demo customers for the cafe?'] as $q) {
            $this->assertLessThan(KB::MIN_SCORE, KB::score($q, self::SEED), $q);
            $this->assertLessThan(KB::MIN_SCORE, KB::score($q, self::SEEDS), $q);
        }
        $this->assertSame(0.0, KB::score('the and of to', self::SEED), 'nothing meaningful asked');
    }

    public function testWordsAreFoldedAndDistinctiveTokensKept(): void {
        $w = KB::words('The seeds in database/seeds never run; entries are missing. HTTP 403!');
        foreach (['seed', 'database/seeds', 'never', 'run', 'entry', 'missing', 'http', '403'] as $x) $this->assertContains($x, $w, $x);
        $this->assertNotContains('the', $w);
        $this->assertContains('update', KB::words('run clitool --update'), 'a flag is found by its name');
    }

    public function testOnlyALessonAboutThePlatformIsOffered(): void {
        $this->assertTrue(KB::looksPlatform('Seeds must live in services/Schema/Seeds; database/seeds is never applied'));
        $this->assertTrue(KB::looksPlatform('The builder sandbox has no conf/config.ini, so a task cannot run clitool there'));
        $this->assertTrue(KB::looksPlatform('git push answers 403 because the seed remote is read-only'));
        $this->assertFalse(KB::looksPlatform('Announcements are authored only by members who own a practitioner page'));
        $this->assertFalse(KB::looksPlatform('The vault grant modal posts to /vault/grant and expects a grantee email'));
    }

    public function testAnEntryNeedsWhatIsSeenAndWhatToDo(): void {
        $this->assertSame([], KB::problems(['title' => 'git push fails with 403', 'fix' => 'Do not push.']));
        $this->assertArrayHasKey('fix', KB::problems(['title' => 'git push fails with 403', 'fix' => ' ']));
        $this->assertArrayHasKey('title', KB::problems(['title' => 'push', 'fix' => 'x']));
        $this->assertArrayHasKey('status', KB::problems(['title' => 'git push fails with 403', 'fix' => 'x', 'status' => 'live']));
    }
}
