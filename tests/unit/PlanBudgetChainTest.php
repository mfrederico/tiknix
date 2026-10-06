<?php
/**
 * A plan's time budget covers its longest dependency chain, each task at its full limit
 * (PlanExecutor::chainTicks). Counting only how WIDE the work runs gave holistica's plan 1.9 hours
 * for four tasks that must run one after the other at up to an hour each.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\PlanExecutor;

class PlanBudgetChainTest extends TestCase {
    private const HOUR = 366;   // 3600 s + a minute to merge, in 10 s ticks

    public function testAChainAddsUpAndIndependentTasksDoNot(): void {
        // holistica plan 1 as it stood: 5 → 6 → 11 → 12, with 2, 4, 7, 9, 10 already merged
        $chain = [5 => ['deps' => [4], 'seconds' => 3600], 6 => ['deps' => [2, 4, 5], 'seconds' => 3600],
                  11 => ['deps' => [2, 6, 7, 10], 'seconds' => 3600], 12 => ['deps' => [2, 3, 4, 5, 6, 7, 8, 9, 10, 11], 'seconds' => 3600]];
        $this->assertSame(4 * self::HOUR, PlanExecutor::chainTicks($chain));
        $wide = [1 => ['deps' => [], 'seconds' => 3600], 2 => ['deps' => [], 'seconds' => 3600], 3 => ['deps' => [], 'seconds' => 1800]];
        $this->assertSame(self::HOUR, PlanExecutor::chainTicks($wide), 'three tasks side by side cost the longest one');
    }

    public function testTheLongestBranchWinsAndEachTasksOwnLimitCounts(): void {
        $t = [1 => ['deps' => [], 'seconds' => 1800], 2 => ['deps' => [1], 'seconds' => 5400], 3 => ['deps' => [1], 'seconds' => 1800], 4 => ['deps' => [2, 3], 'seconds' => 1800]];
        $this->assertSame(186 + 546 + 186, PlanExecutor::chainTicks($t), '1 → 2 (a 90-minute agent) → 4');
        $this->assertSame(0, PlanExecutor::chainTicks([]));
    }

    public function testACycleDoesNotHang(): void {
        $this->assertGreaterThan(0, PlanExecutor::chainTicks([1 => ['deps' => [2], 'seconds' => 60], 2 => ['deps' => [1], 'seconds' => 60]]));
    }

    /* ---- tasks at once, per agent ---- */

    public function testAnAgentRunsOnlyAsManyTasksAsItSaidAndAnotherAgentIsNotHeldByIt(): void {
        $report = ['default_agent' => 'zai', 'parallel' => ['zai' => 1, 'wide' => 3]];
        $this->assertSame(1, PlanExecutor::capOf($report, 'zai'));
        $this->assertSame(0, PlanExecutor::capOf($report, 'anthropic'), 'an agent that set none has no cap of its own');
        $this->assertSame(0, PlanExecutor::capOf(null, 'zai'), 'an app that has not reported');
        $this->assertFalse(PlanExecutor::agentHasRoom(1, PlanExecutor::capOf($report, 'zai')), 'zai is busy with its one task');
        $this->assertTrue(PlanExecutor::agentHasRoom(0, PlanExecutor::capOf($report, 'zai')));
        $this->assertTrue(PlanExecutor::agentHasRoom(2, PlanExecutor::capOf($report, 'anthropic')), 'a task on the second agent starts beside it');
        $this->assertTrue(PlanExecutor::agentHasRoom(2, PlanExecutor::capOf($report, 'wide')));
        $this->assertFalse(PlanExecutor::agentHasRoom(3, PlanExecutor::capOf($report, 'wide')));
    }

    /* ---- a task that splits itself ---- */

    public function testASplitIsCheckedAndItsLastPiecesFound(): void {
        $sp = PlanExecutor::splitPieces(['title' => 'x', 'subtasks' => [
            ['id' => 'a', 'title' => 'Model and seed', 'description' => 'd', 'files' => ['models/Model_X.php'], 'verify' => ['the table exists']],
            ['id' => 'b', 'title' => 'List page', 'depends_on' => ['a', 't9-from-the-plan']],
            ['id' => 'c', 'title' => 'Design: the list', 'depends_on' => ['b', 'c'], 'priority' => 9]]]);
        $this->assertSame([], $sp['problems']);
        $this->assertSame(['a', 'b', 'c'], array_column($sp['pieces'], 'ref'));
        $this->assertSame([[], ['a'], ['b']], array_column($sp['pieces'], 'deps'), 'a piece waits on other pieces only, never on itself');
        $this->assertSame(['c'], $sp['leaves'], 'what waited on the task now waits on the last piece');
        $this->assertSame(4, $sp['pieces'][2]['priority']);
        $this->assertSame(['the table exists'], $sp['pieces'][0]['verify']);

        $side = PlanExecutor::splitPieces(['subtasks' => [['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B']]]);
        $this->assertSame(['a', 'b'], $side['leaves'], 'two pieces side by side are both last');
    }

    public function testASplitThatIsNotOneIsRefusedWithTheReason(): void {
        $p = fn($split) => implode(' | ', PlanExecutor::splitPieces($split)['problems']);
        $this->assertStringContainsString('2 to 6 pieces, not 1', $p(['subtasks' => [['id' => 'a', 'title' => 'only']]]));
        $this->assertStringContainsString('2 to 6 pieces, not 7', $p(['subtasks' => array_map(fn($i) => ['id' => "p{$i}", 'title' => "P{$i}"], range(1, 7))]));
        $this->assertStringContainsString('2 to 6 pieces, not 0', $p(null));
        $this->assertStringContainsString("no title", $p(['subtasks' => [['id' => 'a', 'title' => ''], ['id' => 'b', 'title' => 'B']]]));
        $this->assertStringContainsString("both called 'a'", $p(['subtasks' => [['id' => 'a', 'title' => 'A'], ['id' => 'a', 'title' => 'B']]]));
        $this->assertStringContainsString('in a circle', $p(['subtasks' => [['id' => 'a', 'title' => 'A', 'depends_on' => ['b']], ['id' => 'b', 'title' => 'B', 'depends_on' => ['a']]]]));
        $this->assertSame(2, PlanExecutor::MAX_SPLIT_DEPTH);
    }
}
