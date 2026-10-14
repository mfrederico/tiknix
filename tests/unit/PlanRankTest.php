<?php
/**
 * app\PlanRank: a task's risk is the planner's judgement raised by what the platform can see;
 * a plan's tasks roll up to its total effort, highest risk, critical path, leverage and class —
 * checked against the warehouse-builder plan the design page scores (plan #18).
 */
namespace tests\unit;

use app\PlanRank;
use PHPUnit\Framework\TestCase;

class PlanRankTest extends TestCase {
    public function testRiskIsThePlannersJudgementRaisedByWhatThePlatformSees(): void {
        $why = PlanRank::checks(['files' => ['services/Schema/Seeds/52_Game.php', 'controls/Game.php'], 'adopts' => [], 'verify' => []],
                                [['title' => 'Shifts run the business', 'files' => ['controls/Game.php']]]);
        $this->assertCount(3, $why, 'same file as another task, a seed, no checks: ' . implode(' | ', $why));
        $r = PlanRank::planned(3, 2, 'money: a rule a person would notice', $why);
        $this->assertSame(['effort' => 3, 'planner' => 2, 'risk' => 5], array_intersect_key($r, ['effort' => 1, 'planner' => 1, 'risk' => 1]));
        $this->assertSame('money: a rule a person would notice', $r['why'][0]);
        $this->assertNull(PlanRank::planned(null, 'high', '', [])['risk'], 'a score it did not give is not invented');
        $this->assertSame(['risk' => 2, 'set_by' => 'person'], array_intersect_key(PlanRank::setByPerson($r, 2), ['risk' => 1, 'set_by' => 1]));
        $this->assertSame(2, PlanRank::setByPerson($r, 2)['planner'], 'what the planner said is kept to learn from');
    }

    public function testAPlanRollsUpToItsCriticalPathLeverageAndClass(): void {
        // warehouse-builder plan #18: t1..t8 as ids 1..8, effort as scored on the design page
        $t = fn($id, $deps, $e, $r) => ['id' => $id, 'deps' => $deps, 'effort' => $e, 'risk' => $r];
        $plan = [$t(1, [], 5, 4), $t(2, [], 4, 3), $t(3, [2], 3, 3), $t(4, [1], 3, 2), $t(5, [1, 2], 2, 3), $t(6, [1, 3, 4, 5], 4, 5), $t(7, [6], 3, 2), $t(8, [6, 7], 3, 2)];
        $r = PlanRank::rollup($plan);
        $this->assertSame(27, $r['total']);
        $this->assertSame(5, $r['max_risk']);
        $this->assertSame(2, $r['high']);
        $this->assertSame('large', $r['class']);
        $this->assertSame([5, 5, 3, 3, 3, 2, 1, 0], array_values($r['leverage']));
        $this->assertSame(18, $r['path_effort'], 't1 → t4 → t6 → t7 → t8 is 5+3+4+3+3');
        $this->assertSame([1, 4, 6, 7, 8], $r['path']);
    }

    public function testSmallAndUnscored(): void {
        $this->assertSame('small', PlanRank::rollup([['id' => 1, 'deps' => [], 'effort' => 2, 'risk' => 2]])['class']);
        $this->assertSame('medium', PlanRank::rollup([['id' => 1, 'deps' => [], 'effort' => 2, 'risk' => 3]])['class']);
        $this->assertSame('', PlanRank::rollup([['id' => 1, 'deps' => [], 'effort' => null, 'risk' => null]])['class'], 'a plan nobody scored has no class');
    }

    public function testOnlyATaskThatCouldRunBesideItCanCollideOnAFile(): void {
        $g = [['ref' => 't1', 'deps' => [], 'title' => 'data', 'files' => ['views/index/index.php']],
              ['ref' => 't2', 'deps' => ['t1'], 'title' => 'design', 'files' => ['views/index/index.php']],
              ['ref' => 't3', 'deps' => [], 'title' => 'other', 'files' => ['views/index/index.php']]];
        $this->assertSame(['t3'], array_column(PlanRank::concurrent('t1', $g), 'ref'), 't2 waits on t1: it cannot collide with it');
        $this->assertSame(['t3'], array_column(PlanRank::concurrent('t2', $g), 'ref'));
        $this->assertSame([], PlanRank::checks(['files' => ['views/index/index.php'], 'verify' => ['x']], PlanRank::concurrent('t2', [$g[0], $g[1]])));
    }
}
