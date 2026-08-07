#!/usr/bin/env php
<?php
/**
 * plan-orchestrate.php — detached driver for an approved plan.
 *
 * Ticks PlanExecutor::runOnce() (reap finished agents -> merge -> launch ready,
 * capped at MAX_CONCURRENT) until the plan reaches a terminal state. Launched in
 * its own tmux session by Aibuilder::planrun so it survives the browser.
 *
 * Usage:
 *   php scripts/plan-orchestrate.php --plan=<id> --slug=<slug> --dir=<instanceDir> \
 *       [--model=sonnet] [--level=50] [--db=<sqlite path>]
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

// R:: is the connection lifecycle ONLY (setup/testConnection/close) — it has no Bean::
// wrapper. Every read and write below goes through Bean:: so it uses the cached adapter.
use RedBeanPHP\R;
use app\Bean;
use app\PlanExecutor;

$o = getopt('', ['plan:', 'slug:', 'dir:', 'model::', 'level::', 'db::']);
$planId = (int)($o['plan'] ?? 0);
$slug   = (string)($o['slug'] ?? '');
$dir    = rtrim((string)($o['dir'] ?? ''), '/');
$model  = (string)($o['model'] ?? 'sonnet');
$level  = (int)($o['level'] ?? 50);
// The orchestrator touches ONLY task data, and task data lives in the instance's own
// data/workbench.db — the file its board reads. Defaulting to core's db meant it loaded
// workbenchtask #1 out of CORE and tried to drive that: a plan id from one database used
// as a primary key in another. It died on core's foreign keys, which was lucky; had the
// ids lined up it would have quietly marked an unrelated task as building.
//
// Explicit first, and never a silent fall back to core's: --db, then the
// TIKNIX_WORKBENCH_DB the launcher exports, then derived from the --dir we were given.
$db = trim((string)($o['db'] ?? ''));
if ($db === '') $db = trim((string)(getenv('TIKNIX_WORKBENCH_DB') ?: ''));

if (!$planId || $slug === '' || $dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: --plan=<id> --slug=<slug> --dir=<instanceDir>\n");
    exit(1);
}
if ($db === '') $db = $dir . '/data/workbench.db';
if (!is_file($db)) { fwrite(STDERR, "no tasks db at $db\n"); exit(1); }

R::setup('sqlite:' . $db);
Bean::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "cannot open db: $db\n"); exit(1); }

$parent = Bean::load('workbenchtask', $planId);
if (!$parent->id) { fwrite(STDERR, "no plan #$planId\n"); exit(1); }
$parent->planStatus = 'building';
$parent->status     = 'running';   // keep the plain status column in sync for the Workbench list
$parent->updatedAt  = date('Y-m-d H:i:s');
Bean::store($parent);

echo "[orchestrator] plan #$planId ($slug) starting " . date('c') . "\n";

$ex = new PlanExecutor($planId, $slug, $dir, $level, $model);

$maxTicks = 720;                 // ~2h ceiling at 10s/tick
$res = ['done' => false, 'stalled' => false, 'counts' => [], 'total' => 0];
for ($i = 0; $i < $maxTicks; $i++) {
    $res = $ex->runOnce();
    $c = $res['counts'];
    echo "[orchestrator] tick $i: " . json_encode($c) . ($res['stalled'] ? " STALLED" : "") . "\n";
    if ($res['done']) break;
    sleep(10);
}

// Apply DB seed scripts the plan introduced + rebuild the permission cache, but
// only for a plan that actually completed (a stalled plan is incomplete).
if (empty($res['stalled'])) {
    foreach ($ex->finalize() as $line) {
        echo "[orchestrator] finalize: $line\n";
    }
}

// THREE outcomes, not two. A plan that finished with a failed subtask is not "done" —
// it built most of a thing and stopped short, and reporting that as done means the one
// state a human needs to act on looks exactly like the state that needs nothing.
// 'resolved' is deliberately NOT counted here: a subtask that changed nothing because
// nothing needed changing is a finished subtask, not a failure.
$failedCount = (int) ($res['counts']['failed'] ?? 0) + (int) ($res['counts']['conflict'] ?? 0);
$parent = Bean::load('workbenchtask', $planId);
if (!empty($res['stalled'])) {
    $parent->planStatus = 'stalled';                 // could not proceed
    $parent->status     = 'failed';

    // WRITE DOWN WHY. "stalled" on its own sent someone reading the dependency
    // JSON of every pending task against the status of every other to work out
    // what had happened — the answer was one merge conflict and two subtasks
    // waiting on a person, and nothing on screen named any of them.
    $blocked = (array) ($res['blocked'] ?? []);
    $roots   = \app\PlanExecutor::rootCauses($blocked);
    $parent->progressMessage = $roots
        ? 'Stalled: ' . count($blocked) . ' subtask(s) cannot start. Fix ' . implode('; ', $roots) . '.'
        : 'Stalled: ' . count($blocked) . ' subtask(s) cannot start and no cause could be identified.';
    // The full chain, for the task list rather than the one-line status.
    $parent->blockedJson = json_encode($blocked, JSON_UNESCAPED_SLASHES);

    echo "[orchestrator] STALLED: {$parent->progressMessage}\n";
    foreach ($blocked as $b) {
        echo "[orchestrator]   #{$b['task']} {$b['title']} <- " . implode(', ', $b['blockers']) . "\n";
    }
} elseif ($failedCount > 0) {
    $parent->planStatus = 'attention';               // ran to the end, but not all of it landed
    $parent->status     = 'failed';
} else {
    $parent->planStatus = 'done';
    $parent->status     = 'completed';
}
$parent->updatedAt  = date('Y-m-d H:i:s');
Bean::store($parent);

echo "[orchestrator] plan #$planId finished status={$parent->planStatus} " . date('c') . "\n";

// TELL THE OWNER. A build that ends by writing a line to a log file inside a tmux
// session nobody has open has not told anyone anything. This lands in Communications
// and lights the bell in the shell — no mail configuration required — and emails as
// well only if core's [mail] is set up. Never fatal: a notification that fails must
// not undo a build that succeeded.
try {
    $aibCfg     = @parse_ini_file(__DIR__ . '/../conf/aibuilder.ini', true) ?: [];
    $planTitle  = (string) $parent->title;
    $planMember = (int) $parent->memberId;
    $instCfg    = @parse_ini_file($dir . '/conf/config.ini', true) ?: [];
    $baseUrl    = (string) ($instCfg['app']['baseurl'] ?? '');

    // Which subtasks did not land, and the last thing each said. Read from the same
    // tasks db we have been driving, so the notification carries the detail instead of
    // pointing at a log the reader has to go and find.
    $failures = [];
    foreach (Bean::find('workbenchtask', 'parent_task_id = ? AND status IN (?, ?) ORDER BY id',
                     [$planId, 'failed', 'conflict']) as $ft) {
        $why = '';
        try {
            $log = Bean::findOne('tasklog', 'task_id = ? ORDER BY id DESC', [(int) $ft->id]);
            if ($log && $log->id) $why = (string) ($log->content ?? '');
        } catch (\Throwable $e) { /* no logs for this task */ }
        $failures[] = ['title' => (string) $ft->title, 'why' => $why];
    }

    // ONE automatic re-plan when work did not land. Task-level retries have already been
    // exhausted by here, so what is left is a plan that was wrong — and the answer to a
    // wrong plan is a better plan, once, then a person. See PlanRemediator.
    $remedy = ['action' => 'none', 'why' => ''];
    if ($failures) {
        try {
            $remedy = \app\PlanRemediator::afterPlan(
                $parent, $failures, $slug, $dir, $planMember, $level,
                (string) ($parent->engine ?: 'claude')
            );
        } catch (\Throwable $e) {
            $remedy = ['action' => 'escalate', 'why' => 'remediation error: ' . $e->getMessage()];
        }
        echo "[orchestrator] remediation: {$remedy['action']} — {$remedy['why']}\n";
    }
    echo '[orchestrator] ' . \app\PlanNotifier::planFinished(
        dirname(__DIR__) . '/database/tiknix.db',
        [
            'plan_id'   => $planId,
            'title'     => $planTitle,
            'slug'      => $slug,
            'member_id' => $planMember,
            'status'    => (string) $parent->planStatus,
            'counts'    => $res['counts'] ?? [],
            'base_url'  => $baseUrl,
            'board_url' => rtrim((string) ($aibCfg['sidecar']['workbench_url'] ?? 'https://workbench.tiknix.com'), '/') . '/workbench',
            'failures'  => $failures,
            'remedy'    => $remedy,
        ]
    ) . "\n";
} catch (\Throwable $e) {
    echo '[orchestrator] notify: FAILED — ' . $e->getMessage() . "\n";
}

R::close();

// Definition-of-Done audit — only for a plan that actually completed. Spawned
// DETACHED (the auditor drives Playwright for several minutes on its own), so
// this orchestrator process is free to exit. Opt-out via aibuilder.ini [audit].
if (empty($res['stalled'])) {
    $aib = @parse_ini_file(__DIR__ . '/../conf/aibuilder.ini', true) ?: [];
    if (filter_var($aib['audit']['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
        $cmd = 'nohup php ' . escapeshellarg(__DIR__ . '/plan-audit.php')
             . ' --plan=' . (int)$planId
             . ' --slug=' . escapeshellarg($slug)
             . ' --dir='  . escapeshellarg($dir)
             . ' --level=' . (int)$level
             . ' > ' . escapeshellarg($dir . '/.aibuilder/audit-driver.log') . ' 2>&1 &';
        exec($cmd);
        echo "[orchestrator] launched Definition-of-Done audit for plan #$planId\n";
    }
}
