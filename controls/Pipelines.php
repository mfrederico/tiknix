<?php
/**
 * Pipelines — THIS install's pipeline editor, for a signed-in ADMIN.
 *
 * Every app has its own /pipelines (COMPONENTS_PLAN.md). This is the editor that lived in
 * the pipelines.tiknix sidecar, moved in: the same spreadsheet step builder, step-trace
 * debugger and variable autocomplete, but reading and writing the pipelines of the
 * install it runs in, through the runtime it shares a process with. No instance lookup,
 * no file bridge, no trigger-secret hops — those existed only so a sidecar on core could
 * reach into a project. Here the project is the caller.
 *
 * Machine endpoints (cron, webhooks, REST keys) stay in controls/Pipeline.php (singular),
 * bearer-authenticated; both build their run views from Pipeline\Trace so they never
 * drift. Permission: pipelines::* = ADMIN (services/Schema/Seeds/02_AuthControl.php) AND
 * requireLevel() in every method — editing pipelines is editing the app's automations.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Pipeline\ApiKey;
use app\Pipeline\Loader;
use app\Pipeline\Runner;
use app\Pipeline\StepRegistry;
use app\Pipeline\Trace;

class Pipelines extends Control {

    /** GET /pipelines — the editor. */
    public function index($params = []) {
        if (!$this->gate()) return;
        $this->render('pipelines/index', [
            'title'      => 'Pipelines',
            'components' => StepRegistry::components(),
            'apiBase'    => rtrim((string) (Flight::get('app.baseurl') ?? ''), '/'),
            'memberId'   => (int) $this->member->id,
        ]);
    }

    /** GET /pipelines/list — this install's pipelines, with provenance for a concept's. */
    public function list($params = []) {
        if (!$this->gate(true)) return;
        $loader = Loader::forInstall(Runner::root());
        $out = [];
        foreach ($loader->all() as $slug => $def) {
            $row = ['slug' => (string) $slug, 'name' => (string) ($def['name'] ?? $slug),
                'steps' => count($def['steps'] ?? []), 'expose_as_tool' => (bool) ($def['expose_as_tool'] ?? false),
                'expose_as_api' => (bool) ($def['expose_as_api'] ?? false), 'cron' => (string) ($def['trigger']['cron'] ?? ''),
                'stateful' => (bool) ($def['stateful'] ?? false)];
            $origin = $loader->originOf((string) $slug);
            if ($origin !== null) $row['concept'] = $origin;
            $out[] = $row;
        }
        usort($out, fn($a, $b) => strcmp($a['slug'], $b['slug']));
        Flight::json(['pipelines' => $out]);
    }

    /** GET /pipelines/connectors — this install's connected connectors, no secrets. */
    public function connectors($params = []) {
        if (!$this->gate(true)) return;
        $rows = ConnectionStore::withOwnDb(function () {
            $out = [];
            foreach (Bean::find('connections', 'enabled = 1 ORDER BY connector_type, environment') as $c) {
                if (!$c->id || !empty($c->revokedAt)) continue;
                $type  = (string) $c->connectorType;
                $style = $type === 'shopify' ? 'graphql' : 'rest';
                $out[] = ['connector' => $type, 'environment' => (string) $c->environment,
                    'name' => (string) ($c->externalName ?: $type), 'style' => $style,
                    'tool' => $style === 'graphql' ? 'graphql' : 'request'];
            }
            return $out;
        }, null);
        if ($rows === null) {
            // Distinguishable from "no connections": the store could not be read.
            error_log('ERROR Pipelines::connectors: this install\'s connection store could not be read (data/connections.db)');
            Flight::jsonError('The connection store could not be read; see the log.', 500);
            return;
        }
        Flight::json(['connectors' => $rows]);
    }

    /** GET /pipelines/get?slug=<pipe> — one definition. */
    public function get($params = []) {
        if (!$this->gate(true)) return;
        $slug = (string) Flight::request()->query->slug;
        $def = Loader::forInstall(Runner::root())->get($slug);
        if (!$def) { Flight::jsonError('Pipeline not found.', 404); return; }
        $origin = Loader::forInstall(Runner::root())->originOf($slug);
        Flight::json(['def' => $def, 'concept' => $origin]);
    }

    /** POST /pipelines/validate — dry-validate a definition. */
    public function validate($params = []) {
        if (!$this->gate(true) || !$this->csrf()) return;
        $def = $this->bodyDef();
        if ($def === null) { Flight::jsonError('Invalid JSON.', 400); return; }
        Flight::json(['valid' => !($e = Loader::validate($def)), 'errors' => $e]);
    }

    /** POST /pipelines/save — validate + write the file (a concept's pipeline goes to the concept's file). */
    public function save($params = []) {
        if (!$this->gate(true) || !$this->csrf()) return;
        $def = $this->bodyDef();
        if ($def === null) { Flight::jsonError('Invalid JSON.', 400); return; }
        $errs = Loader::validate($def);
        if ($errs) { Flight::json(['ok' => false, 'errors' => $errs]); return; }
        $file = Loader::forInstall(Runner::root())->save($def);
        $this->logger->info('Pipeline saved', ['slug' => $def['slug'], 'file' => $file, 'member_id' => $this->member->id]);
        Flight::json(['ok' => true, 'slug' => $def['slug'], 'file' => substr($file, strlen(Runner::root()) + 1)]);
    }

    /** POST /pipelines/delete — remove the install's own file; a concept's is refused with the fix. */
    public function delete($params = []) {
        if (!$this->gate(true) || !$this->csrf()) return;
        $slug = (string) $this->getParam('slug');
        try {
            $ok = Loader::forInstall(Runner::root())->delete($slug);
        } catch (\RuntimeException $e) {
            Flight::jsonError($e->getMessage(), 409);
            return;
        }
        if ($ok) $this->logger->info('Pipeline deleted', ['slug' => $slug, 'member_id' => $this->member->id]);
        Flight::json(['ok' => $ok]);
    }

    /** POST /pipelines/run — dispatch a run; returns run_id to watch. */
    public function run($params = []) {
        if (!$this->gate(true) || !$this->csrf()) return;
        $slug = (string) $this->getParam('slug');
        $context = json_decode((string) $this->getParam('context'), true) ?: [];
        try {
            $r = Runner::dispatch($slug, is_array($context) ? $context : [], 'editor');
        } catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 400); return; }
        Flight::json(['run_id' => (int) ($r['run_id'] ?? 0), 'status' => (string) ($r['status'] ?? '')]);
    }

    /** GET /pipelines/runstatus?run_id=<id> — poll a run. */
    public function runstatus($params = []) {
        if (!$this->gate(true)) return;
        $r = Trace::runStatus((int) Flight::request()->query->run_id);
        if (!$r) { Flight::jsonError('Run not found yet.', 404); return; }
        Flight::json($r);
    }

    /** POST /pipelines/mintkey — a pk_ REST key for the caller, shown once. */
    public function mintkey($params = []) {
        if (!$this->gate(true) || !$this->csrf()) return;
        $label = trim((string) $this->getParam('label')) ?: ('editor · ' . date('m-d H:i'));
        $res = ApiKey::mint((int) $this->member->id, $label, (int) $this->member->id);
        Flight::json(['key' => $res['raw'], 'label' => $label]);
    }

    /** POST /pipelines/deliver — a message (or alarm) to a durable object. */
    public function deliver($params = []) {
        if (!$this->gate(true) || !$this->csrf()) return;
        $slug    = (string) $this->getParam('slug');
        $key     = (string) $this->getParam('key');
        $trigger = ((string) $this->getParam('trigger')) === 'alarm' ? 'alarm' : 'message';
        $message = json_decode((string) $this->getParam('message'), true);
        if ($key === '') { Flight::jsonError('An object key is required.', 400); return; }
        try {
            $res = Runner::deliver($slug, $key, is_array($message) ? $message : [], $trigger);
        } catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 400); return; }
        Flight::json($res + ['key' => $key, 'trigger' => $trigger]);
    }

    /** POST /pipelines/debug — start a step-trace debug run; returns the first breakpoint. */
    public function debug($params = []) {
        if (!$this->gate(true) || !$this->csrf()) return;
        $slug = (string) $this->getParam('slug');
        $context = json_decode((string) $this->getParam('context'), true) ?: [];
        if (!Runner::get($slug)) { Flight::jsonError('No such pipeline.', 404); return; }
        try {
            $r = Runner::debugRun($slug, is_array($context) ? $context : []);
            Flight::json(Trace::breakpoint((int) $r['run_id'], $r));
        } catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 400); }
    }

    /** POST /pipelines/debugstep — advance / run to end / abort a debug run (patch = injected data). */
    public function debugstep($params = []) {
        if (!$this->gate(true) || !$this->csrf()) return;
        $runId  = (int) $this->getParam('run_id');
        $action = (string) ($this->getParam('action') ?: 'step');
        $patch  = json_decode((string) $this->getParam('patch'), true);
        $patch  = is_array($patch) ? $patch : [];
        try {
            if ($action === 'abort')    { $r = Runner::debugAbort($runId); }
            elseif ($action === 'end')  { $r = Runner::debugContinueToEnd($runId, $patch); }
            else                        { $r = Runner::debugStep($runId, $patch); }
            Flight::json(Trace::breakpoint($runId, $r));
        } catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 400); }
    }

    /** GET /pipelines/varshapes?slug=<pipe> — keys/types of the latest run's outputs, for autocomplete. */
    public function varshapes($params = []) {
        if (!$this->gate(true)) return;
        Flight::json(Trace::varShapes((string) $this->getParam('slug')));
    }

    // ---- guards ------------------------------------------------------------

    /** Signed in and ADMIN. JSON callers get a JSON refusal instead of a redirect. */
    private function gate(bool $json = false): bool {
        if (!Flight::isLoggedIn()) {
            if ($json) { Flight::jsonError('Not signed in.', 401); return false; }
            Flight::redirect('/auth/login?redirect=' . urlencode('/pipelines'));
            return false;
        }
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            if ($json) { Flight::jsonError('Pipelines are edited by administrators.', 403); return false; }
            $this->flash('error', 'Pipelines are edited by administrators.');
            Flight::redirect('/dashboard');
            return false;
        }
        return true;
    }

    private function csrf(): bool {
        if (!SimpleCsrf::validateRequest()) { Flight::jsonError('Security validation failed; reload the page.', 403); return false; }
        return true;
    }

    private function bodyDef(): ?array {
        $d = json_decode((string) $this->getParam('def'), true);
        return is_array($d) ? $d : null;
    }
}
