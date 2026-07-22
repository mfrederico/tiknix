<?php
/**
 * Pipeline — the instance-side run surfaces for pipelines (part of the code):
 *   POST /pipeline/api/<slug>       — REST API (per-member pk_ key); sync by default,
 *                                     ?async=1 to dispatch + poll. expose_as_api only.
 *   POST /pipeline/trigger/<slug>   — cron/webhook trigger (bearer = the instance's
 *                                     [pipeline] trigger_secret); always dispatched.
 *   GET  /pipeline/status/<run_id>  — poll a run (per-member key).
 *   GET|POST /pipeline/keys         — ADMIN key management UI (mint/revoke).
 *
 * authcontrol: pipeline/api, pipeline/trigger, pipeline/status = 101 (self-authenticating);
 * pipeline/keys = 50 (ADMIN). Definitions come from Runner (the instance's files).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Pipeline\Runner;
use app\Pipeline\ApiKey;
use RedBeanPHP\R;

class Pipeline extends Control {

    /** POST /pipeline/api/<slug> — run an expose_as_api pipeline as the key's member. */
    public function api($params = []) {
        $slug = $this->slugArg();
        $def  = Runner::get($slug);
        if (!$def || empty($def['expose_as_api'])) { Flight::jsonError('No such API.', 404); return; }

        $memberId = ApiKey::verify($this->bearer() ?: (string) $this->headerVal('X-Pipeline-Key'));
        if ($memberId <= 0) { Flight::jsonError('Invalid or missing API key.', 401); return; }

        $context = $this->jsonBody();
        try {
            if ($this->truthy($this->getParam('async'))) {
                $r = Runner::dispatch($slug, $context, 'api:' . $memberId);
                Flight::json(['run_id' => $r['run_id'], 'status' => 'queued',
                    'status_url' => '/pipeline/status/' . $r['run_id']]);
            } else {
                $r = Runner::run($slug, $context, 'api:' . $memberId);
                Flight::json(['run_id' => $r['run_id'], 'status' => $r['status'], 'output' => $r['output'], 'error' => $r['error']]);
            }
        } catch (\Throwable $e) {
            Flight::jsonError($e->getMessage(), 400);
        }
    }

    /** POST /pipeline/trigger/<slug> — cron/webhook fire (bearer = trigger_secret). */
    public function trigger($params = []) {
        $secret = (string) (Flight::get('pipeline.trigger_secret') ?? '');
        if ($secret === '' || !hash_equals($secret, $this->bearer())) { Flight::jsonError('Forbidden.', 403); return; }
        $slug = $this->slugArg();
        $def  = Runner::get($slug);
        if (!$def) { Flight::jsonError('No such pipeline.', 404); return; }
        try {
            $r = Runner::dispatch($slug, $this->jsonBody(), 'trigger');
            Flight::json(['run_id' => $r['run_id'], 'status' => 'queued']);
        } catch (\Throwable $e) {
            Flight::jsonError($e->getMessage(), 400);
        }
    }

    /** GET /pipeline/status/<run_id> — poll a run (per-member key). */
    public function status($params = []) {
        if (ApiKey::verify($this->bearer() ?: (string) $this->headerVal('X-Pipeline-Key')) <= 0) {
            Flight::jsonError('Invalid or missing API key.', 401); return;
        }
        $run = R::load('piperun', (int) $this->slugArg());
        if (!$run->id) { Flight::jsonError('No such run.', 404); return; }
        Flight::json(['run_id' => (int) $run->id, 'slug' => $run->slug, 'status' => $run->status,
            'steps_total' => (int) $run->stepsTotal, 'steps_done' => (int) $run->stepsDone,
            'error' => (string) $run->error, 'output' => json_decode((string) $run->outputJson, true)]);
    }

    /** POST /pipeline/debug/<slug> — start a step-trace debug run (bearer = trigger_secret). */
    public function debug($params = []) {
        if (!$this->trustedTrigger()) { Flight::jsonError('Forbidden.', 403); return; }
        $slug = $this->slugArg();
        if (!Runner::get($slug)) { Flight::jsonError('No such pipeline.', 404); return; }
        try {
            $r = Runner::debugRun($slug, $this->jsonBody());
            Flight::json($this->breakpoint((int) $r['run_id'], $r));
        } catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 400); }
    }

    /** POST /pipeline/debugstep/<run_id> — advance/finish/abort a debug run (bearer = trigger_secret).
     *  Body: { action: "step"|"end"|"abort", patch: {..} } — patch is deep-merged into the bag. */
    public function debugstep($params = []) {
        if (!$this->trustedTrigger()) { Flight::jsonError('Forbidden.', 403); return; }
        $runId  = (int) $this->slugArg();
        $body   = $this->jsonBody();
        $action = (string) ($body['action'] ?? 'step');
        $patch  = is_array($body['patch'] ?? null) ? $body['patch'] : [];
        try {
            if ($action === 'abort')    { $r = Runner::debugAbort($runId); }
            elseif ($action === 'end')  { $r = Runner::debugContinueToEnd($runId, $patch); }
            else                        { $r = Runner::debugStep($runId, $patch); }
            Flight::json($this->breakpoint($runId, $r));
        } catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 400); }
    }

    /** GET|POST /pipeline/keys — ADMIN mint/revoke per-member REST keys. */
    public function keys($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) { Flight::redirect('/dashboard'); return; }

        $minted = null;
        if (Flight::request()->method === 'POST') {
            if (!$this->validateCSRF()) return;
            $action = (string) $this->getParam('action');
            if ($action === 'mint') {
                $memberId = (int) $this->getParam('member_id');
                if ($memberId > 0 && R::load('member', $memberId)->id) {
                    $minted = ApiKey::mint($memberId, (string) $this->getParam('label'), (int) $this->member->id);
                }
            } elseif ($action === 'revoke') {
                ApiKey::revoke((int) $this->getParam('id'));
                Flight::redirect('/pipeline/keys');
                return;
            }
        }
        $this->render('pipeline/keys', [
            'title'   => 'Pipeline API keys',
            'keys'    => ApiKey::all(),
            'members' => R::getAll('SELECT id, COALESCE(display_name, username, email) AS name FROM member ORDER BY id'),
            'minted'  => $minted,
        ]);
    }

    // ---- helpers -----------------------------------------------------------

    /** True if the request carries the instance's [pipeline] trigger_secret. */
    private function trustedTrigger(): bool {
        $secret = (string) (Flight::get('pipeline.trigger_secret') ?? '');
        return $secret !== '' && hash_equals($secret, $this->bearer());
    }

    /**
     * Assemble a debugger breakpoint payload: run status, each step-run (with its
     * RESOLVED input + output/stdout/stderr), the live variable bag (so the UI can
     * show + inject data), and which step ran last / runs next.
     */
    private function breakpoint(int $runId, array $r): array {
        $run = R::load('piperun', $runId);
        $steps = [];
        foreach (R::find('pipesteprun', 'run_id = ? ORDER BY id', [$runId]) as $s) {
            $steps[] = ['step' => $s->stepName, 'type' => $s->stepType, 'status' => $s->status,
                'input' => json_decode((string) $s->inputJson, true), 'output' => json_decode((string) $s->outputJson, true),
                'stdout' => (string) $s->stdout, 'stderr' => (string) $s->stderr,
                'exit' => (int) $s->exitCode, 'duration_ms' => (int) $s->durationMs];
        }
        $state = json_decode((string) $run->stateJson, true) ?: [];
        return [
            'run_id'      => $runId,
            'status'      => (string) $run->status,
            'debug'       => ($state['kind'] ?? '') === 'debug' && $run->status === 'paused',
            'steps_total' => (int) $run->stepsTotal,
            'steps_done'  => (int) $run->stepsDone,
            'error'       => (string) $run->error,
            'output'      => json_decode((string) $run->outputJson, true),
            'last_step'   => $r['last_step'] ?? ($state['last'] ?? null),
            'next_step'   => $r['next_step'] ?? null,
            'bag'         => $run->status === 'paused' ? ($state['bag'] ?? null) : null,
            'steps'       => $steps,
        ];
    }

    /** The trailing URL segment (slug or run id) via the auto-router op param. */
    private function slugArg(): string {
        $op = $this->routeParams['operation'] ?? null;
        return is_object($op) ? (string) ($op->name ?? '') : '';
    }

    private function jsonBody(): array {
        $raw = (string) (Flight::request()->getBody() ?: file_get_contents('php://input'));
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private function bearer(): string {
        $h = (string) $this->headerVal('Authorization');
        return stripos($h, 'bearer ') === 0 ? trim(substr($h, 7)) : '';
    }

    private function headerVal(string $name): string {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        foreach ($headers as $k => $v) if (strcasecmp($k, $name) === 0) return (string) $v;
        $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$server] ?? '');
    }

    private function truthy($v): bool {
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}
