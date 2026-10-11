<?php
/**
 * app\PlanIngestor::direct — a request built without a planner: one approved, straight-through
 * plan whose single task is the request, marked so its agent judges its size (and is told most
 * such requests are ONE piece of work, the opposite of a task a planner marked).
 */
namespace tests\unit;

use app\Bean;
use app\PlanExecutor;
use app\PlanIngestor;

require_once __DIR__ . '/ConceptsTestCase.php';

class DirectPlanTest extends ConceptsTestCase {
    private const DB = 'direct-plan-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    public function testARequestBecomesOneApprovedPlanOfOneUnplannedTask(): void {
        $inst = (object) ['id' => 9, 'slug' => 'zz', 'engine' => 'claude'];
        $id = PlanIngestor::direct($inst, 'Add a phone field to the profile', "Add a phone field to the profile.\nMembers only.", 3);
        $plan = Bean::load('workbenchtask', $id);
        $this->assertSame(['approved', 1, 'ask'], [(string) $plan->planStatus, (int) $plan->autoBuild, (string) $plan->source]);
        $this->assertStringContainsString('Members only.', (string) $plan->planGoal);
        $tasks = array_values(Bean::find('workbenchtask', 'parent_task_id = ?', [$id]));
        $this->assertCount(1, $tasks);
        $this->assertSame(1, (int) $tasks[0]->needsPlanning);
        $this->assertSame(PlanExecutor::UNPLANNED, (string) $tasks[0]->planningNote);
        $this->assertStringContainsString('Members only.', (string) $tasks[0]->description, 'the agent is handed the request itself');
        $this->assertSame(3, (int) $tasks[0]->memberId);
    }

    public function testARequestWithoutWordsIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        PlanIngestor::direct((object) ['id' => 9, 'slug' => 'zz', 'engine' => 'claude'], '  ', '', 3);
    }

    public function testAnUnplannedTaskIsToldToBuildItUnlessItIsReallySeveral(): void {
        $md = PlanExecutor::severalSection(true, PlanExecutor::UNPLANNED);
        $this->assertStringContainsString('Nobody planned this', $md);
        $this->assertStringContainsString('Do not split it to be safe', $md);
        $this->assertStringContainsString('submit_plan', $md, 'it may still split a request that is really several');
        $this->assertStringNotContainsString('The planner marked this', $md);
        $this->assertStringContainsString('The planner marked this', PlanExecutor::severalSection(true, 'needs a look'), 'a task a planner marked is told what it was before');
        $this->assertSame('', PlanExecutor::severalSection(true, PlanExecutor::UNPLANNED, PlanExecutor::MAX_SPLIT_DEPTH));
    }
}
