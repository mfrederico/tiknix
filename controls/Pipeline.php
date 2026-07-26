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

    /**
     * GET /pipeline/status/<run_id> — poll a run.
     *
     * Accepts a per-member pk_ key OR the instance's own trigger_secret. Both are already
     * credentials for THIS instance, and whatever triggered a run needs to be able to
     * watch it — the Publisher fires a publish with the trigger secret and would otherwise
     * have no way to report anything but a run number.
     *
     * Per-step detail is included because "what is it doing" is the actual question. A
     * publish is several steps and knowing only that the whole thing failed tells you
     * nothing about which target rejected you.
     */
    public function status($params = []) {
        if (!$this->trustedTrigger()
            && ApiKey::verify($this->bearer() ?: (string) $this->headerVal('X-Pipeline-Key')) <= 0) {
            Flight::jsonError('Invalid or missing API key.', 401); return;
        }
        $run = R::load('piperun', (int) $this->slugArg());
        if (!$run->id) { Flight::jsonError('No such run.', 404); return; }

        $steps = [];
        foreach (R::find('pipesteprun', 'run_id = ? ORDER BY id', [(int) $run->id]) as $s) {
            $steps[] = [
                'name'     => (string) $s->stepName,
                'type'     => (string) $s->stepType,
                'status'   => (string) $s->status,
                'stdout'   => (string) $s->stdout,
                'stderr'   => (string) $s->stderr,
                'exit'     => (int) $s->exitCode,
                'duration' => (int) $s->durationMs,
            ];
        }

        Flight::json(['run_id' => (int) $run->id, 'slug' => $run->slug, 'status' => $run->status,
            'steps_total' => (int) $run->stepsTotal, 'steps_done' => (int) $run->stepsDone,
            'error' => (string) $run->error, 'output' => json_decode((string) $run->outputJson, true),
            'steps' => $steps]);
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

    /**
     * POST /pipeline/varshapes/<slug> — the SHAPE (keys/types only, never raw
     * values) of the latest run's per-step outputs, so the editor's variable autocomplete can
     * offer real fields like {greet.data.shop.name} for ANY team member without re-running and
     * without broadcasting response PII. bearer = trigger_secret. Empty when the pipeline has
     * never run (the editor then falls back to static per-step-type hints). */
    public function varshapes($params = []) {
        if (!$this->trustedTrigger()) { Flight::jsonError('Forbidden.', 403); return; }
        $slug = $this->slugArg();
        $run  = R::findOne('piperun', 'slug = ? ORDER BY id DESC', [$slug]);
        $shapes = [];
        if ($run && $run->id) {
            foreach (R::find('pipesteprun', 'run_id = ? ORDER BY id', [(int) $run->id]) as $s) {
                $name = (string) $s->stepName;
                if ($name === '') continue;
                $shapes[$name] = $this->shapeOf(json_decode((string) $s->outputJson, true), 0);
            }
        }
        Flight::json(['ok' => true, 'run_id' => $run && $run->id ? (int) $run->id : 0, 'shapes' => $shapes]);
    }

    /**
     * Reduce a value to a walkable, PII-safe shape: objects keep their keys, lists expose their
     * element shape, scalars become just a type (no value). Depth/breadth capped — PII never crosses the wire.
     */
    private function shapeOf($v, int $depth) {
        if ($depth > 6) return ['t' => 'deep'];
        if (is_array($v)) {
            if ($v !== [] && array_keys($v) === range(0, count($v) - 1)) {
                return ['t' => 'array', 'n' => count($v), 'of' => $this->shapeOf($v[0], $depth + 1)];
            }
            $keys = []; $i = 0;
            foreach ($v as $k => $vv) {
                if (++$i > 60) { $keys['…'] = ['t' => 'more']; break; }
                $keys[(string) $k] = $this->shapeOf($vv, $depth + 1);
            }
            return ['t' => 'object', 'keys' => $keys];
        }
        // Type only — NO value crosses this team-shared boundary. A short sample would still
        // leak short PII (emails, names), so the autocomplete gets structure, never data. The
        // current user still sees real values in their own live debug trace.
        return ['t' => is_bool($v) ? 'bool' : (is_int($v) ? 'int' : (is_float($v) ? 'float' : (is_null($v) ? 'null' : 'string')))];
    }

    /** POST /pipeline/object/<slug>?key=<key> — deliver a message to a durable object (bearer = trigger_secret). */
    public function object($params = []) {
        if (!$this->trustedTrigger()) { Flight::jsonError('Forbidden.', 403); return; }
        $slug = $this->slugArg();
        $key  = (string) (Flight::request()->query->key ?? $this->getParam('key') ?? '');
        $trigger = ((string) (Flight::request()->query->trigger ?? 'message')) === 'alarm' ? 'alarm' : 'message';
        if ($slug === '' || $key === '') { Flight::jsonError('slug and key are required.', 400); return; }
        if (!Runner::get($slug)) { Flight::jsonError('No such pipeline.', 404); return; }
        try {
            Flight::json(Runner::deliver($slug, $key, $this->jsonBody(), $trigger));
        } catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 400); }
    }

    /** POST /pipeline/objecttick — fire onAlarm for every due durable object (bearer = trigger_secret). */
    public function objecttick($params = []) {
        if (!$this->trustedTrigger()) { Flight::jsonError('Forbidden.', 403); return; }
        try { Flight::json(Runner::objectTick()); }
        catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 400); }
    }

    /**
     * POST /pipeline/mykey — self-service: mint a REST key for the CURRENT member on
     * THIS app, revealed once. Lets an owner grab a `pk_…` to test their expose_as_api
     * pipelines without the ADMIN keys screen. The key is scoped to this workspace.
     */
    public function mykey($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $label = trim((string) $this->getParam('label')) ?: 'test key';
        $res = ApiKey::mint((int) $this->member->id, $label, (int) $this->member->id);
        Flight::jsonSuccess(['key' => $res['raw'], 'prefix' => $res['prefix']], 'Key created — copy it now; it is shown only once.');
    }

    /**
     * POST /pipeline/mintkey — mint a pk_ REST test key, authed by THIS instance's
     * [pipeline] trigger_secret (server-to-server). This is for the pipeline editor
     * sidecar, which reaches an instance over the trigger_secret (like Run/Debug) and
     * has no member session here — so it can hand the user a runnable key for the
     * instance it's editing. Body: {label, member_id} (member_id is attribution only).
     * NOT related to broker.ini — that key is for reaching connected stores.
     */
    public function mintkey($params = []) {
        if (!$this->trustedTrigger()) { Flight::jsonError('Forbidden.', 403); return; }
        $body  = $this->jsonBody();
        $label = trim((string) ($body['label'] ?? '')) ?: 'editor test key';
        $mid   = (int) ($body['member_id'] ?? 0); if ($mid <= 0) $mid = 1;
        $res   = ApiKey::mint($mid, $label, $mid);
        Flight::json(['key' => $res['raw'], 'prefix' => $res['prefix']]);
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
