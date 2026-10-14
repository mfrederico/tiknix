<?php
/**
 * PlanRank — Plan and Fan, step one: every task of a plan carries how much work it is (effort,
 * 1–5), how likely it is to go wrong and how badly (risk, 1–5, 5 the most), and how much waits
 * on it (leverage); a plan's tasks add up to the request's rank. Recorded and shown — nothing
 * yet decides anything by it. The estimates are kept beside what each task really cost
 * (its stats), so later steps can learn from them.
 *
 * Who decides what:
 *   effort    the planner's estimate, alone
 *   risk      the planner's judgement, RAISED by one for each fact the platform can check
 *             (checks()); a person's choice on the task wins over both
 *   leverage  arithmetic on the plan's dependencies (rollup()), never a judgement
 *
 * Stored on each task as rank_json: {effort, risk, planner, why[], set_by}.
 */

namespace app;

class PlanRank {
    /** What a person picks from, on the task: words, the 1–5 scale behind them. */
    public const LABELS = [5 => 'Critical', 4 => 'High', 3 => 'Medium', 2 => 'Low', 1 => 'Minor'];

    /** A 1–5 score from what the planner wrote, or null when it gave none (not invented). */
    public static function scale($v): ?int {
        if (!is_numeric($v)) return null;
        $n = (int) round((float) $v);
        return ($n >= 1 && $n <= 5) ? $n : null;
    }

    /**
     * What the platform can see about a task that makes it riskier, a sentence each.
     * @param array $task  ['files' => string[], 'adopts' => string[], 'verify' => string[]]
     * @param array $others  the tasks that could run beside it (concurrent()): [['title' => …, 'files' => string[]], …]
     * @return string[]
     */
    public static function checks(array $task, array $others): array {
        $why = [];
        $files = array_values(array_filter(array_map('strval', (array) ($task['files'] ?? []))));
        foreach ($others as $o) {
            $same = array_values(array_intersect($files, array_map('strval', (array) ($o['files'] ?? []))));
            if ($same) { $why[] = '“' . mb_strimwidth((string) ($o['title'] ?? 'another task'), 0, 60, '…') . '” changes the same file (' . $same[0] . ')'; break; }
        }
        foreach ($files as $f) {
            if (preg_match('#^services/Schema/Seeds/#', $f)) { $why[] = 'it writes a seed (data or a table on the live app)'; break; }
        }
        foreach ($files as $f) {
            if (preg_match('#(^|/)(Auth|Login|Member|Permission|Authcontrol|Session|Twofa|Security)[A-Za-z]*\.php$#i', $f) || preg_match('#authcontrol|seedRule|permission#i', $f)) { $why[] = 'it touches sign-in, permissions or who sees what'; break; }
        }
        if (array_filter(array_map('strval', (array) ($task['adopts'] ?? [])))) $why[] = 'it adopts a plugin';
        if (!array_filter(array_map('strval', (array) ($task['verify'] ?? [])))) $why[] = 'it has no checks of its own';
        return $why;
    }

    /**
     * The tasks that could run AT THE SAME TIME as $ref — not before it nor after it in the
     * dependency chain. Only those can collide on a shared file; a design task that waits for
     * the data task it restyles shares its files by design. $tasks: [['ref' => …, 'deps' => refs[], …], …]
     */
    public static function concurrent(string $ref, array $tasks): array {
        $deps = []; foreach ($tasks as $t) $deps[(string) $t['ref']] = array_map('strval', (array) ($t['deps'] ?? []));
        $reach = function (string $from, array $edges) { $seen = []; $stack = $edges[$from] ?? []; while ($stack) { $x = array_pop($stack); if (isset($seen[$x])) continue; $seen[$x] = true; foreach ($edges[$x] ?? [] as $y) $stack[] = $y; } return $seen; };
        $up = $reach($ref, $deps);                                             // what it waits on
        $rev = []; foreach ($deps as $r => $ds) foreach ($ds as $d) $rev[$d][] = $r;
        $down = $reach($ref, $rev);                                            // what waits on it
        return array_values(array_filter($tasks, fn($t) => (string) $t['ref'] !== $ref && !isset($up[(string) $t['ref']]) && !isset($down[(string) $t['ref']])));
    }

    /** The rank_json of a task as planned: the planner's scores, raised by what the checks found. */
    public static function planned($effort, $risk, string $riskReason, array $why): array {
        $e = self::scale($effort); $r = self::scale($risk);
        $final = $r === null ? null : min(5, $r + count($why));   // one step up for each thing the platform found
        $reasons = array_merge(trim($riskReason) !== '' ? [trim($riskReason)] : [], $why);
        return ['effort' => $e, 'planner' => $r, 'risk' => $final, 'why' => $reasons, 'set_by' => 'planner'];
    }

    /** A person set the risk: it wins, and is kept apart so the estimate it replaced can still be learned from. */
    public static function setByPerson(array $rank, int $risk): array {
        if (self::scale($risk) === null) throw new \InvalidArgumentException('risk is 1 to 5');
        return ['risk' => $risk, 'set_by' => 'person'] + $rank;
    }

    /**
     * A plan's tasks, rolled up. $tasks: [['id' => int, 'deps' => int[], 'effort' => ?int, 'risk' => ?int], …]
     * @return array{total:int, scored:int, tasks:int, max_risk:?int, high:int, path:int[], path_effort:int, leverage:array<int,int>, class:string}
     */
    public static function rollup(array $tasks): array {
        $byId = []; foreach ($tasks as $t) $byId[(int) $t['id']] = $t;
        $kids = []; foreach ($tasks as $t) foreach ((array) $t['deps'] as $d) if (isset($byId[(int) $d])) $kids[(int) $d][] = (int) $t['id'];
        // leverage: everything downstream of a task
        $lev = [];
        foreach ($byId as $id => $_) {
            $seen = []; $stack = $kids[$id] ?? [];
            while ($stack) { $k = array_pop($stack); if (isset($seen[$k])) continue; $seen[$k] = true; foreach ($kids[$k] ?? [] as $x) $stack[] = $x; }
            $lev[$id] = count($seen);
        }
        // the critical path: the longest chain by effort (an unscored task counts as 1)
        $memo = [];
        $longest = function (int $id) use (&$longest, &$memo, $byId, $kids): array {
            if (isset($memo[$id])) return $memo[$id];
            $memo[$id] = [0, []];   // a cycle cannot run away
            $best = [0, []];
            foreach ($kids[$id] ?? [] as $k) { $c = $longest($k); if ($c[0] > $best[0]) $best = $c; }
            $w = (int) ($byId[$id]['effort'] ?? 0) ?: 1;
            return $memo[$id] = [$w + $best[0], array_merge([$id], $best[1])];
        };
        $path = [0, []];
        foreach ($byId as $id => $t) {
            if (array_filter((array) $t['deps'], fn($d) => isset($byId[(int) $d]))) continue;   // chains start where nothing is waited on
            $c = $longest($id); if ($c[0] > $path[0]) $path = $c;
        }
        $scores = array_filter(array_map(fn($t) => $t['effort'] ?? null, $tasks), fn($v) => $v !== null);
        $risks  = array_filter(array_map(fn($t) => $t['risk'] ?? null, $tasks), fn($v) => $v !== null);
        $total = (int) array_sum($scores); $max = $risks ? (int) max($risks) : null;
        $class = $scores === [] ? '' : (($max ?? 0) >= 5 || $total >= 15 ? 'large' : ($total <= 3 && ($max ?? 0) <= 2 ? 'small' : 'medium'));
        return ['total' => $total, 'scored' => count($scores), 'tasks' => count($tasks), 'max_risk' => $max, 'high' => count(array_filter($risks, fn($r) => $r >= 4)),
                'path' => $path[1], 'path_effort' => $path[0], 'leverage' => $lev, 'class' => $class];
    }
}
