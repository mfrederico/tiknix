<?php
/**
 * Brokerinfo — what a project's app asks CORE for, with its own broker key.
 *
 * An app authenticates with its OWN broker key (conf/broker.ini, the `brk_` capability) and
 * the project it speaks for comes from the KEY, never from the request:
 *
 *   connectintent                 begin an OAuth connect: core holds the provider's client
 *                                 secret and runs the sign-in, then hands the credential to the app
 *   modelconnections / modelcall  a pipeline step runs on the project OWNER's model connection;
 *   / modelresult                 the key to that model stays here
 *   support                       the app's AI agent escalates to Tiknix support
 *
 * It used to answer "what am I connected to?", list connectors, take a pasted key and
 * disconnect — from when an app's credentials lived on core. An app holds its own connections
 * now and core asks IT (Connectorapi, ConnectorPush), so those are gone, and so is the door
 * that handed a decrypted credential to a sidecar: nothing called any of them.
 *
 * authcontrol: brokerinfo::<method> = 101 (services/Schema/Seeds/25_ControlPlaneRoutes.php) —
 * reachable, and every method authenticates the broker key itself.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\services\connectors\ConnectorRegistry;

class Brokerinfo extends Control {

    /**
     * POST /brokerinfo/connectintent — begin an OAuth connect, driven by the instance.
     * Body: {connector, environment, shop, return_url}. Mints a short-lived signed
     * intent (identity = the KEY's instance/owner, never caller-supplied) and returns a
     * one-time handoff URL on core. The browser follows it; core runs the OAuth with ITS
     * client secret and stores the credential tagged to this instance, then returns to
     * return_url. The instance never touches a token.
     */
    public function connectintent($params = []) {
        [$key, $iid, $mid] = $this->requireBroker(); if (!$key) return;
        $body = $this->jsonBody();
        $type = strtolower(trim((string) ($body['connector'] ?? '')));
        $env  = $this->env((string) ($body['environment'] ?? 'production'));
        $shop = trim((string) ($body['shop'] ?? ''));

        $connector = ConnectorRegistry::get($type);
        if (!$connector) { Flight::jsonError('Unknown connector: ' . $type, 400); return; }
        if (($connector->meta()['auth_type'] ?? 'oauth') !== 'oauth') {
            Flight::jsonError(ucfirst($type) . ' connects with a pasted key, not a sign-in.', 400); return;
        }
        if (!$connector->isConfigured()) {
            Flight::jsonError(ucfirst($type) . ' is not configured on this server.', 400); return;
        }
        // return_url must be https on THIS instance's OWN host — no open redirect.
        $returnUrl = $this->safeInstanceUrl((string) ($body['return_url'] ?? ''), $iid);
        if ($returnUrl === '') { Flight::jsonError('return_url must be an https URL on this instance.', 400); return; }

        $intent = OAuthStateService::issue([
            'purpose'     => 'connect_handoff',
            'provider'    => $type,
            'instance_id' => $iid,
            'member_id'   => $mid,
            'environment' => $env,
            'shop'        => $shop,
            'return_url'  => $returnUrl,
        ]);
        $base = app_url();
        Flight::jsonSuccess(['url' => $base . '/connections/handoff/' . rawurlencode($type) . '?intent=' . urlencode($intent)]);
    }

    /** Return $url only if it's https on the instance's OWN configured host, else ''. */
    private function safeInstanceUrl(string $url, int $instanceId): string {
        $inst = Bean::load('instance', $instanceId);
        if (!$inst->id) return '';
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return '';
        if (\Model_Instance::tenantRow($inst)) {
            $expected = strtolower(trim((string) $inst->ctDomain));
        } else {
            $ini = @parse_ini_file($inst->dir() . '/conf/config.ini', true) ?: [];
            $expected = strtolower((string) parse_url((string) ($ini['app']['baseurl'] ?? ''), PHP_URL_HOST));
        }
        if ($expected === '' || strtolower((string) $p['host']) !== $expected) return '';
        return $url;
    }

    /** Resolve + gate the broker key. Returns [keyBean, instanceId, memberId] or nulls (after sending the error). */
    private function requireBroker(): array {
        $key = $this->brokerKey();
        if (!$key) { Flight::jsonError('Forbidden.', 403); return [null, 0, 0]; }
        $iid = (int) ($key->instanceId ?? 0);
        if ($iid <= 0) { Flight::jsonError('This broker key is not bound to an instance.', 403); return [null, 0, 0]; }
        return [$key, $iid, (int) ($key->memberId ?? 0)];
    }

    /**
     * GET|POST /brokerinfo/qa — where this project's QA Testing is, for its own pipelines
     * (the runtime's `qa` step runs a suite there with this same key). Answered only when the
     * project's OWNER has QA Testing switched on; the owner is read from the registry.
     */
    public function qa($params = []) {
        [$key, $iid] = $this->requireBroker(); if (!$key) return;
        $inst = Bean::load('instance', $iid);
        if (!$inst->id) { Flight::jsonError('That project is gone.', 404); return; }
        if (!Feature::isEnabled('qa', (int) $inst->memberId)) { Flight::jsonError("QA Testing is not switched on for this project's owner.", 403); return; }
        $url = rtrim((string) ((\app\Sidecar\Registry::get('qa') ?? [])['url'] ?? ''), '/');
        if ($url === '') { Flight::jsonError('QA Testing is not set up on this platform ([sidecar.qa] url).', 503); return; }
        Flight::jsonSuccess(['url' => $url, 'project' => (string) $inst->slug]);
    }

    /* ---- the owner's model connections, for this project's pipelines --------------
     * MODEL_CONNECTIONS_PLAN.md phase 4. The key never leaves core: a pipeline agent step
     * asks core to make the call. The payer is the project's OWNER (instance.member_id, read
     * here — never taken from the request), and only for connections the owner opted in
     * ("let my projects' pipelines use this"). */

    /** The owner's connection by id, if this instance may use it; else null. */
    private function pipelineConnection(int $instanceId, int $connectionId) {
        $inst = Bean::load('instance', $instanceId);
        $owner = (int) ($inst->memberId ?? 0);
        if (!$inst->id || $owner <= 0 || $connectionId <= 0) return null;
        $c = \Model_Modelconnection::byId($connectionId);
        if (!$c || (int) $c->memberId !== $owner || (int) ($c->allowPipelines ?? 0) !== 1) return null;
        return $c;
    }

    /** GET /brokerinfo/modelconnections — the owner's opted-in connections (no keys, no endpoints). */
    public function modelconnections($params = []) {
        [$key, $iid] = $this->requireBroker();
        if (!$key) return;
        $inst = Bean::load('instance', $iid);
        $owner = (int) ($inst->memberId ?? 0);
        $out = [];
        if ($owner > 0) {
            foreach (\Model_Modelconnection::forMember($owner) as $c) {
                if ((int) ($c->allowPipelines ?? 0) !== 1) continue;
                $out[] = $c->box()->publicInfo();
            }
        }
        Flight::json(['instance_id' => $iid, 'connections' => $out]);
    }

    /**
     * POST /brokerinfo/modelcall — {connection, model?, system?, prompt, max_tokens?, timeout?}.
     * Answers {job} at once and finishes the call after the response, because a model can take
     * longer than the 60 s nginx allows a request. Poll /brokerinfo/modelresult?job=.
     */
    public function modelcall($params = []) {
        [$key, $iid] = $this->requireBroker();
        if (!$key) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { Flight::jsonError('POST only.', 405); return; }
        $d = $this->jsonBody();
        $cid = (int) ($d['connection'] ?? 0);
        $c = $this->pipelineConnection($iid, $cid);
        if (!$c) { Flight::jsonError("Model connection #{$cid} is not available to this project's pipelines (it must be the project owner's, with 'let my projects' pipelines use this' ticked on Connections → Models).", 403); return; }
        $prompt = (string) ($d['prompt'] ?? '');
        if (trim($prompt) === '') { Flight::jsonError('No prompt.', 400); return; }
        if ($p = $c->box()->callProblems()) { Flight::jsonError(implode('; ', $p), 409); return; }
        $timeout = max(5, min(3600, (int) ($d['timeout'] ?? 600)));

        $job = Bean::dispense('modelcall');
        $job->instanceRef = $iid; $job->connectionRef = (int) $c->id; $job->memberRef = (int) $c->memberId;
        $job->model = (string) ($d['model'] ?? ''); $job->status = 'running'; $job->createdAt = date('Y-m-d H:i:s');
        $jobId = (int) Bean::store($job);

        // Send the job id NOW and close the request; everything below runs after the caller
        // has its answer. Not Flight::json(): Flight buffers the body until the route returns,
        // so finishing the request after it sent an empty 200 (seen live).
        $payload = json_encode(['job' => $jobId, 'status' => 'running']);
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($payload));
        echo $payload;
        flush();
        ignore_user_abort(true);
        @set_time_limit($timeout + 60);
        if (!function_exists('fastcgi_finish_request')) {
            // CLI / non-FPM: there is no "after the response"; the caller would wait the whole
            // call. Say so instead of pretending the job ran in the background.
            $this->logger->error('ERROR Brokerinfo::modelcall: fastcgi_finish_request unavailable (not PHP-FPM); the call runs inside the request');
        } else {
            fastcgi_finish_request();
        }

        $r = $c->box()->call((string) ($d['system'] ?? ''), $prompt, (string) ($d['model'] ?? ''), (int) ($d['max_tokens'] ?? 4096), $timeout);
        $job = Bean::load('modelcall', $jobId);
        $job->status = $r['ok'] ? 'done' : 'failed';
        $job->model = $r['model'];
        $job->resultText = $r['text'];
        $job->usageJson = json_encode($r['usage']);
        $job->error = mb_substr($r['error'], 0, 1000);
        $job->http = $r['http'];
        $job->finishedAt = date('Y-m-d H:i:s');
        Bean::store($job);
        $this->logger->info('Pipeline model call', ['job' => $jobId, 'instance' => $iid, 'connection' => (int) $c->id, 'ok' => $r['ok'], 'model' => $r['model'], 'usage' => $r['usage']]);
        exit;   // the response went out above; Flight must not send a second (empty) one
    }

    /**
     * POST /brokerinfo/support — {subject, message, category?} from a project's AI agent,
     * sent after the member agreed to escalate (the send_to_tiknix_support MCP tool). The
     * ticket is the project OWNER's and names the project; the key decides both, never the
     * body. lib/Support.php limits agent tickets per member per hour.
     */
    public function support($params = []) {
        [$key, $iid] = $this->requireBroker();
        if (!$key) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { Flight::jsonError('POST only.', 405); return; }
        $inst = Bean::load('instance', $iid);
        $owner = (int) ($inst->memberId ?? 0);
        if (!$inst->id || $owner <= 0) { Flight::jsonError("Project #{$iid} has no owner on core to open a ticket for.", 409); return; }
        $d = $this->jsonBody();
        try {
            $r = Support::open($owner, (string) ($d['subject'] ?? ''), (string) ($d['message'] ?? ''), (string) ($d['category'] ?? 'problem'), $inst, 'agent');
        } catch (\InvalidArgumentException $e) {
            Flight::jsonError($e->getMessage(), 400); return;
        } catch (\RuntimeException $e) {
            Flight::jsonError($e->getMessage(), 429); return;
        }
        Flight::json(['ok' => true, 'ticket' => $r['contact'], 'thread' => $r['thread'],
                      'url' => rtrim((string) Flight::get('app.baseurl'), '/') . ($r['thread'] ? '/communications/thread/' . $r['thread'] : '/contact')]);
    }

    /**
     * POST /brokerinfo/kb {question} — a project's agent asks what is known about the platform
     * (app\KnowledgeBase) before it asks its user about escalating to support. Answers only what
     * a person published; `matches` is empty when nothing answers the question — which is an
     * answer too, never an error.
     */
    public function kb($params = []) {
        [$key, $iid] = $this->requireBroker();
        if (!$key) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { Flight::jsonError('POST only.', 405); return; }
        $q = trim((string) ($this->jsonBody()['question'] ?? ''));
        if (mb_strlen($q) < 8) { Flight::jsonError('Ask a question: what you saw, with the exact error if there was one.', 400); return; }
        $matches = KnowledgeBase::ask(mb_substr($q, 0, 2000), 3);
        $this->logger->info('Knowledge base asked', ['instance' => $iid, 'matches' => array_column($matches, 'id'), 'question' => mb_substr($q, 0, 160)]);
        Flight::json(['ok' => true, 'matches' => $matches]);
    }

    /**
     * POST /brokerinfo/plan {plan} — a plan worked out in the project's terminal, handed to the
     * Builder (app\TerminalPlan): it arrives as a draft the project's owner approves there.
     */
    public function plan($params = []) {
        [$key, $iid] = $this->requireBroker();
        if (!$key) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { Flight::jsonError('POST only.', 405); return; }
        $inst = Bean::load('instance', $iid);
        if (!$inst->id) { Flight::jsonError('That project is gone.', 404); return; }
        $plan = $this->jsonBody()['plan'] ?? null;
        try {
            $r = TerminalPlan::receive($inst, is_array($plan) ? $plan : []);
        } catch (\InvalidArgumentException $e) {
            Flight::jsonError($e->getMessage(), 400); return;
        } catch (\RuntimeException $e) {
            Flight::jsonError($e->getMessage(), 409); return;
        }
        $this->logger->info('Plan received from a terminal', ['instance' => $inst->slug, 'plan' => $r['plan'], 'tasks' => $r['tasks']]);
        Flight::json(['ok' => true] + $r + ['url' => rtrim((string) Flight::get('app.baseurl'), '/') . '/sidecar/app/workbench?to=' . rawurlencode('/workbench/view?id=' . $r['plan'])]);
    }

    /** GET /brokerinfo/modelresult?job= — a call this instance started. */
    public function modelresult($params = []) {
        [$key, $iid] = $this->requireBroker();
        if (!$key) return;
        $job = Bean::load('modelcall', (int) $this->getParam('job', 0));
        if (!$job->id || (int) $job->instanceRef !== $iid) { Flight::jsonError('No such call for this project.', 404); return; }
        Flight::json([
            'job' => (int) $job->id, 'status' => (string) $job->status, 'model' => (string) $job->model,
            'text' => (string) $job->resultText, 'usage' => json_decode((string) $job->usageJson, true) ?: (object) [],
            'error' => (string) $job->error, 'http' => (int) $job->http,
        ]);
    }

    /** Decode the JSON request body (broker calls are server-to-server JSON). */
    private function jsonBody(): array {
        $raw = file_get_contents('php://input') ?: '';
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /** Constrain a free-text environment to the known set; default production. */
    private function env(string $env): string {
        $env = strtolower(trim($env));
        return in_array($env, ['development', 'staging', 'production'], true) ? $env : 'production';
    }

    /** Resolve the caller's broker key from the Authorization bearer (hash lookup — the raw key is never stored). */
    private function brokerKey() {
        return BrokerService::keyFromRequest();   // read-only lookup: the key row is left alone
    }
}
