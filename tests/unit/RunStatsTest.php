<?php
/**
 * RunStats: a run's figures added to a task's total, a plan's tasks summed, and the line that
 * says them. And the app's own counting of a session transcript (AgentTask::runStats).
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\RunStats;
use app\AgentTask;

class RunStatsTest extends TestCase {
    public function testRunsAddUpAndAttemptsAreCounted(): void {
        $t = RunStats::add([], ['seconds' => 1800, 'tool_calls' => 90, 'tool_errors' => 2, 'turns' => 30, 'timed_out' => true, 'resumed' => false]);
        $t = RunStats::add($t, ['seconds' => 1632, 'tool_calls' => 53, 'tool_errors' => 1, 'turns' => 11, 'input_tokens' => 1200000, 'output_tokens' => 38000, 'timed_out' => false, 'resumed' => true]);
        $this->assertSame([3432, 143, 3, 2, 1, 1], [$t['seconds'], $t['tool_calls'], $t['tool_errors'], $t['attempts'], $t['timed_out'], $t['resumed']]);
        $this->assertSame('57m 12s · 143 tool calls (3 failed) · 41 turns · 1.2M in / 38k out tokens · 2 attempts, 1 ran out of time, 1 resumed', RunStats::line($t));
    }
    public function testWhatWasNotReportedIsNotSaid(): void {
        $this->assertSame('9m 3s · 1 tool call', RunStats::line(RunStats::add([], ['seconds' => 543, 'tool_calls' => 1])), 'no tokens from the provider: none shown');
        $this->assertSame('', RunStats::line([]));
        $this->assertSame('2h 5m', RunStats::duration(7530));
    }
    public function testAPlanIsTheSumOfTheTasksThatRan(): void {
        $a = RunStats::add([], ['seconds' => 600, 'tool_calls' => 10]);
        $b = RunStats::add(RunStats::add([], ['seconds' => 1800, 'tool_calls' => 40, 'timed_out' => true]), ['seconds' => 900, 'tool_calls' => 20, 'resumed' => true]);
        $s = RunStats::sum([$a, $b, null, []]);
        $this->assertSame([2, 3300, 70, 3, 1, 1], [$s['tasks'], $s['seconds'], $s['tool_calls'], $s['attempts'], $s['timed_out'], $s['resumed']]);
    }
    public function testASessionTranscriptIsCountedFromTheRunOn(): void {
        $home = sys_get_temp_dir() . '/tiknix-runstats-' . bin2hex(random_bytes(4)); $wt = '/srv/app/.aibuilder/wt/plan-1-task-5';
        $dir = $home . '/.claude/projects/-srv-app--aibuilder-wt-plan-1-task-5'; mkdir($dir, 0700, true);
        $since = time() - 100; $old = gmdate('c', $since - 3600); $now = gmdate('c', $since + 10);
        $line = fn(array $j) => json_encode($j) . "\n";
        file_put_contents($dir . '/s.jsonl',
              $line(['type' => 'assistant', 'timestamp' => $old, 'message' => ['id' => 'm0', 'usage' => ['input_tokens' => 999, 'output_tokens' => 999], 'content' => [['type' => 'tool_use', 'name' => 'Read']]]])   // the earlier attempt
            . $line(['type' => 'assistant', 'timestamp' => $now, 'message' => ['id' => 'm1', 'usage' => ['input_tokens' => 100, 'cache_read_input_tokens' => 400, 'output_tokens' => 20], 'content' => [['type' => 'text', 'text' => 'Reading.']]]])
            . $line(['type' => 'assistant', 'timestamp' => $now, 'message' => ['id' => 'm1', 'usage' => ['input_tokens' => 100, 'cache_read_input_tokens' => 400, 'output_tokens' => 20], 'content' => [['type' => 'tool_use', 'name' => 'Read']]]])   // same reply, second block
            . $line(['type' => 'user', 'timestamp' => $now, 'message' => ['content' => [['type' => 'tool_result', 'is_error' => true, 'content' => 'no such file']]]])
            . $line(['type' => 'assistant', 'timestamp' => $now, 'message' => ['id' => 'm2', 'usage' => ['input_tokens' => 50, 'output_tokens' => 5], 'content' => [['type' => 'tool_use', 'name' => 'Bash']]]])
            . "not json\n");
        $s = AgentTask::runStats($home, $wt, $since);
        exec('rm -rf ' . escapeshellarg($home));
        $this->assertSame(['turns' => 2, 'tool_calls' => 2, 'tool_errors' => 1, 'input_tokens' => 550, 'output_tokens' => 25], $s, 'one reply in two entries is one turn and its usage once; the earlier attempt is not counted');
        $this->assertSame([], AgentTask::runStats($home, $wt, $since), 'no transcript: nothing is claimed');
    }
}
