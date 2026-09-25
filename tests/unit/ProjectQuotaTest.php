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

    private function projects(int $memberId, int $n, string $kind = 'project'): void {
        for ($i = 0; $i < $n; $i++) {
            $p = Bean::dispense('instance');
            $p->slug = "p{$memberId}-{$kind}-{$i}"; $p->memberId = $memberId; $p->status = 'active';
            $p->plan = $kind;
            $p->createdAt = sprintf('2026-01-%02d 00:00:00', min(28, 1 + Bean::count('instance')));
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
    /* ---- the four published tiers (pricing page, 2026-09-25) ---- */

    public function testTheOldestProjectIsTheFreeOneWhateverItsKind(): void {
        $m = $this->member('pro');
        $this->projects($m, 1, 'client');   // first made: a client project
        $this->projects($m, 2, 'project');
        $b = ProjectQuota::breakdown($m);
        $this->assertSame(1, $b['complimentary']);
        $this->assertSame(['project' => 2, 'client' => 1], $b['kinds']);
        $this->assertSame(2, $b['billable_projects'], 'both plain projects are past the allowance');
        $this->assertSame(0, $b['billable_client_projects'], 'the client project was the free one');
        $this->assertSame(2 * ProjectQuota::PRICE_PER_PROJECT, $b['monthly']);
        $this->assertCount(1, $b['free_ids']);
    }

    public function testClientProjectsBillAtTheClientRate(): void {
        $m = $this->member('pro');
        $this->projects($m, 1, 'project');  // free
        $this->projects($m, 1, 'project');  // $49
        $this->projects($m, 2, 'client');   // 2 × $99
        $b = ProjectQuota::breakdown($m);
        $this->assertSame([1, 2], [$b['billable_projects'], $b['billable_client_projects']]);
        $this->assertSame(ProjectQuota::PRICE_PER_PROJECT + 2 * ProjectQuota::PRICE_PER_CLIENT_PROJECT, $b['monthly']);
        $this->assertSame(0, $b['agency_plan']);
        $this->assertSame(2, ProjectQuota::billableClientProjects($m));
    }

    public function testAgencyPoolsTenThenChargesExtras(): void {
        $m = $this->member('agency');
        $this->projects($m, 1, 'project');   // free
        $this->projects($m, 8, 'client');    // pooled
        $this->projects($m, 4, 'project');   // 2 more pooled, 2 extra
        $b = ProjectQuota::breakdown($m);
        $this->assertSame(1, $b['agency_plan']);
        $this->assertSame(ProjectQuota::AGENCY_POOL, $b['agency_pooled']);
        $this->assertSame(2, $b['agency_extra']);
        $this->assertSame([0, 0], [$b['billable_projects'], $b['billable_client_projects']], 'nothing billed twice under Agency');
        $this->assertSame(ProjectQuota::PRICE_AGENCY + 2 * ProjectQuota::PRICE_AGENCY_EXTRA, $b['monthly']);
        $this->assertSame(ProjectQuota::PRO_CAP, ProjectQuota::capFor($m), 'agency is uncapped');
        $this->assertFalse(ProjectQuota::isOverCap($m));
    }

    public function testAgencyUnderThePoolPaysTheFlatFeeOnly(): void {
        $m = $this->member('agency');
        $this->projects($m, 4, 'client');
        $b = ProjectQuota::breakdown($m);
        $this->assertSame([1, 3, 0], [$b['agency_plan'], $b['agency_pooled'], $b['agency_extra']]);
        $this->assertSame(ProjectQuota::PRICE_AGENCY, $b['monthly']);
    }

    public function testLegacyIsNeverBilledWhateverTheKinds(): void {
        $l = $this->member('legacy', 3);
        $this->projects($l, 2, 'client');
        $b = ProjectQuota::breakdown($l);
        $this->assertSame([2, 0, 0, 0], [$b['complimentary'], $b['billable_projects'], $b['billable_client_projects'], $b['agency_plan']]);
        $this->assertSame(0.0, $b['monthly']);
    }

    public function testKindNormalisesToProject(): void {
        $this->assertSame('project', ProjectQuota::kindOf(null));
        $this->assertSame('project', ProjectQuota::kindOf('enterprise'));
        $this->assertSame('client', ProjectQuota::kindOf(' Client '));
    }

    public function testSnapshotCarriesTheBreakdown(): void {
        $m = $this->member('pro');
        $this->projects($m, 2, 'client');
        $s = ProjectQuota::snapshot($m);
        $this->assertSame(1, $s['billable_client']);
        $this->assertSame(['project' => 0, 'client' => 2], $s['kinds']);
        $this->assertSame(ProjectQuota::PRICE_PER_CLIENT_PROJECT, $s['monthly']);
    }

    public function testCustomDomainIsAPaidPerk(): void {
        \Flight::set('billing.enforce_project_cap', true);
        try {
            $this->assertFalse(ProjectQuota::canUseCustomDomain($this->member('free')));
            $this->assertTrue(ProjectQuota::canUseCustomDomain($this->member('pro')));
            $this->assertTrue(ProjectQuota::canUseCustomDomain($this->member('agency')));
            $this->assertTrue(ProjectQuota::canUseCustomDomain($this->member('legacy', 5)));
        } finally {
            \Flight::set('billing.enforce_project_cap', false);
        }
        $this->assertTrue(ProjectQuota::canUseCustomDomain($this->member('free')), 'enforcement off: everyone may');
    }
}
