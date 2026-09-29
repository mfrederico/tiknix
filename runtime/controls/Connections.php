<?php
/**
 * Connections — third-party integrations for AI Builder instances.
 *
 * MVP: per-instance GitHub connection (personal access token), used to publish an
 * instance's commits to the customer's own repo as a branch + pull request.
 * Credentials are encrypted at rest via app\EncryptionService (key: conf/config.ini
 * [security] app_key). Ownership is enforced per member+instance — a connection is
 * only ever readable/usable by the member who owns the instance it is bound to.
 *
 * GitHub keeps a bespoke flow (PAT + publish->PR). Every other connector is
 * registry-driven (app\services\connectors\*Connector) and shares one generic
 * OAuth path: connect() -> signed state (OAuthStateService) -> provider ->
 * callback() -> connector->exchangeCode() -> encrypted connections row. Tokens are
 * held ONLY on the control plane; instances reach a store through the MCP broker.
 * A connection is scoped to member + instance + environment (dev/staging/prod).
 *
 * Routes (auto-routed /connections/<method>):
 *   GET  /connections?id=<instance>            - connections hub (list + add)
 *   GET  /connections/connect/<type>?id=&env=  - start a connector's OAuth
 *   GET  /connections/callback/<type>          - OAuth redirect target
 *   GET  /connections/setup?id=<instance>      - guided GitHub connect page (new tab)
 *   GET  /connections/status?id=<instance>     - JSON: is this instance GitHub-connected?
 *   POST /connections/add                      - store/replace a GitHub PAT connection
 *   POST /connections/connectkey               - connect an api_key connector (validated paste)
 *   POST /connections/test                     - re-validate a stored connection
 *   POST /connections/disconnect               - remove a connection (any connector)
 *   POST /connections/publish                  - push HEAD + open/reuse a PR on the repo
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;
use app\EncryptionService;
use app\GitHubService;
use app\OAuthStateService;
use app\services\connectors\ConnectorRegistry;

class Connections extends Control {

    private const APP = 'tiknix';

    private function connSummary($conn): array {
        $meta = json_decode(($conn->metadataJson ?: '{}') ?? '', true) ?: [];
        return [
            'id'          => (int)$conn->id,
            'type'        => $conn->connectorType,
            'repo'        => ($meta['owner'] ?? '') . '/' . ($meta['repo'] ?? ''),
            'defaultBranch' => $meta['defaultBranch'] ?? 'main',
            'autoPublish' => !empty($meta['autoPublish']),
            'resolvesTo'  => array_values($meta['resolvesTo'] ?? []),   // [{domain,branch,verified,verifiedAt,live}]
            'enabled'     => (int)$conn->enabled === 1,
            'lastUsed'    => $conn->lastUsedAt,
            'lastError'   => $conn->lastError,
        ];
    }

    // --- routes ---------------------------------------------------------------

    /**
     * The host this request arrived on. NEVER a guess.
     *
     * This used to end in `?? 'localhost'`, which is the worst possible default for the
     * one job these callers have: building an OAuth redirect_uri. A callback pointing at
     * localhost is not allowlisted anywhere, so the provider rejects the whole flow and
     * the operator sees a provider-side error with nothing in our log to explain it. An
     * absent Host header means we genuinely cannot know the callback URL, and the honest
     * answer to that is to stop, not to invent an address no provider will accept.
     */
    private function requestHost(): string {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $msg = 'OAuth callback URL requested with no Host header — cannot build a '
                 . 'redirect_uri. Refusing rather than sending a provider an invented host.';
            $this->logger->error($msg);
            throw new \RuntimeException($msg);
        }
        return $host;
    }

    /** https when the request (or the proxy in front of it) says so. */
    private function requestIsHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private function redirectUri(): string {
        return ($this->requestIsHttps() ? 'https' : 'http') . '://' . $this->requestHost()
             . '/connections/callback/github';
    }

    /** What a page may see of a connection: never the key. */
    private function mcSummary($c): array {
        $m = $c->box();
        $out = ['id' => (int) $c->id, 'name' => (string) $c->name, 'preset' => (string) $c->preset,
                'protocol' => (string) $c->protocol, 'base_url' => (string) $c->baseUrl, 'auth' => (string) $c->auth,
                'key_status' => $m->keyStatus(), 'engine' => $m->engineName(), 'allow_pipelines' => (int) ($c->allowPipelines ?? 0) === 1,
                'last_test_at' => (string) $c->lastTestAt, 'last_test_ok' => (bool) $c->lastTestOk, 'last_test_msg' => (string) $c->lastTestMsg];
        foreach (\Model_Modelconnection::TIERS as $t) $out[$t . '_model'] = (string) ($c->{$t . 'Model'} ?? '');
        return $out;
    }

    /** The caller's own connection by posted id, or null (flash already set). */
    private function mcOwned(int $id) {
        $c = $id > 0 ? \Model_Modelconnection::byId($id) : null;
        if (!$c || (int) $c->memberId !== (int) $this->member->id) return null;
        return $c;
    }

    private function mcGate(bool $json = false): bool {
        if (!builder_tools_enabled()) { $json ? Flight::jsonError('Model connections are a builder feature.', 403) : Flight::redirect('/connections'); return false; }
        if (Flight::request()->method !== 'POST') { $json ? Flight::jsonError('POST only.', 405) : Flight::redirect('/connections'); return false; }
        if (!Flight::csrf()->validateRequest()) { $json ? Flight::jsonError('Invalid CSRF token.', 403) : $this->flash('error', 'Invalid CSRF token.'); if (!$json) Flight::redirect('/connections#models'); return false; }
        return true;
    }

    /** POST /connections/modelsave — create or update one of my model connections. */
    public function modelsave($params = []) {
        if (!$this->mcGate()) return;
        $d = Flight::request()->data->getData();
        $id = (int) ($d['id'] ?? 0);
        $isRoot = Flight::hasLevel(LEVELS['ROOT']);
        if ($id > 0 && !$this->mcOwned($id)) { $this->flash('error', 'That model connection is not yours.'); Flight::redirect('/connections#models'); return; }
        if ($p = \Model_Modelconnection::problems($d, $isRoot, $id ?: null, (int) $this->member->id)) {
            $this->flash('error', 'Not saved: ' . implode('; ', $p) . '.');
            Flight::redirect('/connections#models');
            return;
        }
        $memberId = (int) $this->member->id;
        $raw = (string) ($d['api_key'] ?? '');
        $saved = \app\CoreDb::with(function () use ($id, $d, $memberId, $raw) {
            $c = $id > 0 ? \app\Bean::load('modelconnection', $id) : \app\Bean::dispense('modelconnection');
            $c->box()->fill($d, $memberId);
            if (!empty($d['clear_key'])) $c->box()->setKey('');
            elseif (trim($raw) !== '' && !str_contains($raw, '…')) $c->box()->setKey($raw);   // blank / the mask = keep
            return (int) \app\Bean::store($c);
        });
        if (!$saved) { $this->flash('error', 'Could not save: ' . \app\CoreDb::lastError()); Flight::redirect('/connections#models'); return; }
        $this->logger->info('Model connection saved', ['id' => $saved, 'member_id' => $memberId]);
        $this->flash('success', 'Model connection saved. Use Test to check it and list its models.');
        Flight::redirect('/connections#models');
    }

    /** POST /connections/modeldelete — delete one of mine (clears it as my build connection if it was). */
    public function modeldelete($params = []) {
        if (!$this->mcGate()) return;
        $c = $this->mcOwned((int) (Flight::request()->data->id ?? 0));
        if (!$c) { $this->flash('error', 'That model connection is not yours.'); Flight::redirect('/connections#models'); return; }
        $memberId = (int) $this->member->id;
        try { $ch = \Model_Modelconnection::chosenFor($memberId); } catch (\RuntimeException $e) { $ch = null; }
        if ($ch && (int) $ch->id === (int) $c->id) \Model_Modelconnection::choose($memberId, 0);
        $id = (int) $c->id;
        \app\CoreDb::with(fn() => \app\Bean::trash(\app\Bean::load('modelconnection', $id)) ?? true);
        $this->flash('success', "Deleted '{$c->name}'." . ($ch && (int) $ch->id === $id ? ' Your builds use the platform\'s Claude again.' : ''));
        Flight::redirect('/connections#models');
    }

    /** POST /connections/modelchoose — build with this connection (id) or the platform's Claude (0). */
    public function modelchoose($params = []) {
        if (!$this->mcGate()) return;
        $id = (int) (Flight::request()->data->id ?? 0);
        $memberId = (int) $this->member->id;
        if ($id > 0) {
            $c = $this->mcOwned($id);
            if (!$c) { $this->flash('error', 'That model connection is not yours.'); Flight::redirect('/connections#models'); return; }
            if ($p = $c->box()->runProblems()) { $this->flash('error', 'Cannot build with it: ' . implode('; ', $p) . '.'); Flight::redirect('/connections#models'); return; }
        }
        \Model_Modelconnection::choose($memberId, $id);
        $this->flash('success', $id > 0 ? "Your builds now run on '{$c->name}'." : 'Your builds run on the platform\'s Claude.');
        Flight::redirect('/connections#models');
    }

    /** POST /connections/modeltest — list the endpoint's models and make one tiny call. JSON. */
    public function modeltest($params = []) {
        if (!$this->mcGate(true)) return;
        $c = $this->mcOwned((int) (Flight::request()->data->id ?? 0));
        if (!$c) { Flight::jsonError('That model connection is not yours.', 404); return; }
        try { $r = $c->box()->test(); }
        catch (\Throwable $e) { $r = ['ok' => false, 'message' => $e->getMessage(), 'models' => []]; }
        \app\CoreDb::with(fn() => \app\Bean::store($c));
        Flight::json($r);
    }

    /** The Models card's data: the member's own connections (MODEL_CONNECTIONS_PLAN.md). */
    private function modelsViewData(): array {
        $mine = [];
        foreach (\Model_Modelconnection::forMember((int) $this->member->id) as $c) $mine[] = $this->mcSummary($c);
        $chosen = null; $problem = '';
        try { $ch = \Model_Modelconnection::chosenFor((int) $this->member->id); $chosen = $ch ? (int) $ch->id : null; }
        catch (\RuntimeException $e) { $problem = $e->getMessage(); }
        return [
            'connections' => $mine,
            'presets'     => \Model_Modelconnection::PRESETS,
            'chosen'      => $chosen,
            'problem'     => $problem,
            'is_root'     => Flight::hasLevel(LEVELS['ROOT']),
        ];
    }

    /**
     * GET /connections — this app's own connections: what it is connected to, and (admins)
     * connect / disconnect / Turnstile. The control plane's hub for OTHER projects is core's
     * own override of this controller (RUNTIME-SPLIT-MAP.md step 2).
     */
    public function index($params = []): void {
        if (!$this->requireLogin()) return;
        $this->instanceConnections();
    }

    /** Inside an instance: read-only list of what this app is connected to (broker). */
    /**
     * The instance's own Connections page, answered ENTIRELY from this install.
     *
     * It used to ask core two questions over the broker -- "what am I connected to?"
     * and "what connectors do you offer?" -- and both answers were already here. The
     * connections are in this install's own data/connections.db, and the connector
     * catalogue is ConnectorRegistry, which ships in every clone.
     *
     * Removing that round-trip removes what came with it: `$r['body']['connections']
     * ?? []` turned a garbled or failed response into an empty list, which renders as
     * "you have not connected anything" -- indistinguishable from the truth, and
     * wrong. There is nothing left to default here because there is no longer a
     * remote answer that can go missing.
     */
    private function instanceConnections(): void {
        if (!Flight::hasLevel(LEVELS['ADMIN'])) { Flight::redirect('/dashboard'); return; }
        $root = dirname(__DIR__);

        $connections = ConnectionStore::withOwnDb(function () {
            $rows = [];
            foreach (Bean::find('connections', 'ORDER BY connector_type, environment') as $c) {
                if (!$c->id) continue;
                $rows[] = [
                    'id'          => (int) $c->id,
                    'connector'   => (string) $c->connectorType,
                    'environment' => (string) ($c->environment ?: 'production'),
                    'name'        => (string) ($c->externalName ?: $c->externalEid),
                    'url'         => (string) ($c->externalUrl ?? ''),
                    'enabled'     => (int) $c->enabled === 1,
                    'revoked'     => !empty($c->revokedAt),
                    'last_used'   => (string) ($c->lastUsedAt ?? ''),
                ];
            }
            return $rows;
        }, []);

        // An OAuth connector reports unconfigured here on purpose: the app
        // registration lives on core, so this install genuinely cannot start that
        // handshake alone. api_key connectors are configured by definition.
        $connectors = [];
        foreach (\app\services\connectors\ConnectorRegistry::all() as $c) {
            $m = $c->meta();
            $connectors[] = [
                'key'        => $c->key(),
                'label'      => (string) ($m['label'] ?? $c->key()),
                'blurb'      => (string) ($m['blurb'] ?? ''),
                'category'   => (string) ($m['category'] ?? 'Other'),
                'icon'       => (string) ($m['icon'] ?? 'plug'),
                'auth_type'  => (string) ($m['auth_type'] ?? 'oauth'),
                'configured' => (bool) $c->isConfigured(),
                /* Can this connector be connected with a PER-CONNECTION app, even though
                   this install holds none? On a project conf/<key>.ini is scrubbed empty
                   on purpose, so isConfigured() is always false here — and treating that
                   as "unavailable" hid the Shopify card completely, which is exactly the
                   case a merchant's own app exists to serve. */
                'custom_ok'  => method_exists($c, 'isConfiguredFor'),
            ];
        }

        $this->render('connections/instance', [
            'title'           => 'Connections',
            'connections'     => $connections,
            'turnstile'       => \app\Turnstile::state(),
            'brokerError'     => '',
            'connectors'      => $connectors,
            'connectorsError' => '',
            'appName'         => basename($root),
            'environments'    => ['development', 'production'],
        ]);
    }

    /**
     * POST /connections/instanceconnect — instance-driven OAuth connect (owner/admin).
     * Asks core (via broker) for a signed handoff URL and redirects the browser to it;
     * core runs the OAuth and returns to this instance's /connections.
     */
    public function instanceconnect($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->instanceManageGuard(false)) return;
        if (!$this->validateCSRF()) return;
        $root = dirname(__DIR__);
        $type = strtolower(trim((string)$this->getParam('type', '')));
        $env  = $this->normalizeEnv($this->getParam('env', 'production'));
        $shop = trim((string)$this->getParam('shop', ''));

        /* A custom app belonging to THIS project is run here, not handed to core.
           The handoff exists only because a project holds no provider app credentials —
           conf/<connector>.ini is scrubbed empty at provision so a customer's project can
           never hold tiknix's shared secret. Their OWN app is a different thing: it is
           theirs, this install may hold it, and Shopify redirects straight back to this
           domain. Running it locally removes the handoff's hardest part — a secret that
           would otherwise have to survive a browser redirect between two hosts. */
        $customApp = [
            'client_id'     => trim((string)$this->getParam('app_key', '')),
            'client_secret' => trim((string)$this->getParam('app_secret', '')),
        ];
        if ($customApp['client_id'] !== '' || $customApp['client_secret'] !== '') {
            $this->localConnectorConnect($type, $env, $shop, $customApp,
                trim((string)$this->getParam('app_scopes', '')));
            return;
        }

        $returnUrl = app_url('/connections');
        $r = \app\InstanceAutomations::connectIntent($root, $type, $env, $shop, $returnUrl);
        if (!empty($r['error'])) { $this->flash('error', $r['error']); Flight::redirect('/connections'); return; }
        Flight::redirect($r['url']);
    }

    /** POST /connections/instanceconnectkey — instance-driven api_key connect (owner/admin). JSON. */
    public function instanceconnectkey($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->instanceManageGuard(true)) return;
        if (!$this->validateCSRF()) return;
        $type = strtolower(trim((string)$this->getParam('type', '')));
        $env  = $this->normalizeEnv($this->getParam('env', 'production'));
        $key  = trim((string)$this->getParam('key', ''));
        if ($type === '') { $this->jsonError('Connector is required.', 400); return; }

        // Stored HERE, by this install, with this install's key. It used to POST the
        // raw credential to core's /brokerinfo/connectkey so core could write it back
        // into this very file -- a network round-trip, and the customer's secret over
        // the wire, to reach a database on local disk.
        $connector = \app\services\connectors\ConnectorRegistry::get($type);
        if (!$connector) { $this->jsonError('Unknown connector: ' . $type, 400); return; }
        $meta = $connector->meta();
        if (($meta['auth_type'] ?? 'oauth') !== 'api_key') {
            $this->jsonError(ucfirst($type) . ' does not connect with a pasted key.', 400); return;
        }
        // A key is required unless the connector says otherwise. The REST connector
        // can point at a public API, where demanding a secret would be demanding
        // something that does not exist.
        if ($key === '' && ($meta['key_required'] ?? true)) {
            $this->jsonError('A key is required for ' . ucfirst($type) . '.', 400); return;
        }

        try {
            // The provider's own words on failure -- "Not Authenticated" and "token
            // expired" want different things done about them.
            $payload = $connector->validateApiKey($key, $this->declaredFields($connector));
            $payload['auth_type'] = 'api_key';
            $id = ConnectionStore::put($type, $env, $payload);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400); return;
        }
        if ($id <= 0) { $this->jsonError('The connection could not be stored on this install.', 500); return; }

        $this->jsonSuccess([
            'id'          => $id,
            'connector'   => $type,
            'environment' => $env,
            'account'     => (string) ($payload['external_name'] ?? $payload['external_eid'] ?? ''),
        ], ucfirst($type) . ' connected.');
    }

    /** POST /connections/instancedisconnect — instance-driven disconnect (owner/admin). JSON. */
    public function instancedisconnect($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->instanceManageGuard(true)) return;
        if (!$this->validateCSRF()) return;
        $cid = (int)$this->getParam('cid', 0);
        if ($cid <= 0) { $this->jsonError('connection id required.', 400); return; }

        // Local, for the same reason as instanceconnectkey: the row is in this
        // install's own file. The id needs no ownership check because a foreign id
        // simply is not in this database.
        $gone = ConnectionStore::withOwnDb(function () use ($cid) {
            $conn = Bean::load('connections', $cid);
            if (!$conn->id) return false;
            Bean::trash($conn);
            return true;
        }, false);

        if (!$gone) { $this->jsonError('No such connection on this install.', 404); return; }
        $this->jsonSuccess([], 'Disconnected.');
    }

    /** Guard for the instance-side manage actions: instance context (not control plane) + ADMIN. */
    private function instanceManageGuard(bool $json): bool {
        if (builder_tools_enabled()) {   // on the control plane, use the owner-scoped flow instead
            if ($json) $this->jsonError('Manage connections from the control-plane Connections page.', 400);
            else Flight::redirect('/connections');
            return false;
        }
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            if ($json) $this->jsonError('Admins only.', 403);
            else Flight::redirect('/integrations');
            return false;
        }
        return true;
    }

    // --- Human verification (Cloudflare Turnstile) ----------------------------
    //
    // A per-install SECURITY connection, not a per-project data connector: it gates
    // THIS site's registration form, so it is stored in and read from this install's
    // own connection store (core's on core, the instance's on an instance). Both
    // actions are install-local and never touch another install's keys.

    /** POST /connections/turnstilesave — store this install's Turnstile keys (admin). JSON. */
    public function turnstilesave($params = []): void {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) { $this->jsonError('Admins only.', 403); return; }
        if (!$this->validateCSRF()) return;
        $site   = trim((string) $this->getParam('site_key', ''));
        $secret = trim((string) $this->getParam('secret_key', ''));
        if ($site === '' || $secret === '') { $this->jsonError('Both the site key and the secret key are required.', 400); return; }
        try {
            $id = \app\Turnstile::save($site, $secret);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400); return;
        }
        if ($id <= 0) { $this->jsonError('The Turnstile keys could not be stored on this install.', 500); return; }
        $this->jsonSuccess(['id' => $id], 'Human verification is on — new sign-ups now pass the Turnstile challenge.');
    }

    /** POST /connections/turnstileforget — remove this install's Turnstile keys (admin). JSON. */
    public function turnstileforget($params = []): void {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) { $this->jsonError('Admins only.', 403); return; }
        if (!$this->validateCSRF()) return;
        \app\Turnstile::forget();
        $this->jsonSuccess(['source' => \app\Turnstile::state()['source']], 'Human verification is off for this site.');
    }

    /**
     * POST /connections/githubwebhook — provision the repo's push→deploy webhook so a
     * push to GitHub fires this instance's trigger.github pipelines. Mints a secret,
     * (re)creates the hook via the GitHub API pointing at /webhook/github, and stores
     * the secret encrypted on the connection. Owner-scoped.
     */
    /**
     * POST /connections/telegramwebhook — point a bot at THIS install.
     *
     * The URL is app_url(), which is the install's own base — so core points
     * Telegram at core, a hosted tenant points it at its own subdomain, and a
     * self-hosted deployment points it at wherever that box lives. There is no
     * branch on which of those is running, because there does not need to be: a
     * Telegram bot is created by whoever owns it with @BotFather, so the token
     * belongs to this install rather than to a control plane, and inbound,
     * storage and outbound all happen here.
     *
     * Scoped by connection ownership rather than by instance: an install that has
     * no instances at all still has connections and still needs this.
     *
     * The secret is minted here and never shown. Its only power is to prove a POST
     * came from Telegram about this connection, so it is regenerated on every call
     * — re-running this after a leak is the fix, and costs nothing.
     */
    public function telegramwebhook($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        // This install's own store. member_id was the shared-table way of asking
        // "is this yours?"; the file answers it now -- a connection that is not this
        // install's is not in this database.
        $cid  = (int) $this->getParam('connection', 0);
        $conn = ConnectionStore::withOwnDb(
            fn() => Bean::findOne('connections', "id = ? AND connector_type = 'telegram'", [$cid]), null);
        if (!$conn || !$conn->id) { Flight::jsonError('Telegram connection not found.', 404); return; }

        // Decrypted with this install's key. Read raw, this is ciphertext -- put()
        // encrypts access_token, so handing it to Telegram would fail authentication
        // with a message about the token, not about the encryption.
        $token = ConnectionStore::ownToken($conn);
        if ($token === '') { Flight::jsonError('This connection has no usable bot token.', 400); return; }

        $connector = ConnectorRegistry::get('telegram');
        $url       = app_url('/webhook/telegram/' . (int) $conn->id);

        // https only. Telegram refuses a plain-http webhook anyway, but failing here
        // says why, rather than surfacing as its less obvious complaint.
        if (stripos($url, 'https://') !== 0) {
            $this->fail('Telegram only delivers to https. This install\'s [app] baseurl is "'
                      . $url . '".', 400);
            return;
        }

        $secret = bin2hex(random_bytes(24));

        try {
            $connector->setWebhook($token, $url, $secret);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 400); return;
        }

        // Stored only after Telegram accepted it. Storing first would leave the
        // database claiming a secret that the bot is not actually sending.
        $cidNow = (int) $conn->id;
        ConnectionStore::withOwnDb(function () use ($cidNow, $secret) {
            $row = Bean::load('connections', $cidNow);
            if (!$row->id) return false;
            $row->webhookSecret = $secret;
            $row->updatedAt     = date('Y-m-d H:i:s');
            Bean::store($row);
            return true;
        }, false, true);

        Flight::jsonSuccess(['url' => $url], 'Telegram will deliver messages here.');
    }

    /** POST /connections/telegramwebhookremove — stop delivery to this install. */
    public function telegramwebhookremove($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        // This install's own store. member_id was the shared-table way of asking
        // "is this yours?"; the file answers it now -- a connection that is not this
        // install's is not in this database.
        $cid  = (int) $this->getParam('connection', 0);
        $conn = ConnectionStore::withOwnDb(
            fn() => Bean::findOne('connections', "id = ? AND connector_type = 'telegram'", [$cid]), null);
        if (!$conn || !$conn->id) { Flight::jsonError('Telegram connection not found.', 404); return; }

        try {
            ConnectorRegistry::get('telegram')->deleteWebhook(ConnectionStore::ownToken($conn));
        } catch (\Throwable $e) {
            // Clear the secret regardless. If Telegram is unreachable the operator
            // still wants this install to stop trusting deliveries for this bot, and
            // an empty secret makes the webhook refuse everything.
            Flight::get('log')?->warning('deleteWebhook failed; clearing the secret anyway',
                ['connection' => (int) $conn->id, 'err' => $e->getMessage()]);
        }

        $cidNow = (int) $conn->id;
        ConnectionStore::withOwnDb(function () use ($cidNow) {
            $row = Bean::load('connections', $cidNow);
            if (!$row->id) return false;
            $row->webhookSecret = '';
            $row->updatedAt     = date('Y-m-d H:i:s');
            Bean::store($row);
            return true;
        }, false, true);

        Flight::jsonSuccess([], 'Telegram will no longer deliver here.');
    }

    /**
     * POST /connections/githubwebhook — register this install's push hook.
     *
     * INSTALL-LOCAL, and it has to be. The hook secret is encrypted with this
     * install's key and decrypted by this install's /webhook/github handler; core
     * doing it on an instance's behalf would seal the secret with core's key and
     * hand the instance something it cannot open. So there is no instance id here
     * any more -- you register the hook from the install it belongs to, which is
     * also the install whose domain the hook points at.
     */
    public function githubwebhook($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $conn = ConnectionStore::for('github');
        if (!$conn) { Flight::jsonError('Connect a GitHub repo to this install first.', 400); return; }

        $meta  = json_decode((string) ($conn->metadataJson ?: '{}'), true) ?: [];
        $owner = (string) ($meta['owner'] ?? ''); $repo = (string) ($meta['repo'] ?? '');
        if ($owner === '' || $repo === '') { Flight::jsonError('This GitHub connection has no owner/repo.', 400); return; }

        // This install's own base url -- on an instance that is <slug>.tiknix.com,
        // which is the whole point: the delivery arrives already scoped.
        $callback = app_url('/webhook/github');
        try {
            $pat = ConnectionStore::ownToken($conn);
            if ($pat === '') { Flight::jsonError('The GitHub token could not be decrypted on this install.', 500); return; }
            $gh  = new GitHubService($pat, $owner, $repo);
            $secret   = bin2hex(random_bytes(20));
            $existing = $gh->findWebhook($callback);
            if ($existing) { $gh->updateWebhook((int) $existing['id'], $callback, $secret); }
            else           { $gh->createWebhook($callback, $secret, ['push']); }

            ConnectionStore::withOwnDb(function () use ($conn, $secret) {
                $row = Bean::load('connections', (int) $conn->id);
                if (!$row->id) return false;
                $row->webhookSecret = EncryptionService::encrypt($secret);
                $row->updatedAt     = date('Y-m-d H:i:s');
                Bean::store($row);
                return true;
            }, false, true);

            Flight::jsonSuccess(['callback' => $callback, 'updated' => (bool) $existing],
                $existing ? 'Deploy webhook updated.' : 'Deploy webhook created.');
        } catch (\Throwable $e) {
            Flight::jsonError('Could not set up the webhook (' . $e->getMessage()
                . '). Your GitHub token may lack admin:repo_hook — add it manually in GitHub: Settings → Webhooks → '
                . $callback . ', content-type application/json, event: push.', 400);
        }
    }

    /**
     * The non-secret extra fields a connector declared in meta()['fields'], read
     * from this request.
     *
     * Allowlisted BY THE CONNECTOR: only names it declared are read, so the form
     * cannot be used to push arbitrary keys into a connector's hands. Values are
     * passed through as submitted — validating them is the connector's job, since
     * only it knows what a legal base URL or auth style is for its own API.
     *
     * Secrets do not come through here. The key travels in its own parameter and
     * these values end up in the connection's metadata, which is stored unencrypted.
     */
    private function declaredFields($connector): array {
        $out = [];
        foreach ((array) ($connector->meta()['fields'] ?? []) as $f) {
            $name = (string) ($f['name'] ?? '');
            if ($name === '') continue;
            $out[$name] = trim((string) $this->getParam($name, (string) ($f['default'] ?? '')));
        }
        return $out;
    }

    /** Constrain a free-text environment to the known set; default production. */
    private function normalizeEnv($env): string {
        $env = strtolower(trim((string)$env));
        return in_array($env, ['development', 'staging', 'production'], true) ? $env : 'production';
    }

    /** The exact, provider-allowlisted callback URL for a connector on this host. */
    private function connectorRedirectUri(string $type): string {
        return ($this->requestIsHttps() ? 'https' : 'http') . '://' . $this->requestHost()
             . '/connections/callback/' . $type;
    }

    private function handoffError(string $msg): void {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;color:#1b1f24">'
           . '<h3>Connection could not start</h3><p style="color:#5b6470">' . htmlspecialchars($msg) . '</p></body>';
    }

    /**
     * Start an OAuth dance THIS install owns, against the project's own provider app.
     *
     * No instance id and no push target: this install IS the project, so the token it
     * receives belongs in its own connections store. The redirect_uri is built from the
     * request host — this project's domain — so the merchant must have registered that
     * callback in their custom app. That is not a workaround; it is the reason this can be
     * local at all, and the reason the shared tiknix app cannot be (only core's callback
     * is registered against that one).
     */
    private function localConnectorConnect(string $type, string $env, string $shop, array $customApp, string $wantScopes): void {
        $connector = ConnectorRegistry::get($type);
        if (!$connector) { $this->flash('error', 'Unsupported connector.'); Flight::redirect('/connections'); return; }

        $ok = method_exists($connector, 'isConfiguredFor')
            ? $connector->isConfiguredFor(['app' => $customApp])
            : false;
        if (!$ok) {
            $this->flash('error', 'That custom app is incomplete — both an API key and an API secret are required.');
            Flight::redirect('/connections'); return;
        }

        $state = OAuthStateService::issue([
            'provider'    => $type,
            'member_id'   => (int)$this->member->id,
            'instance_id' => 0,        // no other install is involved
            'environment' => $env,
            'shop'        => $shop,
            'local'       => true,     // this install runs the dance AND keeps the result
        ]);
        $_SESSION['oauth_state_hash'] = hash('sha256', $state);
        unset($_SESSION['oauth_custom_app'], $_SESSION['oauth_scopes']);
        $_SESSION['oauth_custom_app'] = ['for' => hash('sha256', $state)] + $customApp;
        if ($wantScopes !== '') {
            $_SESSION['oauth_scopes'] = ['for' => hash('sha256', $state), 'scopes' => $wantScopes];
        }

        try {
            $url = $connector->authorizeUrl([
                'state'        => $state,
                'redirect_uri' => $this->connectorRedirectUri($type),
                'shop'         => $shop,
                'app'          => $customApp,
                'scopes'       => $wantScopes,
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/connections'); return;
        }
        Flight::redirect($url);
    }

    /** Redirect to a handoff return_url with a status query param (or core as a fallback). */
    private function redirectBack(string $returnUrl, array $params): void {
        if ($returnUrl === '' || !preg_match('#^https://#i', $returnUrl)) {
            Flight::redirect('/sidecar/app/workbench'); return;
        }
        $sep = strpos($returnUrl, '?') !== false ? '&' : '?';
        Flight::redirect($returnUrl . $sep . http_build_query($params));
    }
}
