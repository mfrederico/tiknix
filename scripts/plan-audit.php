#!/usr/bin/env php
<?php
/**
 * plan-audit.php — Definition-of-Done QA pass for a completed plan.
 *
 * Spawned detached by plan-orchestrate.php once a plan reaches planStatus=done.
 * Deterministically provisions three test users (ROOT/ADMIN/MEMBER) in the
 * instance via its own clitool, launches a jailed Playwright QA agent
 * (AuditRunner) that drives the live site as each level and writes
 * .aibuilder/audit.json, then hands the manifest to AuditReporter (per-subtask
 * comments + firehose on failure + email with screenshots), and finally tears
 * the test users back down.
 *
 * Usage:
 *   php scripts/plan-audit.php --plan=<id> --slug=<slug> --dir=<instanceDir> [--level=50]
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }

// The orchestrator that spawns us runs with CWD = the instance dir; pin CWD to the
// control-plane root so bootstrap + 'conf/config.ini' resolve to THIS app (and its
// DB), not the instance's.
chdir(dirname(__DIR__));

// Full bootstrap so Flight config (app.baseurl, firehose.ingest_key), the logger,
// Mailer, and the control-plane DB connection are all live.
require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;
use app\AuditRunner;
use app\AuditReporter;

$app = new app\Bootstrap('conf/config.ini');
R::freeze(false);   // audit stamps fluid columns (auditStatus/auditAt/auditFailures)

$o = getopt('', ['plan:', 'slug:', 'dir:', 'level::']);
$planId = (int)($o['plan'] ?? 0);
$slug   = (string)($o['slug'] ?? '');
$dir    = rtrim((string)($o['dir'] ?? ''), '/');
$level  = (int)($o['level'] ?? 50);

function alog(string $msg): void { fwrite(STDOUT, '[audit] ' . $msg . "\n"); }

if (!$planId || $slug === '' || $dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: --plan=<id> --slug=<slug> --dir=<instanceDir>\n");
    exit(1);
}

// Opt-out switch (conf/aibuilder.ini [audit] enabled=false).
$aib = @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
if (!filter_var($aib['audit']['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
    alog('audit disabled via config — skipping'); exit(0);
}

$plan = R::load('workbenchtask', $planId);
if (!$plan->id) { fwrite(STDERR, "no plan #$planId\n"); exit(1); }
$inst = R::findOne('instance', 'slug = ?', [$slug]);
if (!$inst || !$inst->id) { fwrite(STDERR, "no instance $slug\n"); exit(1); }

$appNs   = (string)($inst->app ?: 'tiknix');
$baseUrl = "https://{$slug}.{$appNs}.com";
$clitool = $dir . '/scripts/clitool.php';
if (!is_file($clitool)) { fwrite(STDERR, "no clitool at $clitool\n"); exit(1); }

// --- 1) Provision ephemeral test users (deterministic; agent only logs in) ----
$token = substr(sha1($planId . '|' . $slug . '|' . $plan->updatedAt), 0, 8);
$specs = ['root' => 1, 'admin' => 50, 'member' => 100];
$creds = [];

function runClitool(string $dir, array $args): array {
    $cmd = 'cd ' . escapeshellarg($dir) . ' && php ' . escapeshellarg('scripts/clitool.php');
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
    $out = []; $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return ['ok' => $code === 0, 'out' => implode("\n", $out), 'code' => $code];
}

function deleteTestUsers(string $dir, array $creds): void {
    foreach ($creds as $c) {
        runClitool($dir, ['--user=' . $c['email'], '--delete-user', '--yes']);
    }
}

foreach ($specs as $role => $lvl) {
    $email = "qa-{$role}-{$planId}-{$token}@audit.example.com";
    $pw    = 'Qa!' . bin2hex(random_bytes(5));   // >= 8 chars, mixed
    // Idempotent: clear any stale same-email user from a prior run, then create.
    runClitool($dir, ['--user=' . $email, '--delete-user', '--yes']);
    $r = runClitool($dir, ['--adduser=' . $email, '--password=' . $pw, '--level=' . $lvl, '--username=qa' . $role . $token]);
    if (!$r['ok']) { alog("could not create $role test user: " . substr($r['out'], -200)); continue; }
    $creds[$role] = ['email' => $email, 'password' => $pw, 'level' => $lvl];
}
if (!$creds) { alog('no test users could be created — aborting'); exit(1); }
alog('created ' . count($creds) . ' test users');

// Crash-safe teardown: if the QA agent hangs past the deadline, a fatal aborts the
// script, or the tmux session is killed, the normal deleteTestUsers() below is
// skipped and the ephemeral qa-* accounts leak. Register the teardown as a shutdown
// handler (idempotent — clitool no-ops on an already-deleted user) so it ALWAYS runs.
$teardownDone = false;
register_shutdown_function(function () use ($dir, $creds, &$teardownDone) {
    if ($teardownDone) return;
    deleteTestUsers($dir, $creds);
    fwrite(STDOUT, "[audit] teardown via shutdown handler (deadline/fatal/kill)\n");
});

// --- 2) Build the "what changed" checklist from the plan's subtasks -----------
$checklist = [];
foreach (R::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [$planId]) as $s) {
    $ref   = $s->planRef ?: ('#' . $s->id);
    $files = json_decode((string)($s->relatedFiles ?? ''), true);
    $line  = "[{$ref}] " . trim((string)$s->title);
    if (is_array($files) && $files) $line .= ' — files: ' . implode(', ', array_slice($files, 0, 5));
    $checklist[] = $line;
}

// --- 3) Launch the jailed QA agent and wait for the manifest ------------------
$runner = new AuditRunner($slug, $dir, $baseUrl, (int)$inst->memberId, $level);
try {
    $runner->start($creds, $checklist, $planId);
    alog('QA agent launched (' . $runner->getSessionName() . ')');
} catch (\Throwable $e) {
    alog('failed to launch QA agent: ' . $e->getMessage());
    deleteTestUsers($dir, $creds);
    exit(1);
}

$deadline = time() + 15 * 60;    // 15-minute ceiling
while (time() < $deadline) {
    if ($runner->manifestReady()) break;
    if (!$runner->running()) { sleep(2); break; }   // agent exited; give the file a beat to land
    sleep(10);
}
$runner->stop();

// --- 4) Report ----------------------------------------------------------------
$manifestFile = $runner->manifestFile();
if (!is_file($manifestFile)) {
    alog('no manifest produced within the time budget');
    // Record a failed audit so the UI reflects it, and tear down.
    $plan->auditStatus = 'failed';
    $plan->auditAt = date('Y-m-d H:i:s');
    $plan->auditFailures = -1;   // sentinel: agent produced nothing
    R::store($plan);
    deleteTestUsers($dir, $creds);
    exit(1);
}

$manifest = json_decode((string)@file_get_contents($manifestFile), true);
if (!is_array($manifest)) {
    alog('manifest was not valid JSON');
    deleteTestUsers($dir, $creds);
    exit(1);
}

$res = AuditReporter::report($manifest, $plan, $inst, $dir, (int)($plan->auditCycle ?? 0));
alog('reported: ' . json_encode($res));

// --- 5) Tear down test users --------------------------------------------------
deleteTestUsers($dir, $creds);
$teardownDone = true;   // normal path done; stop the shutdown handler re-running it
alog('test users removed; done ' . ($res['passed'] ? 'PASS' : 'FAIL'));

// --- 6) Idle-sweep: the instance is now idle, so drain ONE deferred fix. The
//        active-build guard defers fixes while an agent is on the repo (and the
//        plan-independent dedup won't re-triage them), so without this a deferred
//        403/error would stick. Launching one at a time lets them drain across
//        cycles: fix builds -> re-audits -> on completion this sweep fires again.
sweepOneDeferred($inst, $dir, $level);

R::close();
exit($res['passed'] ? 0 : 2);

/** Launch one deferred/reopened auto-triage fix for an idle instance. Returns plan id or 0. */
function sweepOneDeferred($inst, string $dir, int $level): int {
    $tag = (string)$inst->slug . '.' . (string)($inst->app ?: 'tiknix');
    if (!filter_var($inst->autoTriage ?? false, FILTER_VALIDATE_BOOLEAN)) return 0;
    // Idle only: no task for this instance currently running/building.
    if ((int)R::getCell("SELECT COUNT(*) FROM workbenchtask WHERE instance_tag = ? AND status IN ('running','building')", [$tag]) > 0) {
        alog('idle-sweep skipped — instance still has an active build');
        return 0;
    }
    $err = R::findOne('detectederror',
        "instance_tag = ? AND status IN ('deferred','reopened') ORDER BY id ASC", [$tag]);
    if (!$err || !$err->id) return 0;

    $ctx  = json_decode((string)$err->context, true) ?: [];
    $desc = "Auto-triaged (idle sweep) from a detected error on {$tag}.\n\n"
          . "**Message:** " . (string)$err->message . "\n"
          . ((string)$err->url ? "**URL:** " . (string)$err->url . "\n" : '')
          . ((string)$err->fullMessage ? "\n" . (string)$err->fullMessage . "\n" : '');
    $plan = [
        'title'    => 'Fix: ' . mb_substr((string)$err->message, 0, 120),
        'summary'  => 'Auto-triaged (idle sweep) from a detected error on ' . $tag . '.',
        'subtasks' => [[
            'id' => 't1', 'title' => 'Fix: ' . mb_substr((string)$err->message, 0, 120),
            'description' => $desc, 'files' => $err->file ? [(string)$err->file] : [],
            'priority' => 2, 'depends_on' => [],
        ]],
    ];
    try {
        $r = \app\PlanIngestor::ingest($inst, $plan, (int)$inst->memberId, '', (string)($inst->app ?: 'tiknix'));
        $planId = (int)($r['parent']['id'] ?? 0);
        if (!$planId) return 0;
        $parent = R::load('workbenchtask', $planId);
        $parent->planStatus      = 'building';
        $parent->status          = 'running';
        $parent->source          = 'detected_error';
        $parent->detectederrorId = (int)$err->id;
        $parent->auditCycle      = (int)($ctx['audit_cycle'] ?? 0);   // preserve chain depth for the cap
        $parent->updatedAt       = date('Y-m-d H:i:s');
        R::store($parent);

        // Spawn the detached orchestrator (mirrors Firehose::startOrchestrator).
        // Escalate the final cap-cycle fix to opus; earlier cycles stay on sonnet.
        $model = ((int)$parent->auditCycle >= \app\AuditReporter::MAX_AUDIT_CYCLES) ? 'opus' : 'sonnet';
        $session = 'tiknix-plan' . $planId . '-orchestrator';
        if (!\app\TmuxManager::exists($session)) {
            $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/plan-orchestrate.php')
                 . ' --plan=' . $planId . ' --slug=' . escapeshellarg((string)$inst->slug)
                 . ' --dir=' . escapeshellarg($dir) . ' --model=' . $model . ' --level=' . $level;
            $ab = $dir . '/.aibuilder'; @mkdir($ab, 0775, true);
            $sf = $ab . '/run-orchestrator.sh';
            file_put_contents($sf, "#!/bin/bash\n" . $cmd . ' 2>&1 | tee ' . escapeshellarg($ab . '/orchestrator.log') . "\n");
            @chmod($sf, 0755);
            \app\TmuxManager::create($session, $sf, $dir);
        }
        $err->taskId = $planId;
        $err->status = 'building';
        R::store($err);
        alog("idle-sweep launched deferred fix plan #$planId for: " . mb_substr((string)$err->message, 0, 60));
        return $planId;
    } catch (\Throwable $e) {
        alog('idle-sweep failed: ' . $e->getMessage());
        return 0;
    }
}
