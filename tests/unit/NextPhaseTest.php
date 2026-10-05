<?php
/**
 * Which plan "build the next phase" builds (app\PlanPhases, used by the Builder's board): the oldest one
 * planned and not built — never one already superseded, and nothing while a phase is building.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;

class NextPhaseTest extends TestCase {
    private function ph(int $id, string $status, int $replanOf = 0, bool $superseded = false): array {
        return ['id' => $id, 'title' => "plan {$id}", 'plan_status' => $status, 'total' => 3, 'built' => 0, 'replan_of' => $replanOf, 'superseded' => $superseded, 'phase' => $replanOf ? 0 : $id];
    }
    public function testTheOldestPlannedPhaseIsNext(): void {
        $this->assertSame(2, \app\PlanPhases::next([$this->ph(1, 'done'), $this->ph(2, 'draft'), $this->ph(3, 'draft')])['id']);
        $this->assertSame(2, \app\PlanPhases::next([$this->ph(1, 'done'), $this->ph(2, 'stalled'), $this->ph(3, 'approved')])['id'], 'a stalled phase is resumed before a later one starts');
    }
    public function testASupersededRePlanIsNeverNext(): void {
        // holistica: phase 1 finished; its two automatic re-plans are work already done
        $this->assertNull(\app\PlanPhases::next([$this->ph(1, 'done'), $this->ph(13, 'draft', 1, true), $this->ph(30, 'draft', 1, true)]));
        $this->assertSame(1, \app\PlanPhases::next([$this->ph(1, 'stalled'), $this->ph(13, 'draft', 1)])['id'], 'while the original is stuck it is resumed first; its re-plan stays available');
        $this->assertSame(13, \app\PlanPhases::next([$this->ph(1, 'failed'), $this->ph(13, 'draft', 1)])['id'], 'an original that cannot be resumed leaves its re-plan as the way forward');
    }
    public function testNothingIsNextWhileAPhaseBuildsOrWhenAllAreBuilt(): void {
        $this->assertNull(\app\PlanPhases::next([$this->ph(1, 'building'), $this->ph(2, 'draft')]));
        $this->assertNull(\app\PlanPhases::next([$this->ph(1, 'done'), $this->ph(2, 'done')]));
        $this->assertNull(\app\PlanPhases::next([]));
    }
}
