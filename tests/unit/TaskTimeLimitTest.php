<?php
/**
 * A task that ran out of time is recognised in both wordings (an app on an older runtime says
 * "exited 124") and its retry gets the longer limit — never more than the maximum.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\PlanExecutor;

class TaskTimeLimitTest extends TestCase {
    public function testBothWordingsAreATimeout(): void {
        $this->assertTrue(PlanExecutor::ranOutOfTime('the agent exited 124'));
        $this->assertTrue(PlanExecutor::ranOutOfTime('the agent ran out of time: it was still working when its 30-minute limit ended, and its unfinished work was not kept'));
        $this->assertFalse(PlanExecutor::ranOutOfTime('the agent exited 1'));
        $this->assertFalse(PlanExecutor::ranOutOfTime('the agent exited 1240'));
    }
    public function testTheLimitIsThirtyMinutesUnlessARetryRaisedIt(): void {
        $this->assertSame(1800, PlanExecutor::timeLimit((object) []));
        $this->assertSame(3600, PlanExecutor::timeLimit((object) ['timeLimit' => 3600]));
        $this->assertSame(3600, PlanExecutor::timeLimit((object) ['timeLimit' => 99999]), 'never past the maximum');
        $this->assertSame(1800, PlanExecutor::timeLimit((object) ['timeLimit' => 60]), 'never under the standard limit');
    }
}
