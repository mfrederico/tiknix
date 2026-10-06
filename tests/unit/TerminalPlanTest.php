<?php
/**
 * A plan handed in from a project's terminal (app\TerminalPlan) is refused when it is not a
 * plan or has nowhere to go — with the reason — and is never attributed by what its body claims.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\TerminalPlan;

class TerminalPlanTest extends TestCase {
    private function inst(array $o = []): object { return (object) ($o + ['id' => 9001, 'slug' => 'zz-no-such-project', 'memberId' => 1, 'app' => 'tiknix', 'engine' => 'claude']); }

    public function testSomethingThatIsNotAPlanIsRefused(): void {
        foreach ([[], ['title' => 'x'], ['title' => '', 'subtasks' => [['id' => 't1', 'title' => 'a']]], ['title' => 'x', 'subtasks' => []]] as $bad) {
            try { TerminalPlan::receive($this->inst(), $bad); $this->fail('accepted ' . json_encode($bad)); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Not a plan', $e->getMessage()); }
        }
    }

    public function testAProjectWithNoOwnerSaysSo(): void {
        $plan = ['title' => 'Public pages', 'subtasks' => [['id' => 't1', 'title' => 'Landing page']]];
        try { TerminalPlan::receive($this->inst(['memberId' => 0]), $plan); $this->fail('no owner accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('no owner', $e->getMessage()); }
    }
}
