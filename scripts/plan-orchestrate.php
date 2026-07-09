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

use RedBeanPHP\R;
use app\PlanExecutor;

$o = getopt('', ['plan:', 'slug:', 'dir:', 'model::', 'level::', 'db::']);
$planId = (int)($o['plan'] ?? 0);
$slug   = (string)($o['slug'] ?? '');
$dir    = rtrim((string)($o['dir'] ?? ''), '/');
$model  = (string)($o['model'] ?? 'sonnet');
$level  = (int)($o['level'] ?? 50);
$db     = (string)($o['db'] ?? (dirname(__DIR__) . '/database/tiknix.db'));

if (!$planId || $slug === '' || $dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: --plan=<id> --slug=<slug> --dir=<instanceDir>\n");
    exit(1);
}

R::setup('sqlite:' . $db);
R::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "cannot open db: $db\n"); exit(1); }

$parent = R::load('workbenchtask', $planId);
if (!$parent->id) { fwrite(STDERR, "no plan #$planId\n"); exit(1); }
$parent->planStatus = 'building';
$parent->updatedAt  = date('Y-m-d H:i:s');
R::store($parent);

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

$parent = R::load('workbenchtask', $planId);
$parent->planStatus = !empty($res['stalled']) ? 'stalled' : 'done';
$parent->updatedAt  = date('Y-m-d H:i:s');
R::store($parent);
R::close();

echo "[orchestrator] plan #$planId finished status={$parent->planStatus} " . date('c') . "\n";
