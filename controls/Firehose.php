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
     * Auto-triage a newly-detected error (Phase 3 fills this in). Returns a
     * small status array for the ingest response.
     */
    private function autoTriage($err): array {
        return ['action' => 'none'];
    }
}
