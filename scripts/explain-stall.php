#!/usr/bin/env php
<?php
/**
 * explain-stall.php — write down WHY a plan stalled, for plans that stalled before
 * the orchestrator learned to record it.
 *
 *   php scripts/explain-stall.php --db=<path/to/workbench.db>        # report only
 *   php scripts/explain-stall.php --db=<path> --apply
 *   php scripts/explain-stall.php --all                              # every instance
 *
 * A plan stalls when nothing is running, subtasks are pending, and none of them can
 * start. PlanExecutor::depsMerged() accepts `merged` and `resolved`, so `failed`,
 * `conflict`, `awaiting` and a missing dependency are each a dead end for whatever
 * sits downstream — usually a cascade from one or two subtasks that actually need a
 * person. This finds those, and rootCauses() reduces the cascade to them.
 *
 * Goes through Bean:: (CLAUDE.md) rather than raw PDO. Not ceremony — it is what
 * makes this simple: the target workbench.db is registered as a named RedBean
 * connection, so a plan is an ordinary bean and blocked_json APPEARS ON STORE
 * because the schema is fluid. The first version of this script used PDO and had to
 * ALTER TABLE the column in by hand, which then failed because SQLite resolves
 * column names at prepare() time and the try/catch around execute() never fired.
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap();

use app\Bean;
use app\PlanExecutor;

$opt   = getopt('', ['db::', 'all', 'apply']);
$apply = isset($opt['apply']);

$targets = [];
if (isset($opt['all'])) {
    foreach (glob('/var/www/html/default/*/data/workbench.db') ?: [] as $f) $targets[basename(dirname(dirname($f)))] = $f;
} elseif (!empty($opt['db'])) {
    $targets[basename(dirname(dirname((string) $opt['db'])))] = (string) $opt['db'];
} else {
    fwrite(STDERR, "usage: explain-stall.php --db=<workbench.db> | --all  [--apply]\n");
    exit(2);
}

echo $apply ? "writing stall reasons\n" : "DRY RUN — nothing will change (pass --apply)\n";
$found = 0;

foreach ($targets as $slug => $db) {
    if (!is_file($db)) { printf("  %-26s no workbench.db\n", $slug); continue; }

    // One named connection per file: reusing a key across databases would quietly
    // keep the first one open, which is the sort of bug that makes a script report
    // another instance's plans as this one's.
    $key = 'stall_' . substr(sha1($db), 0, 8);
    if (!Bean::hasDatabase($key)) Bean::addDatabase($key, 'sqlite:' . $db);
    Bean::selectDatabase($key);

    try {
        // Fluid mode answers a query naming an absent column with nothing rather
        // than an error, so an instance that has never planned reads as "no stalled
        // plans" — which is true.
        $plans = Bean::find('workbenchtask', 'plan_status = ?', ['stalled']);
    } catch (\Throwable $e) {
        printf("  %-26s could not read: %s\n", $slug, $e->getMessage());
        Bean::selectDatabase('default');
        continue;
    }

    foreach ($plans as $plan) {
        $found++;
        $subs = Bean::find('workbenchtask', 'parent_task_id = ?', [(int) $plan->id]);

        // Statuses of everything in the plan, so a dependency can be judged. Keyed
        // by id, and array_values is irrelevant here because nothing is bound into
        // an IN() — see CLAUDE.md on id-keyed find() results.
        $byId = [];
        foreach ($subs as $s) $byId[(int) $s->id] = $s;

        $blocked = [];
        foreach ($subs as $t) {
            if ((string) $t->status !== 'pending') continue;
            $why = [];
            foreach ((json_decode((string) $t->dependsOn, true) ?: []) as $depId) {
                $dep = $byId[(int) $depId] ?? null;
                if (!$dep)  { $why[] = "#{$depId} (no such task in this plan)"; continue; }
                if (in_array((string) $dep->status, ['merged', 'resolved'], true)) continue;
                $why[] = sprintf('#%d %s — %s', (int) $dep->id, $dep->status, shorten((string) $dep->title));
            }
            if ($why) $blocked[] = ['task' => (int) $t->id, 'title' => shorten((string) $t->title), 'blockers' => $why];
        }

        $roots = PlanExecutor::rootCauses($blocked);
        $msg   = $roots
            ? 'Stalled: ' . count($blocked) . ' subtask(s) cannot start. Fix ' . implode('; ', $roots) . '.'
            : 'Stalled: ' . count($blocked) . ' subtask(s) cannot start and no cause could be identified.';

        printf("\n  %s  PLAN #%d — %s\n    %s\n", $slug, (int) $plan->id, shorten((string) $plan->title), $msg);
        foreach ($blocked as $b) printf("      #%-3d %-44s <- %s\n", $b['task'], $b['title'], implode(', ', $b['blockers']));

        if ($apply) {
            // A bean, not an UPDATE. blocked_json does not exist on a database whose
            // plans predate it and does not need to: RedBean adds the column on
            // store because the schema is fluid.
            $plan->progressMessage = $msg;
            $plan->blockedJson     = json_encode($blocked, JSON_UNESCAPED_SLASHES);
            $plan->updatedAt       = date('Y-m-d H:i:s');
            Bean::store($plan);
            echo "      written\n";
        }
    }

    Bean::selectDatabase('default');
}

printf("\n  %d stalled plan(s)%s\n", $found, $apply ? ' updated' : ' — pass --apply to write the reason');

function shorten(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return mb_strlen($s) > 48 ? mb_substr($s, 0, 47) . '…' : $s;
}
