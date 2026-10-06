<?php
/**
 * A build task that hands in a split (app\PlanExecutor::applySplit) is replaced in ITS plan by
 * the pieces: they start after it, run on its agent one level deeper, and whatever waited on
 * the task waits on the last pieces. A plan a person approves step by step is held for them;
 * a split past the limits is refused and nothing is added.
 */
namespace tests\unit;

use app\Bean;
use app\PlanExecutor;

class TaskSplitTest extends ConceptsTestCase {
    private PlanExecutor $ex;
    private int $plan;

    private const DB = 'task-split-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function task(array $o): int {
        $t = Bean::dispense('workbenchtask');
        foreach ($o + ['taskType' => 'feature', 'instanceId' => 9, 'instanceTag' => 'zz.tiknix', 'engine' => 'claude', 'model' => '', 'agent' => 'second',
                       'memberId' => 3, 'authcontrolLevel' => 100, 'baseBranch' => 'main', 'dbSource' => 'live', 'status' => 'pending', 'dependsOn' => '[]',
                       'splitOf' => 0, 'splitDepth' => 0, 'createdAt' => date('Y-m-d H:i:s')] as $k => $v) $t->{$k} = $v;
        return (int) Bean::store($t);
    }

    private function make(bool $auto): void {
        $this->plan = $this->task(['title' => 'Phase 1', 'planStatus' => 'building', 'autoBuild' => $auto ? 1 : 0, 'splitHold' => 0]);
        $ex = (new \ReflectionClass(PlanExecutor::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PlanExecutor::class, 'planId'))->setValue($ex, $this->plan);
        $this->ex = $ex;
    }

    private function split($task, $split): array {
        $m = new \ReflectionMethod(PlanExecutor::class, 'applySplit');
        return $m->invoke($this->ex, Bean::load('workbenchtask', $task), $split);
    }

    private const SPLIT = ['title' => 'x', 'subtasks' => [
        ['id' => 'a', 'title' => 'Model and seed', 'description' => 'the data', 'files' => ['models/Model_X.php'], 'verify' => ['the table exists']],
        ['id' => 'b', 'title' => 'Pages', 'depends_on' => ['a']]]];

    public function testThePiecesTakeTheTasksPlaceAndItsDependentsWaitForTheLastOne(): void {
        $this->make(true);
        $big   = $this->task(['title' => 'Everything about X', 'parentTaskId' => $this->plan, 'status' => 'merged']);
        $after = $this->task(['title' => 'Design: X', 'parentTaskId' => $this->plan, 'dependsOn' => json_encode([$big])]);
        $done  = $this->task(['title' => 'Earlier', 'parentTaskId' => $this->plan, 'status' => 'merged', 'dependsOn' => json_encode([$big])]);

        $ids = $this->split($big, self::SPLIT);
        $this->assertCount(2, $ids);
        [$a, $b] = array_map(fn($id) => Bean::load('workbenchtask', $id), $ids);
        $this->assertSame('Model and seed', (string) $a->title);
        $this->assertSame([$this->plan, 'pending', 'second', $big, 1], [(int) $a->parentTaskId, (string) $a->status, (string) $a->agent, (int) $a->splitOf, (int) $a->splitDepth]);
        $this->assertSame([$big], json_decode((string) $a->dependsOn, true), 'a piece starts after the task that split, and is handed what it left');
        $this->assertSame([$big, (int) $a->id], json_decode((string) $b->dependsOn, true));
        $this->assertSame(['the table exists'], json_decode((string) $a->acceptanceCriteria, true));
        $this->assertSame([$big, (int) $b->id], json_decode((string) Bean::load('workbenchtask', $after)->dependsOn, true), 'what waited on the task waits on the last piece');
        $this->assertSame([$big], json_decode((string) Bean::load('workbenchtask', $done)->dependsOn, true), 'a task that already finished is left alone');
        $this->assertFalse($this->ex->heldForSplit(), 'a plan built straight through goes on');
    }

    public function testAPlanAPersonApprovesIsHeldUntilTheyPressBuild(): void {
        $this->make(false);
        $big = $this->task(['title' => 'Everything about X', 'parentTaskId' => $this->plan, 'status' => 'merged']);
        $this->assertCount(2, $this->split($big, self::SPLIT));
        $this->assertTrue($this->ex->heldForSplit());
        $this->ex->clearSplitHold();
        $this->assertFalse($this->ex->heldForSplit());
    }

    public function testASplitPastTheLimitsOrMalformedAddsNothing(): void {
        $this->make(true);
        $deep = $this->task(['title' => 'Already deep', 'parentTaskId' => $this->plan, 'status' => 'merged', 'splitDepth' => PlanExecutor::MAX_SPLIT_DEPTH]);
        $before = (int) Bean::count('workbenchtask');
        $this->assertSame([], $this->split($deep, self::SPLIT));
        $this->assertSame([], $this->split($deep, ['subtasks' => [['id' => 'a', 'title' => 'one']]]));
        $ok = $this->task(['title' => 'Fine', 'parentTaskId' => $this->plan, 'status' => 'merged']);
        $this->assertSame([], $this->split($ok, null), 'no split handed in: nothing happens');
        $this->assertSame($before + 1, (int) Bean::count('workbenchtask'));
        $this->assertSame(2, (int) Bean::count('tasklog', 'task_id = ? AND message LIKE ?', [$deep, '%NOT split: it is already%']), 'each refusal is said on the task');

        for ($i = 0; $i < PlanExecutor::MAX_SPLITS_PER_PLAN; $i++) {
            $t = $this->task(['title' => "T{$i}", 'parentTaskId' => $this->plan, 'status' => 'merged']);
            $this->assertCount(2, $this->split($t, self::SPLIT), "split {$i}");
        }
        $more = $this->task(['title' => 'One too many', 'parentTaskId' => $this->plan, 'status' => 'merged']);
        $this->assertSame([], $this->split($more, self::SPLIT), 'a plan whose tasks keep splitting needs a person');
    }
}
