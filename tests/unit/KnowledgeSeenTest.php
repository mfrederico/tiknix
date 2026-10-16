<?php
/**
 * app\KnowledgeBase::propose counts a lesson written down again instead of dropping it, on the
 * entry it repeats — or, for a candidate merged into a published entry, on that entry — and an
 * entry that has come up RULE_AFTER times is offered as a rule.
 */
namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\KnowledgeBase as KB;

class KnowledgeSeenTest extends ConceptsTestCase {
    private const DB = 'knowledge-seen-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        // Declared as seeds 45 and 53 declare it: a column the query names but no store has made
        // yet would read as no rows in fluid mode.
        Bean::exec('CREATE TABLE kbentry (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, symptom TEXT, cause TEXT, fix TEXT, status TEXT, source TEXT, source_note TEXT,
            hits INTEGER, last_hit_at TEXT, reviewed_at TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, seen INTEGER, seen_in TEXT, ruled_seen INTEGER)');
    }
    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private const LESSON = 'Ui::table throws when a call has no empty option, even for a list that is never empty';

    public function testARepeatIsCountedWithItsProject(): void {
        $id = KB::propose(self::LESSON, self::LESSON, '', self::LESSON, 'notebook', 'bakery — plan 12');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(0, KB::propose(self::LESSON, self::LESSON, '', self::LESSON, 'notebook', 'mileage — plan 3'), 'no second entry');
        KB::propose(self::LESSON, self::LESSON, '', self::LESSON, 'notebook', 'bakery — plan 14');
        $b = Bean::load('kbentry', $id);
        $this->assertSame(3, (int) $b->seen);
        $this->assertSame("bakery\nmileage", (string) $b->seenIn, 'each project once');
        $this->assertSame([$id], array_map(fn($r) => (int) $r->id, KB::ruleWorthy()));
        KB::markRuled($id);
        $this->assertSame([], KB::ruleWorthy(), 'made a rule: not named again');
        foreach (['a', 'b'] as $p) KB::propose(self::LESSON, self::LESSON, '', self::LESSON, 'notebook', "{$p} — plan 1");
        $this->assertSame([], KB::ruleWorthy(), 'twice more is not yet a rule that does not work');
        KB::propose(self::LESSON, self::LESSON, '', self::LESSON, 'notebook', 'c — plan 1');
        $this->assertSame([$id], array_map(fn($r) => (int) $r->id, KB::ruleWorthy()), 'three more times: the rule is not working');
    }

    public function testARepeatOfAMergedCandidateCountsOnTheEntryItWasMergedInto(): void {
        $published = KB::save(null, ['title' => 'Ui::table rules: empty is required', 'symptom' => 'A page 500s from Ui::table.', 'cause' => 'x', 'fix' => 'Give every call an empty.', 'status' => 'published']);
        $cand = KB::propose(self::LESSON, self::LESSON, '', self::LESSON, 'notebook', 'bakery — plan 12');
        $c = Bean::load('kbentry', $cand); $c->sourceNote .= " — merged into #{$published}"; $c->status = 'dismissed'; Bean::store($c);
        KB::propose(self::LESSON, self::LESSON, '', self::LESSON, 'notebook', 'mileage — plan 3');
        $this->assertSame(2, (int) Bean::load('kbentry', $published)->seen);
        $this->assertSame(1, (int) Bean::load('kbentry', $cand)->seen);
        $this->assertSame([], KB::ruleWorthy(), 'twice is not yet a rule');
    }
}
