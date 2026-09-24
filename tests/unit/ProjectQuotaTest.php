<?php
/**
 * lib/ProjectQuota — a granted free project is honoured everywhere: at the create gate,
 * on the invoice, and in what the billing service is told.
 *
 *   free     cap = the free allowance; raising member.free_projects raises it. A stale
 *            plan_project_cap (every signup used to stamp 1) must not win — that is the
 *            bug where a grant changed the invoice but the gate still said 1.
 *   legacy   cap = the grandfathered cap, or the grant if higher; never billed
 *   pro      uncapped; billed past the allowance; complimentary = the allowance
 *   grant    setFreeProjects writes the number and an audit row, and nothing when unchanged
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ProjectQuota;

class ProjectQuotaTest extends ConceptsTestCase {

    private const DB = 'quota-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        // countFor joins these; they exist (empty) on every real install.
        Bean::exec('CREATE TABLE team (id INTEGER PRIMARY KEY, owner_id INTEGER)');
        Bean::exec('CREATE TABLE teammember (id INTEGER PRIMARY KEY, team_id INTEGER, member_id INTEGER)');
        Bean::exec('CREATE TABLE instance_team (id INTEGER PRIMARY KEY, instance_id INTEGER, team_id INTEGER)');
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function member(string $tier, int $cap = 0, int $free = 0): int {
        $m = Bean::dispense('member');
        $m->email = uniqid() . '@x.test'; $m->level = 100; $m->status = 'active';
        $m->planTier = $tier; $m->planProjectCap = $cap; $m->freeProjects = $free;
        return (int) Bean::store($m);
    }

    private function projects(int $memberId, int $n): void {
        for ($i = 0; $i < $n; $i++) {
            $p = Bean::dispense('instance');
            $p->slug = "p{$memberId}-{$i}"; $p->memberId = $memberId; $p->status = 'active';
            Bean::store($p);
        }
    }

    public function testAGrantRaisesAFreeMembersCapEvenWithAStaleStampedCap(): void {
        $m = $this->member('free', 1, 3);   // signed up (cap stamped 1), then granted 3
        $this->assertSame(3, ProjectQuota::capFor($m), 'the grant decides, not the signup stamp');
        $this->projects($m, 2);
        $this->assertSame(0, ProjectQuota::billableProjects($m));
        $this->assertSame(2, ProjectQuota::complimentaryProjects($m));
        $this->assertFalse(ProjectQuota::snapshot($m)['over']);
    }

    public function testFreeWithoutAGrantIsTheDefaultOne(): void {
        $m = $this->member('free');
        $this->assertSame(ProjectQuota::FREE_CAP, ProjectQuota::capFor($m));
    }

    public function testLegacyKeepsItsCapUnlessTheGrantIsHigher(): void {
        $this->assertSame(5, ProjectQuota::capFor($this->member('legacy', 5)));
        $this->assertSame(8, ProjectQuota::capFor($this->member('legacy', 5, 8)));
        $l = $this->member('legacy', 3);
        $this->projects($l, 3);
        $this->assertSame([0, 3], [ProjectQuota::billableProjects($l), ProjectQuota::complimentaryProjects($l)], 'legacy is never billed');
    }

    public function testProIsUncappedAndBilledPastTheAllowance(): void {
        $p = $this->member('pro', 0, 2);
        $this->projects($p, 5);
        $this->assertSame(ProjectQuota::PRO_CAP, ProjectQuota::capFor($p));
        $this->assertSame([3, 2], [ProjectQuota::billableProjects($p), ProjectQuota::complimentaryProjects($p)]);
    }

    public function testAGrantIsAuditedAndANoOpIsNot(): void {
        $admin = $this->member('legacy', 10);
        $m = $this->member('free');
        $bean = Bean::load('member', $m);
        $this->assertTrue($bean->box()->setFreeProjects(4, $admin, 'beta tester'));
        $this->assertFalse($bean->box()->setFreeProjects(4, $admin, 'again'), 'unchanged = no row');
        $rows = $bean->box()->audits();
        $this->assertCount(1, $rows);
        $r = reset($rows);
        $this->assertSame(['0', '4', 'beta tester', $admin], [(string) $r->oldValue, (string) $r->newValue, (string) $r->note, (int) $r->byRef]);
        $this->assertSame(4, ProjectQuota::capFor($m));
    }
}
