<?php
/**
 * lib/GoalBrief and lib/ToolUse — the Builder create form's answers.
 *
 *   compose   who it is for and how we will know it worked are written INTO the goal, so the
 *             planner, a single task's agent, a retry and a re-plan all read them
 *   split     takes them back out (Reuse puts them in the form again), and leaves a goal
 *             document's own headings alone
 *   planner   the brief tells the planner to honour both sections
 *   ToolUse   records choices, refuses prose; a tally counts each option's values
 */

namespace tests\unit;

use app\GoalBrief;
use app\ToolUse;
use PHPUnit\Framework\TestCase;

class GoalBriefTest extends TestCase {

    public function testAnAudienceBecomesTheLevelNewPagesAreSeededAt(): void {
        $this->assertSame(101, GoalBrief::level('anyone'));
        $this->assertSame(100, GoalBrief::level('members'));
        $this->assertSame(50, GoalBrief::level('admins'));
        $this->assertNull(GoalBrief::level('none'));
        $brief = GoalBrief::compose('A booking page.', 'members');
        $this->assertStringContainsString("## Who it is for\nSigned-in members only.", $brief);
        $this->assertStringContainsString('MEMBER (authcontrol level 100)', $brief);
        $this->assertStringContainsString('PUBLIC (authcontrol level 101)', GoalBrief::compose('x', 'anyone'));
        $this->assertStringContainsString('ADMIN (authcontrol level 50)', GoalBrief::compose('x', 'admins'));
    }

    public function testNoPagesWritesNothingAboutAccess(): void {
        $this->assertSame('Fix the footer year.', GoalBrief::compose(" Fix the footer year.\n", 'none'));
    }

    public function testAnAnswerNobodyGaveIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        GoalBrief::compose('x', '');
    }

    public function testSplitIsTheInverseOfCompose(): void {
        foreach (['anyone', 'members', 'admins', 'none'] as $who) {
            foreach (['', "A member sees only their own invoices.\n- the form refuses a past date"] as $done) {
                $back = GoalBrief::split(GoalBrief::compose("# Invoices\n\nLet people pay.", $who, $done));
                $this->assertSame("# Invoices\n\nLet people pay.", $back['goal'], "{$who}/" . strlen($done));
                $this->assertSame($done, $back['acceptance']);
                // 'none' with nothing else leaves no trace, so it reads as not answered yet.
                $this->assertSame($who === 'none' && $done === '' ? '' : $who, $back['audience']);
            }
        }
    }

    public function testAGoalDocumentsOwnHeadingsAreLeftAlone(): void {
        $doc = "# Plan\n\n## Who it is for\nCat owners in Denver, mostly.\nAnd their sitters.\n\n## Scope\nA cafe.";
        $this->assertSame(['goal' => $doc, 'audience' => '', 'acceptance' => ''], GoalBrief::split($doc));
        // …and composing over it still reads back correctly.
        $back = GoalBrief::split(GoalBrief::compose($doc, 'admins', 'It lists customers.'));
        $this->assertSame([$doc, 'admins', 'It lists customers.'], [$back['goal'], $back['audience'], $back['acceptance']]);
    }

    public function testThePlannerIsToldToHonourBothAnswers(): void {
        $src = file_get_contents(\app\Paths::root() . '/lib/PlanRunner.php');
        $this->assertStringContainsString('`## Who it is for`', $src);
        $this->assertStringContainsString('`## How we will know it worked`', $src);
        // The headings the planner is told about are the ones compose() writes.
        $brief = GoalBrief::compose('x', 'anyone', 'y');
        $this->assertStringContainsString("\n## Who it is for\n", $brief);
        $this->assertStringContainsString("\n## How we will know it worked\n", $brief);
    }

    public function testToolUseRecordsChoicesNotText(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('choices, not text');
        ToolUse::record('builder.create', 1, ['tags' => 'api, authentication, the admin password is hunter2']);
    }

    public function testATallyCountsEachOptionsValues(): void {
        $t = ToolUse::tally([
            ['id' => 1, 'member_ref' => 7, 'choices' => '{"path":"plan","tags":false,"audience":"members"}'],
            ['id' => 2, 'member_ref' => 7, 'choices' => '{"path":"plan","tags":false,"audience":"admins"}'],
            ['id' => 3, 'member_ref' => 9, 'choices' => '{"path":"task","tags":true,"audience":"members"}'],
        ]);
        $this->assertSame(3, $t['uses']);
        $this->assertSame(2, $t['people']);
        $this->assertSame(['plan' => 2, 'task' => 1], $t['options']['path']);
        $this->assertSame(['no' => 2, 'yes' => 1], $t['options']['tags']);
        $this->assertSame(['members' => 2, 'admins' => 1], $t['options']['audience']);
    }

    public function testARowThatDoesNotParseStopsTheCount(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tooluse row 4');
        ToolUse::tally([['id' => 4, 'member_ref' => 1, 'choices' => '{not json']]);
    }
}
