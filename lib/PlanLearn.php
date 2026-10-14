<?php
/**
 * PlanLearn — Plan and Fan, step four: a project's estimates checked against what happened.
 *
 * Every task carries the planner's scores (rank_json) and what it really cost (stats_json, summed
 * over its attempts by app\RunStats), and how it ended (its status, its review). Read together,
 * per project, they answer:
 *   effort  what a task scored 1..5 has really taken HERE: its median time and tokens
 *   risk    how often a task at each risk had trouble — failed, needed another attempt, or its
 *           review found something
 *   model   what the same effort costs on each model the project's agents ran
 *
 * The answer goes where the planner reads it (section()) — "in this project, tasks you score 2
 * have taken 1m 30s" — and where a person reads it: the request's floor in Ask (floor()).
 * Nothing is a hidden weight: a planner reading it decides what to do with it.
 */

namespace app;

class PlanLearn {
    /** Fewer finished, scored tasks than this, and there is nothing to learn yet. */
    public const MIN_TASKS = 3;
    /** The most recent tasks read: a project's early work stops counting as it grows. */
    public const RECENT = 120;

    /** A project's finished, scored, measured tasks, newest first — or [] when it has no board yet. */
    public static function rows(string $dir): array {
        $db = rtrim($dir, '/') . '/data/workbench.db';
        if (!is_file($db)) return [];
        $pdo = new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        $cols = array_column($pdo->query('PRAGMA table_info(workbenchtask)')->fetchAll(), 'name');
        foreach (['rank_json', 'stats_json'] as $c) if (!in_array($c, $cols, true)) return [];   // nothing scored or measured here yet
        $review = in_array('review_json', $cols, true) ? 'review_json' : "'' AS review_json";
        $st = $pdo->prepare("SELECT id, status, rank_json, stats_json, {$review} FROM workbenchtask
                             WHERE parent_task_id > 0 AND status IN ('merged', 'resolved', 'failed', 'conflict')
                               AND rank_json IS NOT NULL AND rank_json <> '' AND stats_json IS NOT NULL AND stats_json <> ''
                             ORDER BY id DESC LIMIT " . self::RECENT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $rank = json_decode((string) $r['rank_json'], true) ?: [];
            $stats = json_decode((string) $r['stats_json'], true) ?: [];
            if (empty($stats['attempts'])) continue;
            $out[] = ['id' => (int) $r['id'], 'status' => (string) $r['status'], 'rank' => $rank, 'stats' => $stats,
                      'review' => json_decode((string) $r['review_json'], true) ?: []];
        }
        return $out;
    }

    /**
     * What the rows say. Pure: rows as rows() gives them.
     * @return array{tasks:int, effort:array<int,array>, risk:array<int,array>, model:array<string,array>}
     */
    public static function learn(array $rows): array {
        $byEffort = []; $byRisk = []; $byModel = [];
        foreach ($rows as $r) {
            $e = PlanRank::scale($r['rank']['effort'] ?? null);
            $k = PlanRank::scale($r['rank']['risk'] ?? null);
            $secs = (int) ($r['stats']['seconds'] ?? 0);
            $toks = (int) ($r['stats']['input_tokens'] ?? 0) + (int) ($r['stats']['output_tokens'] ?? 0);
            if ($e !== null && $secs > 0) {
                $byEffort[$e]['seconds'][] = $secs;
                if ($toks > 0) $byEffort[$e]['tokens'][] = $toks;
                if (($m = (string) ($r['stats']['model'] ?? '')) !== '') { $byModel[$m]['seconds'][] = $secs; if ($toks > 0) $byModel[$m]['tokens'][] = $toks; $byModel[$m]['effort'][] = $e; }
            }
            if ($k !== null) {
                $byRisk[$k]['n'] = ($byRisk[$k]['n'] ?? 0) + 1;
                $byRisk[$k]['trouble'] = ($byRisk[$k]['trouble'] ?? 0) + (self::trouble($r) !== '' ? 1 : 0);
            }
        }
        $effort = [];
        foreach ($byEffort as $e => $v) $effort[$e] = ['n' => count($v['seconds']), 'seconds' => self::median($v['seconds']), 'tokens' => isset($v['tokens']) ? self::median($v['tokens']) : null];
        ksort($effort);
        $risk = [];
        foreach ($byRisk as $k => $v) $risk[$k] = ['n' => $v['n'], 'trouble' => $v['trouble']];
        ksort($risk);
        $model = [];
        foreach ($byModel as $m => $v) $model[$m] = ['n' => count($v['seconds']), 'seconds' => self::median($v['seconds']), 'tokens' => isset($v['tokens']) ? self::median($v['tokens']) : null, 'effort' => self::median($v['effort'])];
        return ['tasks' => count($rows), 'effort' => $effort, 'risk' => $risk, 'model' => $model];
    }

    /** What went wrong with a finished task, in a word — '' when nothing did. */
    public static function trouble(array $r): string {
        if (in_array($r['status'], ['failed', 'conflict'], true)) return 'failed';
        if ((int) ($r['stats']['attempts'] ?? 1) > 1) return 'retried';
        if (($r['review']['state'] ?? '') === 'found') return 'review found';
        return '';
    }

    /** For the planner's brief: what tasks have really cost here. '' until there is enough to say. */
    public static function section(array $l): string {
        if ($l['tasks'] < self::MIN_TASKS || !$l['effort']) return '';
        $lines = [];
        foreach ($l['effort'] as $e => $v) {
            $lines[] = "- effort {$e}: {$v['n']} task" . ($v['n'] === 1 ? '' : 's') . ', a median of ' . RunStats::duration($v['seconds'])
                     . ($v['tokens'] !== null ? ' and ' . RunStats::tokens($v['tokens']) . ' tokens' : '');
        }
        $risk = [];
        foreach ($l['risk'] as $k => $v) $risk[] = (PlanRank::LABELS[$k] ?? $k) . ": {$v['trouble']} of {$v['n']}";
        $models = '';
        if (count($l['model']) > 1) {
            $models = "\nOn each model (median per task): " . implode('; ', array_map(fn($m, $v) => "{$m} " . RunStats::duration($v['seconds']) . ($v['tokens'] !== null ? ', ' . RunStats::tokens($v['tokens']) . ' tokens' : '') . " over {$v['n']}", array_keys($l['model']), $l['model'])) . '.';
        }
        $list = implode("\n", $lines);
        $riskLine = $risk ? "\nTasks that had trouble (failed, needed another attempt, or their review found something), by the risk they were given: " . implode(' · ', $risk) . '.' : '';
        return <<<MD

## What tasks have really cost in THIS project

From the last {$l['tasks']} tasks built here, by the effort they were scored:

{$list}
{$riskLine}{$models}

Score `effort` against these, not against projects in general: work like what took a minute here
is a 1 or 2, whatever it would be elsewhere. Keep scoring `risk` for what breaks if a task is wrong;
the counts above say how often each level has actually had trouble here.

MD;
    }

    /**
     * The least time a plan can take here: its critical path (PlanRank::rollup's path) at this
     * project's median for each task's effort. null when an effort on the path has no record yet.
     * $tasks: the rollup's input; $l: learn().
     */
    public static function floor(array $path, array $tasks, array $l): ?int {
        if (!$path || $l['tasks'] < self::MIN_TASKS) return null;
        $byId = []; foreach ($tasks as $t) $byId[(int) $t['id']] = $t;
        $s = 0;
        foreach ($path as $id) {
            $e = PlanRank::scale($byId[(int) $id]['effort'] ?? null);
            if ($e === null || !isset($l['effort'][$e])) return null;
            $s += (int) $l['effort'][$e]['seconds'];
        }
        return $s;
    }

    /**
     * What tasks of these efforts have used here, added up, in tokens — the request's likely cost,
     * before it starts. A task whose effort was never built here adds nothing (said by `unknown`);
     * null until the project has a record to go on.
     * @param array $efforts  one effort (1–5 or null) per task
     * @return array{tokens:int, unknown:int}|null
     */
    public static function estimate(array $efforts, array $l): ?array {
        if ($l['tasks'] < self::MIN_TASKS) return null;
        $sum = 0; $unknown = 0;
        foreach ($efforts as $e) {
            $e = PlanRank::scale($e);
            if ($e === null || ($l['effort'][$e]['tokens'] ?? null) === null) { $unknown++; continue; }
            $sum += (int) $l['effort'][$e]['tokens'];
        }
        return ['tokens' => $sum, 'unknown' => $unknown];
    }

    private static function median(array $v): int {
        sort($v); $n = count($v);
        if ($n === 0) return 0;
        return (int) round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
    }
}
