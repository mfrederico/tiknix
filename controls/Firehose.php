<?php
/**
 * Firehose — control-plane error ingest for AI Builder instances.
 *
 * Instances POST uncaught errors here (see lib/ErrorReporter.php). We dedup by
 * signature and, for a NEW error on a published + idle instance, auto-triage it
 * into a fix workspace (Phase 3). The feed UI is Phase 4.
 *
 * Security — two layers, mirroring controls/Mcp.php:
 *   1. Route: firehose::report = 101 (PUBLIC) so instances can reach it.
 *   2. Controller: a shared secret ([firehose] ingest_key) validated per request.
 * Only the CONTROL PLANE sets ingest_key, so an instance clone of this code
 * (which has api_key but no ingest_key) rejects every report — it can't be
 * tricked into ingesting into its own DB.
 *
 * Collision safety (see lib/ErrorReporter.php for the full picture):
 *   Layer 1 origin gate — instances only report when role=live (workspaces muted).
 *   Layer 2 active-build guard + Layer 3 signature dedup — enforced here.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use app\BaseControls\Control;

class Firehose extends Control {

    public function __construct() {
        parent::__construct();
    }

    /** GET /firehose — admin feed of detected errors (newest first, 'new' on top). */
    public function index() {
        if (!$this->requireLogin()) return;
        $this->requireBuilderTools('Firehose');
        if ($this->member->level > LEVELS['ADMIN']) {
            $this->flash('error', 'The error firehose is admin-only.');
            Flight::redirect('/dashboard');
            return;
        }

        $rows = Bean::find('detectederror',
            "ORDER BY CASE WHEN status = 'new' THEN 0 ELSE 1 END, last_seen_at DESC LIMIT 300");
        $errors = [];
        foreach ($rows as $e) {
            $task = $e->taskId ? Bean::load('workbenchtask', (int)$e->taskId) : null;
            $errors[] = ['e' => $e, 'task' => ($task && $task->id) ? $task : null];
        }

        $this->viewData['title']  = 'Error Firehose';
        $this->viewData['errors'] = $errors;
        $this->viewData['counts'] = [
            'new'      => (int)Bean::count('detectederror', "status = 'new'"),
            'open'     => (int)Bean::count('detectederror', "status IN ('new','triaged','building','reopened','deferred')"),
            'resolved' => (int)Bean::count('detectederror', "status = 'resolved'"),
        ];
        $this->render('firehose/index', $this->viewData);
    }

    /** POST /firehose/resolve — admin sets a detected error's status (resolved/ignored/new). */
    public function resolve() {
        if (!$this->requireLogin()) return;
        if (Flight::request()->method !== 'POST') { Flight::redirect('/firehose'); return; }
        if (!\app\SimpleCsrf::validate()) { Flight::jsonError('CSRF validation failed', 403); return; }
        if ($this->member->level > LEVELS['ADMIN']) { Flight::jsonError('Admins only', 403); return; }

        $id     = (int)$this->getParam('id');
        $status = (string)$this->getParam('status', 'resolved');
        if (!in_array($status, ['resolved', 'ignored', 'new'], true)) { Flight::jsonError('bad status', 422); return; }

        $e = Bean::load('detectederror', $id);
        if (!$e->id) { Flight::jsonError('not found', 404); return; }
        $e->status    = $status;
        $e->updatedAt = date('Y-m-d H:i:s');
        Bean::store($e);
        Flight::jsonSuccess(['id' => $id, 'status' => $status]);
    }

    /** POST /firehose/report — JSON error ingest. Self-authed by shared key. */
    public function report() {
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) { Flight::jsonError('invalid payload', 400); return; }

        // Layer-2 auth: shared secret, header preferred, body fallback.
        $provided = $_SERVER['HTTP_X_FIREHOSE_KEY'] ?? ($data['api_key'] ?? '');
        $expected = (string)(Flight::get('firehose.ingest_key') ?? '');
        if ($expected === '' || !hash_equals($expected, (string)$provided)) {
            Flight::jsonError('unauthorized', 401);
            return;
        }

        $sig      = trim((string)($data['signature'] ?? ''));
        $instance = trim((string)($data['instance'] ?? ''));
        if ($sig === '' || $instance === '') {
            Flight::jsonError('missing signature or instance', 422);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $err = Bean::findOne('detectederror', 'signature = ?', [$sig]);

        if ($err && $err->id) {
            // Known signature — bump counters, never duplicate (Layer 3).
            $err->hitCount   = (int)$err->hitCount + 1;
            $err->lastSeenAt = $now;
            // Regression: a previously resolved/ignored error is firing again.
            if (in_array($err->status, ['resolved', 'ignored'], true)) {
                $err->status = 'reopened';
            }
            Bean::store($err);
            Flight::jsonSuccess([
                'id' => (int)$err->id, 'status' => $err->status,
                'hits' => (int)$err->hitCount, 'new' => false,
            ], 'recorded');
            return;
        }

        // New signature — record it.
        $err = Bean::dispense('detectederror');
        $err->signature   = $sig;
        $err->instanceTag = $instance;
        $err->type        = (string)($data['type'] ?? 'exception');
        $err->message     = mb_substr((string)($data['message'] ?? ''), 0, 500);
        $err->fullMessage = mb_substr((string)($data['full_message'] ?? ''), 0, 2000);
        $err->klass       = mb_substr((string)($data['class'] ?? ''), 0, 200);
        $err->file        = mb_substr((string)($data['file'] ?? ''), 0, 500);
        $err->line        = (int)($data['line'] ?? 0);
        $err->trace       = mb_substr((string)($data['trace'] ?? ''), 0, 8000);
        $err->url         = mb_substr((string)($data['url'] ?? ''), 0, 500);
        $err->httpMethod  = mb_substr((string)($data['http_method'] ?? ''), 0, 12);
        $err->context     = json_encode($data['context'] ?? []);
        $err->hitCount    = 1;
        $err->status      = 'new';
        $err->taskId      = 0;
        $err->firstSeenAt = $now;
        $err->lastSeenAt  = $now;
        $err->createdAt   = $now;
        Bean::store($err);

        // Phase 3 hooks auto-triage here.
        $triage = $this->autoTriage($err);

        Flight::jsonSuccess([
            'id' => (int)$err->id, 'status' => $err->status,
            'new' => true, 'triage' => $triage,
        ], 'recorded');
    }

    /**
     * Auto-triage a newly-detected error: create a highlighted workbench task
     * against the reporting instance, guarded so it never collides with an agent
     * already on the repo. Returns a small status array for the ingest response.
     */
    private function autoTriage($err): array {
        // Control-plane only: an instance clone of this code must never create tasks.
        if (function_exists('is_control_plane') && !is_control_plane()) {
            return ['action' => 'skipped', 'reason' => 'not control plane'];
        }

        // Resolve the reporting instance. Reported tag is "<slug>.tiknix".
        $tag  = (string)$err->instanceTag;
        $slug = preg_replace('/\.[^.]+$/', '', $tag);   // bidsurge.tiknix -> bidsurge
        $inst = Bean::findOne('instance', 'slug = ?', [$slug]);
        if (!$inst || !$inst->id) {
            $err->status = 'unmatched';
            Bean::store($err);
            return ['action' => 'skipped', 'reason' => 'no matching instance'];
        }

        // Layer 2 — active-build guard: if an agent is already working this
        // instance's repo, do NOT spawn a fix. Defer; the feed shows it and it
        // can be launched once the instance goes idle.
        if ($this->instanceHasActiveBuild($tag)) {
            $err->status = 'deferred';
            Bean::store($err);
            return ['action' => 'deferred', 'reason' => 'instance has an active build'];
        }

        // Auto-launch is per-instance opt-in (instance.auto_triage). When on, run
        // the fix through the existing headless plan orchestrator (worktree +
        // merge-back + auto-retry). When off, create a highlighted task the human
        // can Run. Layer 3 dedup already guarantees this fires once per signature.
        if (filter_var($inst->autoTriage ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $planId = $this->launchViaOrchestrator($err, $inst);
            if ($planId) {
                $err->taskId = $planId;
                $err->status = 'building';
                Bean::store($err);
                return ['action' => 'launched', 'plan_id' => $planId];
            }
            // Launch failed — fall through to a plain triage task so nothing is lost.
        }

        $task = $this->createTriageTask($err, $inst, $tag);
        $err->taskId = (int)$task->id;
        $err->status = 'triaged';
        Bean::store($err);
        return ['action' => 'task_created', 'task_id' => (int)$task->id];
    }

    /** A standalone highlighted triage task (used when auto-launch is off/failed). */
    private function createTriageTask($err, $inst, string $tag) {
        $now  = date('Y-m-d H:i:s');
        $task = Bean::dispense('workbenchtask');
        $task->title           = 'Fix: ' . mb_substr((string)$err->message, 0, 120);
        $task->description     = $this->triageBrief($err);
        $task->taskType        = 'bug';
        $task->priority        = 2;
        $task->status          = 'pending';
        $task->memberId        = (int)$inst->memberId;
        $task->instanceId      = (int)$inst->id;
        $task->instanceTag     = $tag;
        $task->baseBranch      = '';               // resolves to instance/<slug> at run time
        $task->authcontrolLevel= 1;
        $task->source          = 'detected_error'; // powers the workbench highlight
        $task->detectederrorId = (int)$err->id;
        $task->auditCycle      = (int)(json_decode((string)$err->context, true)['audit_cycle'] ?? 0);
        $task->createdAt       = $now;
        $task->updatedAt       = $now;
        Bean::store($task);
        return $task;
    }

    /** Layer 2: is an agent currently building/running against this instance? */
    private function instanceHasActiveBuild(string $tag): bool {
        return (int)Bean::count(
            'workbenchtask',
            "instance_tag = ? AND status IN ('running', 'building')",
            [$tag]
        ) > 0;
    }

    /** Markdown brief handed to the fix agent (and shown in the task view). */
    private function triageBrief($err): string {
        $ctx = json_decode((string)$err->context, true) ?: [];
        $l   = [];
        $l[] = '**Auto-detected error** on `' . $err->instanceTag . '` — captured by the firehose.';
        $l[] = '';
        $l[] = '- **Type:** ' . $err->type;
        $l[] = '- **Message:** ' . $err->message;
        if (!empty($err->klass)) $l[] = '- **Class:** `' . $err->klass . '`';
        $l[] = '- **Location:** `' . $err->file . ':' . $err->line . '`';
        if (!empty($err->url)) $l[] = '- **Request:** `' . $err->httpMethod . ' ' . $err->url . '`';
        if (!empty($ctx['controller'])) {
            $l[] = '- **Controller:** `' . $ctx['controller'] . '->' . ($ctx['method'] ?? '') . '`';
        }
        $l[] = '- **Seen:** ' . (int)$err->hitCount . '× (first ' . $err->firstSeenAt . ')';
        $l[] = '';
        $l[] = '### Full message';
        $l[] = '```';
        $l[] = (string)$err->fullMessage;
        $l[] = '```';
        $l[] = '';
        $l[] = '### Stack trace';
        $l[] = '```';
        $l[] = (string)$err->trace;
        $l[] = '```';
        $l[] = '';
        $l[] = '**Goal:** reproduce, find the root cause at the location above, fix it, and verify the page/endpoint works.';

        // Visual evidence from the audit that surfaced this failure. Rendered as
        // inline images (MarkdownParser supports ![](url)); the agent reads them as
        // markdown pointers to the exact broken screen. Kept OUTSIDE the code fences
        // above so they display rather than being escaped.
        $shots = $ctx['screens'] ?? [];
        if (is_array($shots) && $shots) {
            $l[] = '';
            $l[] = '### Screenshots from the failing audit';
            $l[] = '_Visual proof of the defect — reproduce against these._';
            foreach (array_slice($shots, 0, 6) as $u) {
                if (is_string($u) && preg_match('#^https?://#i', $u)) $l[] = '![screenshot](' . $u . ')';
            }
        }
        return implode("\n", $l);
    }

    /**
     * Auto-launch the fix by wrapping the detected error as a 1-task plan and
     * running it through the existing headless orchestrator (worktree off
     * instance/<slug> + merge-back + auto-retry). Returns the plan id, or 0 on
     * failure (caller falls back to a plain triage task).
     */
    private function launchViaOrchestrator($err, $inst): int {
        try {
            $app  = $inst->app ?: 'tiknix';
            $plan = [
                'title'    => 'Fix: ' . mb_substr((string)$err->message, 0, 120),
                'summary'  => 'Auto-triaged from a detected runtime error on ' . $err->instanceTag . '.',
                'subtasks' => [[
                    'id'          => 't1',
                    'title'       => 'Fix: ' . mb_substr((string)$err->message, 0, 120),
                    'description' => $this->triageBrief($err),
                    'files'       => $err->file ? [(string)$err->file] : [],
                    'priority'    => 2,
                    'depends_on'  => [],
                ]],
            ];
            $res    = \app\PlanIngestor::ingest($inst, $plan, (int)$inst->memberId, '', $app);
            $planId = (int)($res['parent']['id'] ?? 0);
            if (!$planId) return 0;

            // Mark the plan building (mirrors Workbench::planbuild) + tag it as
            // detected-error so the workbench can highlight it.
            $parent = Bean::load('workbenchtask', $planId);
            $parent->planStatus      = 'building';
            $parent->status          = 'running';
            $parent->source          = 'detected_error';
            $parent->detectederrorId = (int)$err->id;
            // Inherit the audit-chain depth so this fix's own post-build audit knows
            // how deep it is and the audit->fix loop terminates (see AuditReporter).
            $parent->auditCycle      = (int)(json_decode((string)$err->context, true)['audit_cycle'] ?? 0);
            $parent->updatedAt       = date('Y-m-d H:i:s');
            Bean::store($parent);

            $level = (int)((Bean::load('member', (int)$inst->memberId)->level) ?: 1);
            return $this->startOrchestrator($planId, $inst, $level) ? $planId : 0;
        } catch (\Throwable $e) {
            $this->logger->error('Firehose auto-launch failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Launch the detached worktree orchestrator for a plan (headless mirror of Workbench). */
    private function startOrchestrator(int $planId, $inst, int $level): bool {
        $app = $inst->app ?: 'tiknix';
        $dir = '/var/www/html/default/' . $inst->slug . '.' . $app;
        if (\app\TmuxManager::exists('tiknix-plan' . $planId . '-orchestrator')) return true;
        // Escalate the FINAL cap-cycle fix to a stronger model. The audit->fix loop
        // gets MAX_AUDIT_CYCLES attempts; the last one is the last auto-shot before a
        // human takes over, so give it opus (the earlier, cheaper cycles stay sonnet).
        $auditCycle = (int)(Bean::load('workbenchtask', $planId)->auditCycle ?? 0);
        $model = ($auditCycle >= \app\AuditReporter::MAX_AUDIT_CYCLES) ? 'opus' : 'sonnet';
        $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/plan-orchestrate.php')
             . ' --plan=' . $planId
             . ' --slug=' . escapeshellarg((string)$inst->slug)
             . ' --dir='  . escapeshellarg($dir)
             . ' --model=' . $model
             . ' --level=' . $level;
        $ab = $dir . '/.aibuilder';
        @mkdir($ab, 0775, true);
        $scriptFile = $ab . '/run-orchestrator.sh';
        file_put_contents($scriptFile, "#!/bin/bash\n" . $cmd . ' 2>&1 | tee ' . escapeshellarg($ab . '/orchestrator.log') . "\n");
        @chmod($scriptFile, 0755);
        return \app\TmuxManager::create('tiknix-plan' . $planId . '-orchestrator', $scriptFile, $dir);
    }
}
