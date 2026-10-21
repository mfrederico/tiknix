<?php
/**
 * plan-nothing.php — the planner judged a request needs no change (it wrote plan-complete.md, not a
 * plan): the found problems that request was to fix are closed with its reason, instead of waiting
 * forever with their Fix it button. Run by PlanRunner's script, only when it had found problems
 * to fix (--supersede).
 *
 *   --dir=<workspace> --member=<id> --supersede=<task ids> [--app=tiknix]
 *
 * On the project's board each problem reads "Looked at: nothing needed changing" (resolved, no
 * fixed_by); in core its detected error is IGNORED — "not a problem", so the same report from a
 * later check is counted on it, not filed again (Firehose). This is how a check that took the app's
 * intended behaviour for a fault (inresonance's closed sign-up, 2026-10-20) is answered by the planner.
 */
chdir(dirname(__DIR__));   // cron and background launches start elsewhere; the app boots from its root
require dirname(__DIR__) . '/vendor/autoload.php';
require_once \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap();

use app\Bean;

$o = getopt('', ['dir:', 'member:', 'supersede:', 'app::']);
$dir = rtrim((string) ($o['dir'] ?? ''), '/');
$member = (int) ($o['member'] ?? 0);
$ids = array_values(array_filter(array_map('intval', explode(',', (string) ($o['supersede'] ?? '')))));
if ($dir === '' || !$ids) { fwrite(STDERR, "usage: --dir=<workspace> --member=<id> --supersede=<ids>\n"); exit(2); }

$why = trim((string) @file_get_contents($dir . '/.aibuilder/plan-complete.md'));
$why = $why !== '' ? mb_strimwidth(preg_replace('/\s+/', ' ', $why), 0, 600, '…') : 'The planner found nothing to change.';
$db = $dir . '/data/workbench.db';
if (!is_file($db)) { fwrite(STDERR, "[nothing] no board at {$db}\n"); exit(1); }

Bean::addDatabase('nothingws', 'sqlite:' . $db);
Bean::selectDatabase('nothingws');
Bean::freeze(false);
$errors = [];
foreach ($ids as $id) {
    $t = Bean::load('workbenchtask', $id);
    if (!$t->id || (int) $t->memberId !== $member || (string) ($t->source ?? '') !== 'detected_error' || !empty($t->parentTaskId)) continue;
    $t->status = 'resolved'; $t->fixAskedAt = 0;
    $t->progressMessage = 'Looked at: nothing needed changing — ' . $why;
    $t->updatedAt = date('Y-m-d H:i:s');
    Bean::store($t);
    if ((int) ($t->detectederrorId ?? 0) > 0) $errors[] = (int) $t->detectederrorId;
    echo "[nothing] found problem #{$id} closed: nothing needed changing\n";
}
Bean::selectDatabase('default');
foreach ($errors as $eid) {
    $e = Bean::load('detectederror', $eid);
    if (!$e->id) continue;
    $e->status = 'ignored';
    Bean::store($e);
    echo "[nothing] error #{$eid} ignored: the planner judged it intended\n";
}
