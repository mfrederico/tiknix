<?php
/**
 * RunStats — what an agent's run cost, kept per task and summed per plan: time, tool calls,
 * tokens, attempts, how many ran out of time and how many resumed.
 *
 * The app measures each run (runtime AgentTask::runStats, from the agent's own session
 * transcript) and hands the figures back with the result; this adds a run to a task's running
 * total and says a total in a line. It exists so that a change meant to make agents faster can be
 * seen to — or not to. A figure a provider did not report is absent, never guessed.
 */
namespace app;

final class RunStats {
    private const SUMMED = ['seconds', 'turns', 'tool_calls', 'tool_errors', 'input_tokens', 'output_tokens'];

    /** A task's stored totals plus one more run. */
    public static function add(array $total, array $run): array {
        foreach (self::SUMMED as $k) if (isset($run[$k])) $total[$k] = (int) ($total[$k] ?? 0) + (int) $run[$k];
        $total['attempts']  = (int) ($total['attempts'] ?? 0) + 1;
        $total['timed_out'] = (int) ($total['timed_out'] ?? 0) + (!empty($run['timed_out']) ? 1 : 0);
        $total['resumed']   = (int) ($total['resumed'] ?? 0) + (!empty($run['resumed']) ? 1 : 0);
        return $total;
    }

    /** The totals of several tasks (a plan's subtasks) — tasks: how many of them have run at all. */
    public static function sum(array $totals): array {
        $out = ['tasks' => 0];
        foreach ($totals as $t) {
            if (!is_array($t) || empty($t['attempts'])) continue;
            $out['tasks']++;
            foreach (array_merge(self::SUMMED, ['attempts', 'timed_out', 'resumed']) as $k) if (isset($t[$k])) $out[$k] = (int) ($out[$k] ?? 0) + (int) $t[$k];
        }
        return $out;
    }

    /** "27m 12s · 143 tool calls (3 failed) · 41 turns · 1.2M in / 38k out tokens · 2 attempts, 1 ran out of time, 1 resumed" */
    public static function line(array $s): string {
        if (empty($s['seconds']) && empty($s['tool_calls']) && empty($s['attempts'])) return '';
        $parts = [];
        if (isset($s['seconds'])) $parts[] = self::duration((int) $s['seconds']);
        if (isset($s['tool_calls'])) $parts[] = number_format((int) $s['tool_calls']) . ' tool call' . ((int) $s['tool_calls'] === 1 ? '' : 's') . (!empty($s['tool_errors']) ? ' (' . (int) $s['tool_errors'] . ' failed)' : '');
        if (!empty($s['turns'])) $parts[] = number_format((int) $s['turns']) . ' turns';
        if (!empty($s['input_tokens']) || !empty($s['output_tokens'])) $parts[] = self::tokens((int) ($s['input_tokens'] ?? 0)) . ' in / ' . self::tokens((int) ($s['output_tokens'] ?? 0)) . ' out tokens';
        $extra = [];
        if ((int) ($s['attempts'] ?? 0) > 1) $extra[] = (int) $s['attempts'] . ' attempts';
        if (!empty($s['timed_out'])) $extra[] = (int) $s['timed_out'] . ' ran out of time';
        if (!empty($s['resumed'])) $extra[] = (int) $s['resumed'] . ' resumed';
        if ($extra) $parts[] = implode(', ', $extra);
        return implode(' · ', $parts);
    }

    public static function duration(int $s): string {
        if ($s < 60) return "{$s}s";
        if ($s < 3600) return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
        return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
    }

    private static function tokens(int $n): string {
        if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
        if ($n >= 1000) return round($n / 1000) . 'k';
        return (string) $n;
    }
}
