<?php
/**
 * app\PlanLearn (Plan and Fan, step four): a project's estimates against what its tasks really
 * cost — medians per effort, trouble per risk, cost per model — said to the planner, and the
 * least time a plan can take here.
 */
namespace tests\unit;

use app\PlanLearn;
use app\RunStats;
use PHPUnit\Framework\TestCase;

class PlanLearnTest extends TestCase {
    private function row(int $id, string $status, int $effort, int $risk, int $secs, int $tokens, int $attempts = 1, string $model = '', string $review = ''): array {
        return ['id' => $id, 'status' => $status, 'rank' => ['effort' => $effort, 'risk' => $risk],
                'stats' => ['seconds' => $secs, 'input_tokens' => $tokens, 'output_tokens' => 0, 'attempts' => $attempts] + ($model !== '' ? ['model' => $model] : []),
                'review' => $review !== '' ? ['state' => $review] : []];
    }

    public function testMediansPerEffortTroublePerRiskAndCostPerModel(): void {
        $l = PlanLearn::learn([
            $this->row(1, 'merged', 2, 1, 60, 500000, 1, 'claude-sonnet'),
            $this->row(2, 'merged', 2, 2, 40, 300000, 1, 'claude-sonnet'),
            $this->row(3, 'merged', 2, 2, 90, 900000, 1, 'claude-haiku'),
            $this->row(4, 'failed', 4, 4, 300, 2000000),
            $this->row(5, 'merged', 4, 4, 100, 800000, 2),
            $this->row(6, 'merged', 3, 4, 120, 900000, 1, '', 'found'),
        ]);
        $this->assertSame(6, $l['tasks']);
        $this->assertSame(['n' => 3, 'seconds' => 60, 'tokens' => 500000], $l['effort'][2]);
        $this->assertSame(200, $l['effort'][4]['seconds'], 'the median of two is their middle');
        $this->assertSame(['n' => 3, 'trouble' => 3], $l['risk'][4], 'failed, retried, review found');
        $this->assertSame(['n' => 2, 'trouble' => 0], $l['risk'][2]);
        $this->assertSame(2, $l['model']['claude-sonnet']['n']);
        $this->assertSame(90, $l['model']['claude-haiku']['seconds']);
        $s = PlanLearn::section($l);
        $this->assertStringContainsString('- effort 2: 3 tasks, a median of 1m 0s and 500k tokens', $s);
        $this->assertStringContainsString('High: 3 of 3', $s);
        $this->assertStringContainsString('claude-haiku 1m 30s', $s);
    }

    public function testNothingIsSaidOrEstimatedFromTooLittle(): void {
        $l = PlanLearn::learn([$this->row(1, 'merged', 2, 1, 60, 1)]);
        $this->assertSame('', PlanLearn::section($l));
        $this->assertNull(PlanLearn::floor([1], [['id' => 1, 'effort' => 2]], $l));
    }

    public function testTheFloorIsTheCriticalPathAtThisProjectsMedians(): void {
        $l = PlanLearn::learn([$this->row(1, 'merged', 2, 1, 60, 1), $this->row(2, 'merged', 2, 1, 60, 1), $this->row(3, 'merged', 4, 1, 200, 1)]);
        $tasks = [['id' => 10, 'effort' => 4], ['id' => 11, 'effort' => 2], ['id' => 12, 'effort' => 5]];
        $this->assertSame(260, PlanLearn::floor([10, 11], $tasks, $l));
        $this->assertNull(PlanLearn::floor([10, 12], $tasks, $l), 'an effort never built here is not guessed');
    }

    public function testARunsModelIsKeptOnTheTask(): void {
        $t = RunStats::add(RunStats::add([], ['seconds' => 10, 'model' => 'glm-5']), ['seconds' => 5, 'model' => 'claude-sonnet']);
        $this->assertSame('claude-sonnet', $t['model']);
        $this->assertSame(['glm-5' => 1, 'claude-sonnet' => 1], $t['models']);
        $this->assertStringEndsWith('· on claude-sonnet', RunStats::line(RunStats::add([], ['seconds' => 5, 'model' => 'claude-sonnet'])));
        $this->assertArrayNotHasKey('model', RunStats::add([], ['seconds' => 5]), 'an older runtime does not say: nothing is invented');
    }
}
