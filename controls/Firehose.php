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

        // Create the highlighted triage task (Layer 3 dedup already guaranteed one
        // detectederror per signature, so this fires at most once per bug).
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
        $task->source          = 'detected_error'; // powers the workbench highlight (Phase 4)
        $task->detectederrorId = (int)$err->id;
        $task->createdAt       = $now;
        $task->updatedAt       = $now;
        Bean::store($task);

        $err->taskId = (int)$task->id;
        $err->status = 'triaged';
        Bean::store($err);

        // Auto-launch the fix is per-instance opt-in (instance.auto_triage). When
        // off, the task waits in the feed for a human to click Run.
        $launched = false;
        if (filter_var($inst->autoTriage ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $launched = $this->launchFix($task, $inst);
            if ($launched) {
                $err->status = 'building';
                Bean::store($err);
            }
        }

        return [
            'action'  => $launched ? 'launched' : 'task_created',
            'task_id' => (int)$task->id,
        ];
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
        return implode("\n", $l);
    }

    /**
     * Auto-launch the fix agent for a triage task (Phase 3b). Returns true when a
     * build was actually started. Implemented via the headless task launcher.
     */
    private function launchFix($task, $inst): bool {
        return false;
    }
}
