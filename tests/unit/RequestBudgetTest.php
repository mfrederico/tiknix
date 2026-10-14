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

    public function testOverBudgetOnlyWhenThereIsOneAndItIsUsed(): void {
        $this->assertFalse(PlanExecutor::overBudget(99000000, 0));
        $this->assertFalse(PlanExecutor::overBudget(2999999, 3000000));
        $this->assertTrue(PlanExecutor::overBudget(3000000, 3000000));
    }
}
