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
use app\PlanOrchestrator;

$o = getopt('', ['slug:', 'dir:', 'member:', 'app::', 'db::', 'supersede::', 'autobuild::', 'level::', 'prompt::']);
$slug   = (string)($o['slug'] ?? '');
$dir    = rtrim((string)($o['dir'] ?? ''), '/');
$member = (int)($o['member'] ?? 0);
$app    = (string)($o['app'] ?? 'tiknix');
// TWO databases, and conflating them is what made every sidecar decompose invisible.
//
//   registry — core's db: the `instance` row, the member, the team shares. Resolving and
//              authorizing happen here and nowhere else.
//   tasks    — the instance's OWN data/workbench.db. That is what the AI Projects board
//              reads (WorkbenchDb::select), and therefore the only place an ingested plan
//              can be seen.
//
// This defaulted to core's db for BOTH, so a plan decomposed from the sidecar was written
// to core's tables while the board looked in the per-instance file and found nothing —
// the planner ran, succeeded, and produced a plan nobody could see.
//
// Resolution order, explicit first and never a silent guess: --db, then the
// TIKNIX_WORKBENCH_DB the planner exports, then derived from the instance dir we were
// given — which is where that instance's board reads by construction.
$registryDb = dirname(__DIR__) . '/database/tiknix.db';
$tasksDb    = trim((string)($o['db'] ?? ''));
if ($tasksDb === '') $tasksDb = trim((string)(getenv('TIKNIX_WORKBENCH_DB') ?: ''));
// Original task ids to remove ONCE the new (consolidated) plan is ingested — the
// delete-and-replace half of the Consolidate feature. Runs only on success below.
$supersede = array_values(array_filter(array_map('intval', explode(',', (string)($o['supersede'] ?? '')))));
// Straight-through build: the member ticked "approve and build automatically" when they
// decomposed, so the review gate is waived and the orchestrator starts the moment the
// plan exists. Only ever set by PlanRunner from that explicit opt-in.
$autoBuild = ((string)($o['autobuild'] ?? '')) === '1';
$level     = (int)($o['level'] ?? 50);
// The prompt-log row this decompose came from, so the goal you typed can be linked to the
// plan it turned into. 0 for a re-plan or any decompose that predates the prompt log.
$promptId  = (int)($o['prompt'] ?? 0);

if ($slug === '' || $dir === '' || !$member) {
    fwrite(STDERR, "usage: --slug=<slug> --dir=<instanceDir> --member=<id> [--app=tiknix] [--db=<tasks db>]\n");
    exit(2);
}
if ($tasksDb === '') $tasksDb = $dir . '/data/workbench.db';

// Every plan waiting in this instance, oldest first. Plans carry unique names now
// (see SubmitPlanTool) because two members sharing an instance could both be planning,
// and one fixed filename meant the second silently clobbered the first.
$pending = PlanIngestor::pending($dir);
if (!$pending) { echo "[ingest] no plan file — nothing to ingest\n"; exit(0); }
$planFile = $pending[0];
if (count($pending) > 1) {
    echo '[ingest] ' . count($pending) . " plans waiting; taking the oldest, the rest follow on the next run\n";
}

R::setup('sqlite:' . $registryDb);
R::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "[ingest] cannot open registry db: $registryDb\n"); exit(1); }

$tasksDir = dirname($tasksDb);
if (!is_dir($tasksDir) && !@mkdir($tasksDir, 0775, true)) {
    fwrite(STDERR, "[ingest] cannot create $tasksDir for the tasks db\n"); exit(1);
}
R::addDatabase('tasks', 'sqlite:' . $tasksDb);

// Resolve by slug (+app to disambiguate), then AUTHORIZE: the member must OWN the
// instance or be on a team it's shared with — a team member decomposing a shared
// workspace produces a plan.json here too. Mirrors Workbench::decompose /
// TaskAccessControl::canAccessInstance so ingest matches the UI's access policy.
$inst = R::findOne('instance', 'slug = ? AND app = ?', [$slug, $app]);
if (!$inst || !$inst->id) { $inst = R::findOne('instance', 'slug = ?', [$slug]); }
if (!$inst || !$inst->id) { fwrite(STDERR, "[ingest] no instance '$slug'\n"); exit(1); }
if (!(new \app\TaskAccessControl())->canAccessInstance($member, (int)$inst->id)) {
    fwrite(STDERR, "[ingest] member $member has no access to instance '$slug'\n"); exit(1);
}

// Registry work is done. Everything from here writes TASKS, so switch to the instance's
// own workbench.db — the file its board reads.
R::selectDatabase('tasks');
R::freeze(false);   // fluid: the task tables auto-create on first store

// Atomic claim: if the browser poll already ingested it, skip cleanly.
$claim = PlanIngestor::claim($planFile);
if ($claim === null) { echo "[ingest] plan.json already claimed/ingested — skipping\n"; exit(0); }

$plan = json_decode(((string)@file_get_contents($claim)) ?? '', true);
if (!PlanIngestor::isValidPlan($plan)) {
    @unlink($claim);
    fwrite(STDERR, "[ingest] plan.json is not a valid plan — discarded\n");
    exit(1);
}
// If a plan was re-planned because it partly failed, link the new one to it. The chain
// is what bounds automatic remediation to a single attempt (PlanRemediator), and it is
// also the honest record: the failed plan stays on the board next to its replacement.
$replanOf = 0;
try {
    $prev = R::findOne('workbenchtask',
        'parent_task_id IS NULL AND replan_requested_at IS NOT NULL ORDER BY id DESC');
    if ($prev && $prev->id) $replanOf = (int) $prev->id;
} catch (\Throwable $e) { /* column absent until the first remediation */ }

// The prompt this plan was decomposed FROM. .aibuilder/plan-goal.md holds the exact goal
// the planner was given — the human's ask, or for a re-plan the ask PLUS the failure
// report PlanRemediator appended. Both files are per-instance and are overwritten by the
// next decompose, so the one thing you most want when a plan looks wrong is gone by the
// time you go looking. Captured here, at the moment it still describes THIS plan.
$goalText = '';
$goalSrc  = $dir . '/.aibuilder/plan-goal.md';
if (is_file($goalSrc)) $goalText = trim((string) @file_get_contents($goalSrc));

try {
    $res = PlanIngestor::ingest($inst, $plan, $member, '', $app);
    $planId = (int) $res['parent']['id'];

    $parentBean = R::load('workbenchtask', $planId);
    if ($goalText !== '') {
        $parentBean->planGoal = $goalText;
        R::store($parentBean);
    }
    // Close the loop in the member's prompt log: the goal they typed now names the plan it
    // became. Linked by planUid, not by row id — see PlanIngestor for why.
    if ($promptId > 0) {
        \app\PromptLog::linkPlan(
            $promptId, (string) $parentBean->planUid, (string) $parentBean->title, $planId
        );
    }
    // The FULL brief (goal + the instance's reuse inventory) is 19KB of mostly
    // per-instance boilerplate, so it is snapshotted to disk rather than stored per
    // plan in the tasks db — it shapes what the planner proposes, so it is worth
    // keeping, just not worth duplicating into every row.
    $reqSrc = $dir . '/.aibuilder/plan-request.md';
    if (is_file($reqSrc)) {
        $snapDir = $dir . '/.aibuilder/plans/' . $planId;
        if ((is_dir($snapDir) || @mkdir($snapDir, 0775, true)) && !@copy($reqSrc, $snapDir . '/plan-request.md')) {
            fwrite(STDERR, "[ingest] warning: could not snapshot the planner brief to $snapDir\n");
        }
    }
    if ($replanOf > 0) {
        $newParent = R::load('workbenchtask', $planId);
        $newParent->replanOf = $replanOf;
        R::store($newParent);
        $old = R::load('workbenchtask', $replanOf);
        if ($old->id) { $old->replanRequestedAt = null; R::store($old); }   // consumed
        echo "[ingest] linked as a re-plan of #{$replanOf}\n";
    }
} catch (\Throwable $e) {
    @rename($claim, $planFile);   // release for retry (e.g. the browser fallback)
    fwrite(STDERR, "[ingest] failed: " . $e->getMessage() . "\n");
    exit(1);
}
@unlink($claim);
echo "[ingest] plan #{$res['parent']['id']} \"{$res['parent']['title']}\" with "
   . count($res['subtasks']) . " subtask(s) — tagged {$slug}.{$app} -> {$tasksDb}\n";

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

// ---------------------------------------------------------------------------
// Straight-through: approve and build, no human click.
//
// The approval is recorded either way — the member DID approve, in advance, by ticking
// the box — so the plan never sits in a state nobody chose. Only the build launch can
// fail, and when it does the plan stays 'approved' with a loud reason: that is a plan
// waiting for one Build click, not a plan silently doing nothing.
// ---------------------------------------------------------------------------
if ($autoBuild) {
    $planId = (int) $res['parent']['id'];
    $parent = R::load('workbenchtask', $planId);
    $parent->planStatus = 'approved';
    $parent->autoBuild  = 1;      // the board can say "this one was set to build itself"
    $parent->updatedAt  = date('Y-m-d H:i:s');
    R::store($parent);

    // Pass $tasksDb EXPLICITLY. It is the path this script resolved and wrote the plan
    // to; handing the launcher an env var instead would let the orchestrator write task
    // state somewhere other than where the plan it is building actually lives.
    $started = PlanOrchestrator::launch($planId, $slug, $dir, $level, 'sonnet', $tasksDb);

    $note = R::dispense('tasklog');
    $note->taskId     = $planId;
    $note->memberId   = $member;
    $note->logLevel   = $started ? 'info' : 'error';
    $note->logType    = 'system';
    $note->message    = $started
        ? 'Auto-approved and started building (straight-through was requested at decompose).'
        : 'Auto-approved, but the orchestrator could not be started — press Build to run it. See planner.log.';
    $note->createdAt  = date('Y-m-d H:i:s');
    R::store($note);

    if ($started) {
        $parent->planStatus = 'building';
        $parent->status     = 'running';
        $parent->updatedAt  = date('Y-m-d H:i:s');
        R::store($parent);
        echo "[ingest] auto-approved plan #{$planId} and started the build\n";
    } else {
        // Loud: the member asked for a build and there isn't one. Non-zero exit so the
        // planner log ends on a failure rather than on the ingest success line above.
        fwrite(STDERR, "[ingest] plan #{$planId} auto-approved but the orchestrator FAILED to start\n");
        R::close();
        exit(1);
    }
}

R::close();
