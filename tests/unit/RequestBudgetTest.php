<?php
/**
 * Plan and Fan, step five: an agent's "Spend per request" reaches the Builder in the app's report,
 * and a request that has used it starts nothing more on that agent.
 */
namespace tests\unit;

use app\PlanExecutor;
use PHPUnit\Framework\TestCase;

class RequestBudgetTest extends TestCase {
    public function testTheBudgetTravelsInTheReportHeadline(): void {
        $h = \Model_Projectreport::headline(['readiness' => ['providers' => [
            ['name' => 'claude', 'preset' => 'anthropic', 'type' => 'build', 'ready' => true, 'budget' => 10000000],
            ['name' => 'glm', 'preset' => 'zai', 'type' => 'build', 'ready' => true, 'budget' => 0],
        ]]]);
        $this->assertSame(['claude' => 10000000], $h['budget'], 'no limit is not listed');
        $this->assertSame(10000000, PlanExecutor::budgetOf($h, 'claude'));
        $this->assertSame(0, PlanExecutor::budgetOf($h, 'glm'));
        $this->assertSame(0, PlanExecutor::budgetOf(null, 'claude'), 'an app that has not reported has set no limit');
    }

    public function testNearIsMostOfTheBudgetButNotAllOfIt(): void {
        $this->assertFalse(PlanExecutor::nearBudget(2399999, 3000000));
        $this->assertTrue(PlanExecutor::nearBudget(2400000, 3000000));
        $this->assertFalse(PlanExecutor::nearBudget(3000000, 3000000), 'used: that is a pause, not a warning');
        $this->assertFalse(PlanExecutor::nearBudget(9000000, 0));
    }

    public function testTheEstimateAddsWhatTasksOfEachEffortHaveUsedHere(): void {
        $row = fn($e, $tok) => ['id' => 1, 'status' => 'merged', 'rank' => ['effort' => $e, 'risk' => 1], 'stats' => ['seconds' => 60, 'input_tokens' => $tok, 'attempts' => 1], 'review' => []];
        $l = \app\PlanLearn::learn([$row(2, 600000), $row(2, 600000), $row(4, 900000)]);
        $this->assertSame(['tokens' => 2100000, 'unknown' => 1], \app\PlanLearn::estimate([4, 2, 2, 5], $l), 'an effort never built here adds nothing, and is counted as unknown');
        $this->assertNull(\app\PlanLearn::estimate([2], \app\PlanLearn::learn([])), 'no record, no estimate');
    }

    public function testTheOwnerNoticeRefusesAKindItDoesNotKnow(): void {
        $this->expectException(\InvalidArgumentException::class);
        \app\PlanNotifier::planBudget(__FILE__, ['kind' => 'other', 'plan_id' => 1, 'member_id' => 1]);
    }

    public function testOverBudgetOnlyWhenThereIsOneAndItIsUsed(): void {
        $this->assertFalse(PlanExecutor::overBudget(99000000, 0));
        $this->assertFalse(PlanExecutor::overBudget(2999999, 3000000));
        $this->assertTrue(PlanExecutor::overBudget(3000000, 3000000));
    }
}
