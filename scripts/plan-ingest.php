#!/usr/bin/env php
<?php
/**
 * plan-ingest.php — headless, server-side ingest of a planner's plan.json into a
 * workbench task tree. Run by the planner's runner script the moment the jailed
 * `claude -p` decomposition exits, so a plan lands in the Workbench WITHOUT any
 * browser page needing to stay open (fire-and-forget).
 *
 * Idempotent + race-safe: it ATOMICALLY claims plan.json (rename), so it can never
 * double-ingest with the AI Builder browser poll — whichever claims first wins.
 *
 * Usage:
 *   php scripts/plan-ingest.php --slug=<slug> --dir=<instanceDir> --member=<id> \
 *       [--app=tiknix] [--db=<sqlite path>]
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;
use app\PlanIngestor;

$o = getopt('', ['slug:', 'dir:', 'member:', 'app::', 'db::', 'supersede::']);
$slug   = (string)($o['slug'] ?? '');
$dir    = rtrim((string)($o['dir'] ?? ''), '/');
$member = (int)($o['member'] ?? 0);
$app    = (string)($o['app'] ?? 'tiknix');
$db     = (string)($o['db'] ?? (dirname(__DIR__) . '/database/tiknix.db'));
// Original task ids to remove ONCE the new (consolidated) plan is ingested — the
// delete-and-replace half of the Consolidate feature. Runs only on success below.
$supersede = array_values(array_filter(array_map('intval', explode(',', (string)($o['supersede'] ?? '')))));

if ($slug === '' || $dir === '' || !$member) {
    fwrite(STDERR, "usage: --slug=<slug> --dir=<instanceDir> --member=<id> [--app=tiknix] [--db=<path>]\n");
    exit(2);
}

$planFile = $dir . '/.aibuilder/plan.json';
if (!is_file($planFile)) { echo "[ingest] no plan.json — nothing to ingest\n"; exit(0); }

R::setup('sqlite:' . $db);
R::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "[ingest] cannot open db: $db\n"); exit(1); }

$inst = R::findOne('instance', 'slug = ? AND member_id = ?', [$slug, $member]);
if (!$inst || !$inst->id) { fwrite(STDERR, "[ingest] no instance '$slug' owned by member $member\n"); exit(1); }

// Atomic claim: if the browser poll already ingested it, skip cleanly.
$claim = PlanIngestor::claim($planFile);
if ($claim === null) { echo "[ingest] plan.json already claimed/ingested — skipping\n"; exit(0); }

$plan = json_decode(((string)@file_get_contents($claim)) ?? '', true);
if (!PlanIngestor::isValidPlan($plan)) {
    @unlink($claim);
    fwrite(STDERR, "[ingest] plan.json is not a valid plan — discarded\n");
    exit(1);
}
try {
    $res = PlanIngestor::ingest($inst, $plan, $member, '', $app);
} catch (\Throwable $e) {
    @rename($claim, $planFile);   // release for retry (e.g. the browser fallback)
    fwrite(STDERR, "[ingest] failed: " . $e->getMessage() . "\n");
    exit(1);
}
@unlink($claim);
echo "[ingest] plan #{$res['parent']['id']} \"{$res['parent']['title']}\" with "
   . count($res['subtasks']) . " subtask(s) — tagged {$slug}.{$app}\n";

// Delete-and-replace: now that the consolidated plan exists, remove the originals
// (only tasks owned by this member) and any parent plan left empty by the removal.
if ($supersede) {
    $parents = [];
    $removed = 0;
    foreach ($supersede as $tid) {
        $t = R::load('workbenchtask', $tid);
        if (!$t->id || (int)$t->memberId !== $member) continue;
        if ($t->parentTaskId) $parents[(int)$t->parentTaskId] = true;
        $t->xownTasklogList; $t->xownTasksnapshotList; $t->xownTaskcommentList; // cascade children
        R::trash($t);
        $removed++;
    }
    foreach (array_keys($parents) as $pid) {
        if ((int)R::count('workbenchtask', 'parent_task_id = ?', [$pid]) === 0) {
            $p = R::load('workbenchtask', $pid);
            if ($p->id && empty($p->parentTaskId) && (int)$p->memberId === $member) {
                $p->xownTasklogList; $p->xownTasksnapshotList; $p->xownTaskcommentList;
                R::trash($p);
            }
        }
    }
    echo "[ingest] superseded {$removed} original task(s)\n";
}
R::close();
