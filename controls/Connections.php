<?php
/**
 * Connections — a project's third-party integrations, seen from the control plane.
 *
 * A project runs in its own container and keeps its connections THERE (its own
 * data/connections.db, its own key); tokens never come to this host. This hub LISTS what the
 * app is connected to (ConnectorPush::ask → the app's /connectorapi/list) and starts a
 * connect: every connector is registry-driven (app\services\connectors\*Connector, or a
 * connectors/*.json manifest) and shares one OAuth path — connect() → signed state
 * (OAuthStateService) → provider → callback() → connector->exchangeCode() → the token is
 * handed to the app (handoff). An api_key connector is pasted here, validated, handed over the
 * same way. Disconnect, test and webhook secrets go through the app's own doors.
 *
 * Publishing is a PIPELINE in the project (the Deploy page, controls/Deploy.php); the GitHub
 * publish→PR flow that lived here is gone with the host folders it read.
 *
 * Also here: the member's own model connections (Settings → Models) and this install's
 * Turnstile.
 *
 * Routes (auto-routed /connections/<method>):
 *   GET  /connections?id=<instance>            - connections hub (list + add)
 *   GET  /connections/connect/<type>?id=&env=  - start a connector's OAuth
 *   GET  /connections/callback/<type>          - OAuth redirect target
 *   POST /connections/connectkey               - connect an api_key connector (validated paste)
 *   POST /connections/test                     - re-validate a stored connection
 *   POST /connections/disconnect               - remove a connection (any connector)
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;
use app\EncryptionService;
use app\GitHubService;
use app\OAuthStateService;
use app\BrokerService;
use app\services\connectors\ConnectorRegistry;

class Connections extends Control {

    private const APP = 'tiknix';

    /** A project the current member owns, running in its container. */
    private function ownedInstance($id) {
        $id = (int)$id;
        if (!$id) return null;
        $inst = Bean::load('instance', $id);
        if (!$inst->id) return null;
        if ((int)$inst->memberId !== (int)$this->member->id) return null;
        if (!\Model_Instance::tenantRow($inst)) return null;   // nothing but a container holds a project's app
        return $inst;
    }

    /** A project the member may work on — owned, or shared through a team (the picker's rule). Viewing only; writes use ownedInstance(). */
    private function accessibleInstance($id) {
        $id = (int)$id;
        if (!$id) return null;
        $inst = Bean::load('instance', $id);
        if (!$inst->id || !$inst->accessibleBy((int)$this->member->id)) return null;
        if (!\Model_Instance::tenantRow($inst)) return null;
        return $inst;
    }

    /**
     * True (after answering 409) when the project runs in its own container: its connections
     * live in the app, and the things this route does — GitHub publish, tokens read on this
     * host — are the app's own, on its Connections page.
     */
    private function appOwnsThis($inst): bool {
        if (!\Model_Instance::tenantRow($inst)) return false;
        $this->jsonError(($inst->displayName ?: $inst->slug) . ' runs in its own container. This is done on its own Connections page (the project\'s section of the menu).', 409);
        return true;
    }

    // --- routes ---------------------------------------------------------------

    /** GET /connections/connect/<type>?id=<instance>&env= — start a connector's OAuth. */
    public function connect($params = []): void {
        if (!$this->requireLogin()) return;
        $type = strtolower((string) ($params['operation']->name ?? ''));
        if ($type === '') { Flight::redirect('/connections'); return; }
        $this->connectorConnect($type);
    }

    /** GET /connections/callback/<type>?code=&state= — the provider's redirect target. */
    public function callback($params = []): void {
        $type = strtolower((string) ($params['operation']->name ?? ''));
        if ($type === '') { Flight::redirect('/connections'); return; }
        $this->connectorCallback($type);
    }

    /* ---- model connections (MODEL_CONNECTIONS_PLAN.md) -------------------------- */

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

    /** GET /connections[?id=<instance>] — connections hub; defaults to the member's most-recent store. */
    public function index($params = []): void {
        if (!$this->requireLogin()) return;
        // Inside an instance there is no owner/instance picker — show the read-only
        // list of what this app is connected to (metadata via the broker).
        $instances = Bean::find('instance', 'member_id = ? ORDER BY created_at DESC', [(int)$this->member->id]);

        // An explicit ?id= wins (deep links from the builder), then the project the
        // member selected. NOT "most recently created" — that guess meant Connections
        // showed a different project's stores than the one you were working on, and
        // connecting from here could bind a store to the wrong instance entirely.
        //
        // Any project the member may WORK ON (the rule the picker lists by), not only one
        // they own: a shared project used to bounce here to /projects without a word, so
        // the page looked missing. Viewing is for everyone on it; the connect / rename /
        // disconnect actions below stay the owner's (ownedInstance) and the view says so.
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) {
            $project = ProjectContext::current((int)$this->member->id);
            if ($project) $inst = $this->accessibleInstance((int)$project->id);
        }
        // No project chosen → choose one; do not silently fall back to some instance.
        if (!$inst) {
            $this->flash('info', 'Pick a project to see its connections.');
            Flight::redirect('/projects');
            return;
        }

        // A just-completed connect (a prior request) writes a connections row; bust
        // the cache before reading so a newly-connected store shows on the FIRST view
        // instead of after a second connect / cache TTL.
        $ad = Flight::get('cachedDatabaseAdapter');
        if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('connections');

        // What the SELECTED project is connected to, asked of its app (metadata only: names,
        // environments, state). Keys and webhook secrets stay in the container; the app's own
        // Connections page shows their hints.
        $listError = '';
        $byType = [];
        if (!\Model_Instance::tenantRow($inst)) {
            // Nothing holds a project's connections but its container; there is no other store to list.
            $this->flash('error', ($inst->displayName ?: $inst->slug) . ' is not running in a container, so it has no connections to show.');
            Flight::redirect('/projects');
            return;
        }
        try {
            $rows = \app\ConnectorPush::ask((int) $inst->id, '/connectorapi/list')['connections'] ?? null;
            if (!is_array($rows)) throw new \RuntimeException("{$inst->slug}'s /connectorapi/list answered without a connections list.");
            foreach ($rows as $c) {
                $byType[(string) $c['connector']][] = [
                    'id' => (int) $c['id'], 'environment' => (string) $c['environment'], 'name' => (string) $c['name'],
                    'eid' => '', 'url' => (string) $c['url'], 'enabled' => (bool) $c['enabled'], 'revoked' => (bool) $c['revoked'],
                    // the app's own page has these; its list door gives metadata only
                    'lastError' => null, 'webhookSet' => false, 'webhookHint' => '', 'keyHint' => '', 'specOps' => 0,
                ];
            }
        } catch (\RuntimeException $e) {
            $this->logger->error('Connections: could not list the app\'s connections', ['slug' => $inst->slug, 'err' => $e->getMessage()]);
            $listError = $e->getMessage();
        }

        // Unified connector cards: every registry connector, each carrying its own
        // existing connections so the hub shows connect-vs-connected state inline.
        $cards = [];
        foreach (ConnectorRegistry::all() as $conn) {
            $meta = $conn->meta();
            $auth = $meta['auth_type'] ?? 'oauth';
            $cards[] = [
                'key'          => $conn->key(),
                'label'        => $meta['label'] ?? $conn->key(),
                'blurb'        => $meta['blurb'] ?? '',
                'category'     => $meta['category'] ?? 'Other',
                'icon'         => $meta['icon'] ?? 'plug',
                'color'        => $meta['color'] ?? 'secondary',
                'auth_type'    => $auth,
                'connect_kind' => $auth === 'api_key' ? 'api_key' : ($conn->key() === 'shopify' ? 'shopify' : 'oauth'),
                'configured'   => $conn->isConfigured(),
                'features'     => $meta['features'] ?? [],
                // The connect form reads key_label / key_placeholder / key_required /
                // key_hint / fields from here, so a connector that needs more than a
                // pasted key describes itself instead of the view special-casing it.
                'meta'         => $meta,
                'manage_url'   => null,
                'connections'  => $byType[$conn->key()] ?? [],
            ];
        }

        $this->render('connections/index', [
            'mc'             => $this->modelsViewData(),
            'title'          => 'Connections',
            'instance'       => $inst,
            'instances'      => $instances,
            // shared with me (a team): the page is read-only and says so; owner actions stay the owner's
            'canManage'      => $inst->ownedBy((int)$this->member->id),
            'ownerEmail'     => (string) (Bean::load('member', (int)$inst->memberId)->email ?? ''),
            'cards'          => $cards,
            // could not ask the app what it is connected to — said on the page, never shown as "nothing connected"
            'listError'      => $listError,
            // This site's own sign-up gate — install-local, so this is CORE's Turnstile.
            'turnstile'      => \app\Turnstile::state(),
            // A manifest that will not load must SAY so here. A declarative connector
            // that simply fails to appear is indistinguishable from one nobody added,
            // and the file is the only place the mistake is visible.
            'connectorErrors' => ConnectorRegistry::errors(),
            'environments'   => ['development', 'production'],
            'categoryOrder'  => ['Project', 'Payments', 'Stores', 'Messaging', 'Social', 'Other'],
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
        // The control plane manages a project's connections through the owner-scoped flow.
        if ($json) $this->jsonError('Manage connections from the control-plane Connections page.', 400);
        else Flight::redirect('/connections');
        return false;
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
     * POST /connections/broker — mint/rotate this instance's broker key, revealed
     * ONCE. Owner-only. The instance presents this as a Bearer token to the MCP
     * gateway to reach its own connected stores; it decrypts nothing and can be
     * rotated or revoked here at any time.
     */
    public function broker($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        // A container app's key lives in its own conf/broker.ini, installed by core
        // (BrokerService::ensureContainerConfig). Minting one here replaces the row that file
        // matches and hands the key to nobody — the app is cut off from core.
        if (\Model_Instance::tenantRow($inst)) {
            $this->jsonError("{$inst->slug} runs in its own container; its broker key is installed there by core and is not handed out here.", 409);
            return;
        }

        // Advisory allowlist: the connectors this instance actually has connections
        // for, read from its own store.
        $keys = InstanceConnections::withInstall((int)$inst->id, function () {
            $k = [];
            foreach (Bean::find('connections', 'enabled = 1') as $c) {
                if ($c->connectorType) $k[(string)$c->connectorType] = true;
            }
            return $k;
        }, []);

        $res = BrokerService::mint((int)$inst->id, (int)$this->member->id, array_keys($keys));
        // requestHost(), not `?? 'tiknix.com'`. This hands the caller a CREDENTIAL and the
        // address to spend it at; inventing the address means handing someone a working key
        // pointed at an install that is not theirs. The sibling builder above was already
        // converted to refuse on a missing Host header — this one was missed.
        $this->jsonSuccess([
            'token'    => $res['token'],
            'endpoint' => ($this->requestIsHttps() ? 'https' : 'http') . '://'
                        . $this->requestHost() . '/mcp/message',
        ], 'Broker key minted — copy it now; it is shown only once.');
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
        $host = \app\Host::visitorHost();
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
        return \app\Host::https();
    }

    private function connectorRedirectUri(string $type): string {
        return ($this->requestIsHttps() ? 'https' : 'http') . '://' . $this->requestHost()
             . '/connections/callback/' . $type;
    }

    /** GET /connections/connect/<type>?id=&env=&shop= — start a registry connector's OAuth. */
    private function connectorConnect(string $type): void {
        $connector = ConnectorRegistry::get($type);
        if (!$connector) { Flight::redirect('/sidecar/app/workbench'); return; }
        if (($connector->meta()['auth_type'] ?? 'oauth') === 'api_key') {
            // api_key connectors take a pasted key via POST /connections/connectkey,
            // not the OAuth GET flow.
            $this->flash('error', ucfirst($type) . ' connects with a pasted API key, not a sign-in redirect.');
            Flight::redirect('/connections?id=' . (int)$this->getParam('id', 0)); return;
        }
        if (!$connector->isConfigured()) {
            $this->flash('error', ucfirst($type) . ' is not configured on this server.');
            Flight::redirect('/sidecar/app/workbench'); return;
        }
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/sidecar/app/workbench'); return; }
        if ($inst->isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { Flight::redirect('/sidecar/app/workbench'); return; }

        // The connect form POSTs now, because the custom-app fields carry a secret and a
        // GET would put it in the query string. A GET is still accepted for the plain
        // no-custom-app case (existing links, the handoff flow), which carries nothing
        // sensitive; anything with a body has to prove it came from our own form.
        if (Flight::request()->method === 'POST' && !$this->validateCSRF()) return;

        $env  = $this->normalizeEnv($this->getParam('env', 'production'));
        $shop = trim((string)$this->getParam('shop', ''));

        /* A merchant's OWN provider app, when they run one (a Shopify custom app). Both
           halves or neither — the connector refuses a half pair rather than completing it
           from the server ini, which would sign the redirect with one app's key and verify
           the callback with another's secret.

           Carried in the SESSION, never in the signed state: the state travels as a query
           parameter through the merchant's browser and the provider's servers, and an app
           secret does not belong in a URL, a log, or a Referer header. The callback already
           requires this same session for its double-submit check, so this adds nothing new
           to trust — and it is keyed by the state hash so a second connect attempt in
           another tab cannot hand its credentials to this one's callback. */
        $customApp = [
            'client_id'     => trim((string)$this->getParam('app_key', '')),
            'client_secret' => trim((string)$this->getParam('app_secret', '')),
        ];
        $hasCustom = $customApp['client_id'] !== '' || $customApp['client_secret'] !== '';

        /* Scopes to REQUEST. A merchant's custom app carries its own scope set, and asking
           for the shared app's list against it gets the whole authorisation rejected — so
           an app override without a scope override is only half a feature. Blank means
           "use the server default", which the connector resolves; it is not a secret, so
           unlike the key and secret it can ride along in the open. */
        $wantScopes = trim((string)$this->getParam('app_scopes', ''));

        // Configured-ness is per-attempt now: a store with its own app is connectable even
        // when the shared credentials are blank, and only the connector can judge that.
        $probe = $hasCustom ? ['app' => $customApp] : [];
        $ok = method_exists($connector, 'isConfiguredFor')
            ? $connector->isConfiguredFor($probe)
            : $connector->isConfigured();
        if (!$ok) {
            $this->flash('error', $hasCustom
                ? 'That custom app is incomplete — both an API key and an API secret are required.'
                : ucfirst($type) . ' is not configured on this server.');
            Flight::redirect('/connections?id=' . (int)$inst->id); return;
        }

        // The signed state is the ONLY source of identity at callback time.
        $state = OAuthStateService::issue([
            'provider'    => $type,
            'member_id'   => (int)$this->member->id,
            'instance_id' => (int)$inst->id,
            'environment' => $env,
            'shop'        => $shop,
        ]);
        // Double-submit: proves the callback lands in the same browser session.
        $_SESSION['oauth_state_hash'] = hash('sha256', $state);
        // Cleared before either is set, so a previous attempt's app or scopes can never
        // be picked up by this one's callback.
        unset($_SESSION['oauth_custom_app'], $_SESSION['oauth_scopes']);
        if ($hasCustom) {
            $_SESSION['oauth_custom_app'] = ['for' => hash('sha256', $state)] + $customApp;
        }
        if ($wantScopes !== '') {
            $_SESSION['oauth_scopes'] = ['for' => hash('sha256', $state), 'scopes' => $wantScopes];
        }

        try {
            $url = $connector->authorizeUrl([
                'state'        => $state,
                'redirect_uri' => $this->connectorRedirectUri($type),
                'shop'         => $shop,
                'app'          => $hasCustom ? $customApp : null,
                'scopes'       => $wantScopes,
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/connections?id=' . (int)$inst->id); return;
        }
        Flight::redirect($url);
    }

    /**
     * GET /connections/handoff/<type>?intent=… — instance-driven OAuth entry point.
     * Consumes the broker-minted signed intent (identity = the instance + its owner),
     * re-asserts ownership, sets the double-submit hash in a core session, and redirects
     * into the connector's OAuth. Public route (self-authenticates via the signed intent).
     */
    public function handoff($params = []): void {
        $type = strtolower((string)($params['operation']->name ?? ''));
        $connector = ConnectorRegistry::get($type);
        if (!$connector || !$connector->isConfigured()) { $this->handoffError('That connector is unavailable.'); return; }

        /* A connector that only ever uses the customer's own app has nothing to hand off.
           The whole point of this route is borrowing the platform's app because the project
           holds none — and for these there IS no platform app to borrow. Refused here, with
           somewhere to go, rather than proceeding on isConfigured() (true on core, where
           conf/<key>.ini still has legacy credentials) and dying later inside appFor with a
           message about a missing API key that names nothing the user can act on.

           When the last connector stops needing this route, the route goes with it. */
        if (method_exists($connector, 'requiresOwnApp') && $connector->requiresOwnApp()) {
            $this->handoffError(ucfirst($type) . ' connects through the merchant\'s own app, '
                . 'so it is set up inside your project rather than here — open Connections in '
                . 'the project and paste the app\'s API key and secret.');
            return;
        }

        $intent = (string)$this->getParam('intent', '');
        $claims = $intent !== '' ? OAuthStateService::verify($intent) : null;
        if (!$claims || (string)($claims['purpose'] ?? '') !== 'connect_handoff' || (string)($claims['provider'] ?? '') !== $type) {
            $this->handoffError('This connect link has expired or is invalid — start again from your instance.'); return;
        }
        $iid = (int)($claims['instance_id'] ?? 0);
        $mid = (int)($claims['member_id'] ?? 0);
        $inst = Bean::load('instance', $iid);
        if (!$inst->id || (int)$inst->memberId !== $mid) { $this->handoffError('That instance was not found.'); return; }

        // Re-sign as the OAuth state, carrying the handoff marker + return_url so the
        // callback knows to authenticate by the signed state (no core login) and return
        // to the instance. The double-submit hash lands in THIS browser's core session.
        $state = OAuthStateService::issue([
            'provider'    => $type,
            'member_id'   => $mid,
            'instance_id' => $iid,
            'environment' => $this->normalizeEnv($claims['environment'] ?? 'production'),
            'shop'        => (string)($claims['shop'] ?? ''),
            'handoff'     => true,
            'return_url'  => (string)($claims['return_url'] ?? ''),
        ]);
        $_SESSION['oauth_state_hash'] = hash('sha256', $state);
        try {
            $url = $connector->authorizeUrl([
                'state'        => $state,
                'redirect_uri' => $this->connectorRedirectUri($type),
                'shop'         => (string)($claims['shop'] ?? ''),
            ]);
        } catch (\Throwable $e) { $this->handoffError($e->getMessage()); return; }
        Flight::redirect($url);
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

    /** GET /connections/callback/<type> — registry connector OAuth redirect target. */
    private function connectorCallback(string $type): void {
        $connector = ConnectorRegistry::get($type);
        if (!$connector) { Flight::redirect('/sidecar/app/workbench'); return; }

        $state    = (string)$this->getParam('state', '');
        $claims   = $state !== '' ? OAuthStateService::verify($state) : null;
        $sessHash = (string)($_SESSION['oauth_state_hash'] ?? '');
        unset($_SESSION['oauth_state_hash']);

        if (!$claims
            || $sessHash === '' || !hash_equals($sessHash, hash('sha256', $state))
            || (string)($claims['provider'] ?? '') !== $type) {
            $this->flash('error', 'Authorization expired or invalid — please reconnect.');
            Flight::redirect('/sidecar/app/workbench'); return;
        }

        $iid     = (int)($claims['instance_id'] ?? 0);
        $mid     = (int)($claims['member_id'] ?? 0);
        $handoff = !empty($claims['handoff']);

        // Identity ALWAYS comes from the signed state. Handoff mode authenticates by
        // that state + the instance's broker-minted intent (no core login); the
        // control-plane mode additionally binds to the logged-in owner's session.
        $local = !empty($claims['local']);

        if ($handoff) {
            $inst = Bean::load('instance', $iid);
            if (!$inst->id || (int)$inst->memberId !== $mid) {
                $this->handoffError('You no longer own that instance.'); return;
            }
            $returnUrl = (string)($claims['return_url'] ?? '');
        } elseif ($local) {
            // This install IS the project, so there is no instance row to own and nothing
            // to look up. The state was signed with THIS install's key, which is what
            // makes it unforgeable here; the session hash above already proved the
            // callback landed in the browser that started it. Identity is still the
            // signed member, not the session's — the same rule as every other mode.
            if (!Flight::isLoggedIn()) { Flight::redirect('/auth/login'); return; }
            if ($mid !== (int)$this->member->id) {
                $this->flash('error', 'This authorization was started by a different account.');
                Flight::redirect('/connections'); return;
            }
            $inst = null;
            $returnUrl = '/connections';
        } else {
            if (!Flight::isLoggedIn()) { Flight::redirect('/auth/login'); return; }
            if ($mid !== (int)$this->member->id) {
                $this->flash('error', 'This authorization was started by a different account.');
                Flight::redirect('/sidecar/app/workbench'); return;
            }
            $inst = $this->ownedInstance($iid);
            if (!$inst) {
                $this->flash('error', 'You no longer own that instance.');
                Flight::redirect('/sidecar/app/workbench'); return;
            }
            $returnUrl = '/connections?id=' . $iid;
        }

        /* The custom app this dance began under, if any. Bound to THIS state hash: a
           second connect started in another tab must not have its credentials picked up
           by this callback. Read before use and cleared either way, so a stale pair
           cannot leak into a later, unrelated connect. */
        $customApp = null;
        $stash = $_SESSION['oauth_custom_app'] ?? null;
        unset($_SESSION['oauth_custom_app']);
        if (is_array($stash) && hash_equals((string)($stash['for'] ?? ''), hash('sha256', $state))) {
            $customApp = ['client_id' => (string)$stash['client_id'], 'client_secret' => (string)$stash['client_secret']];
        }

        // The scopes this dance ASKED for, bound to the same state. Needed here because
        // exchangeCode records what Shopify granted and falls back to what was requested
        // when the response omits it — the server default would be the wrong answer for a
        // store authorised under a custom app.
        $wantScopes = '';
        $sstash = $_SESSION['oauth_scopes'] ?? null;
        unset($_SESSION['oauth_scopes']);
        if (is_array($sstash) && hash_equals((string)($sstash['for'] ?? ''), hash('sha256', $state))) {
            $wantScopes = (string)$sstash['scopes'];
        }

        try {
            $payload = $connector->exchangeCode([
                'params'       => $_GET,
                'claims'       => $claims,
                'redirect_uri' => $this->connectorRedirectUri($type),
                // Same app as the authorize leg — the HMAC on this callback was computed
                // with its secret, so anything else fails verification.
                'app'          => $customApp,
                'scopes'       => $wantScopes,
            ]);
            // Remember WHICH app this store belongs to, so a later re-auth or token
            // refresh goes back to the merchant's app rather than silently to the shared
            // one — which would fail, and fail looking like the merchant's problem.
            if ($customApp) {
                $payload['app_key']    = $customApp['client_id'];
                $payload['app_secret'] = $customApp['client_secret'];
            }
            // Kept apart from payload['scopes'], which is what Shopify GRANTED. This is
            // what we ASKED for, and a re-auth has to ask for the same thing again —
            // requesting the granted set back would silently ratchet the store down to
            // whatever a partial grant happened to allow last time.
            if ($wantScopes !== '') $payload['app_scopes'] = $wantScopes;
            $this->upsertConnection($type, $claims, $payload);
        } catch (\Throwable $e) {
            error_log('[connections] ' . $type . ' callback failed: ' . $e->getMessage());
            if ($handoff) { $this->redirectBack($returnUrl, ['connect_error' => $type]); return; }
            $this->flash('error', ucfirst($type) . ' connection failed: ' . $e->getMessage());
            Flight::redirect($local ? '/connections' : '/connections?id=' . $iid); return;
        }
        /* Wire the instance so its app can reach this store immediately — no keys for the
           user to handle. Best-effort: never fail the connect over this.

           Skipped when the project ran its own dance: the broker exists to let a project
           reach a store whose token core is holding, and here the token is already in this
           install's own store. There is no custody to arrange. */
        if (!$local) {
            try {
                BrokerService::ensureContainerConfig($inst, $mid);
            } catch (\Throwable $e) {
                error_log('[connections] store wiring failed for instance ' . $iid . ': ' . $e->getMessage());
            }
        }
        if ($handoff) { $this->redirectBack($returnUrl, ['connected' => $type]); return; }
        $this->flash('success', ucfirst($type) . ' store connected.');
        Flight::redirect($local ? '/connections' : '/connections?id=' . $iid);
    }

    /** Redirect to a handoff return_url with a status query param (or core as a fallback). */
    private function redirectBack(string $returnUrl, array $params): void {
        if ($returnUrl === '' || !preg_match('#^https://#i', $returnUrl)) {
            Flight::redirect('/sidecar/app/workbench'); return;
        }
        $sep = strpos($returnUrl, '?') !== false ? '&' : '?';
        Flight::redirect($returnUrl . $sep . http_build_query($params));
    }

    /**
     * Upsert an encrypted connection for a registry connector. One row per
     * (member, instance, connector, environment, store) so a builder can hold
     * distinct dev / staging / production stores side by side.
     */
    /**
     * Store a completed connect against the instance it was started for.
     *
     * The routing was never the missing piece: the OAuth `state` has carried
     * instance_id since it was written (see the issue() calls above), so the
     * callback has always known whose connection this is. What was wrong is where
     * it put it — ConnectionStore::upsert writes core's shared table, so a
     * credential connected through this hub landed somewhere it could not travel
     * from. It goes to that instance's own store now.
     *
     * member_id is dropped, not lost: a connection belongs to the instance,
     * whoever happened to attach it. Keeping an owner would reintroduce the
     * question of whose connection this is, which is the question the move exists
     * to stop asking.
     *
     * Phase 3: it is PUSHED, not written. putForInstall opened the instance's file
     * from here, which only works while core shares a disk with it — the same
     * connect against a self-hosted instance wrote nothing and said nothing. The
     * push goes through that install's own /connectorapi/receive with its own
     * broker key, so core stores nothing and the door is the same either way.
     *
     * Throws rather than returning 0. Both callers already sit inside a try/catch
     * that reports the message to the person connecting, which is where a failed
     * connect belongs — not in a 0 that reads as "stored, id unknown".
     */
    private function upsertConnection(string $type, array $claims, array $payload, string $authType = 'oauth'): int {
        $payload['auth_type'] = $authType;
        $env = $this->normalizeEnv($claims['environment'] ?? 'production');

        /* A project that ran its own dance keeps the result. There is nothing to push:
           this install already owns the connections store the token belongs in, and
           ConnectorPush would try to deliver it to this very host over HTTP using a
           broker key issued for talking to core. Storing directly is not a shortcut —
           it is the only correct destination. */
        if (!empty($claims['local'])) {
            return \app\ConnectionStore::put($type, $env, $payload);
        }

        return \app\ConnectorPush::push((int) $claims['instance_id'], $type, $env, $payload);
    }

    /**
     * POST /connections/connectkey — connect an api_key-type registry connector
     * (e.g. Stripe) from a pasted secret/restricted key. The key is validated
     * against the provider BEFORE anything persists, then stored encrypted via
     * upsertConnection (EncryptionService) exactly like an OAuth token. JSON,
     * called via fetch like add()/disconnect().
     */
    public function connectkey($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        if ($inst->isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { $this->jsonError('Only root can configure the tiknix-core connection.', 403); return; }

        $type      = strtolower(trim((string)$this->getParam('type', '')));
        $connector = ConnectorRegistry::get($type);
        if (!$connector) { $this->jsonError('Unsupported connector', 400); return; }
        if (($connector->meta()['auth_type'] ?? 'oauth') !== 'api_key') {
            $this->jsonError(ucfirst($type) . ' does not connect with a pasted key.', 400); return;
        }

        $env = $this->normalizeEnv($this->getParam('env', 'production'));
        $key = trim((string)$this->getParam('key', ''));
        try {
            $payload = $connector->validateApiKey($key, $this->declaredFields($connector));
            $this->upsertConnection($type, [
                'member_id'   => (int)$this->member->id,
                'instance_id' => (int)$inst->id,
                'environment' => $env,
            ], $payload, 'api_key');
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400); return;
        }
        // Wire the instance so its app can reach this account immediately — no keys
        // for the user to handle. Best-effort: never fail the connect over this.
        try {
            BrokerService::ensureContainerConfig($inst, (int)$this->member->id);
        } catch (\Throwable $e) {
            error_log('[connections] store wiring failed for instance ' . (int)$inst->id . ': ' . $e->getMessage());
        }
        $this->jsonSuccess([
            'type'        => $type,
            'environment' => $env,
            'account'     => (string)($payload['external_name'] ?? $payload['external_eid'] ?? ''),
        ], ucfirst($type) . ' connected');
    }

    /**
     * Which instance's store, and which row in it, a hub action is aimed at.
     *
     * Ownership used to be `$conn->memberId === $this->member->id`, a column that no
     * longer exists: a per-instance store records no owner, because everything in the
     * file belongs to that instance already. The check that replaces it is STRONGER --
     * ownedInstance() proves this member owns the instance, and only then do we open
     * its file. A cid belonging to somebody else is not in that database to find.
     *
     * @return array{0:int,1:int}|null [instanceId, connectionId], or null having sent the error
     */
    private function hubTarget(bool $remoteOk = false): ?array {
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found.', 404); return null; }
        if (!$remoteOk && $this->appOwnsThis($inst)) return null;
        $cid = (int)$this->getParam('cid', 0);
        if ($cid <= 0) { $this->jsonError('Connection not found', 404); return null; }
        return [(int)$inst->id, $cid];
    }

    /** POST /connections/disconnect — remove a stored connection. */
    public function disconnect($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        if (($t = $this->hubTarget(true)) === null) return;
        [$iid, $cid] = $t;

        if (\Model_Instance::tenantRow(Bean::load('instance', $iid))) {
            try { \app\ConnectorPush::ask($iid, '/connectorapi/disconnect', ['id' => $cid]); }
            catch (\RuntimeException $e) { $this->jsonError($e->getMessage(), 502); return; }
            $this->jsonSuccess([], 'Disconnected');
            return;
        }

        $gone = InstanceConnections::withInstall($iid, function () use ($cid) {
            $conn = Bean::load('connections', $cid);
            if (!$conn->id) return false;
            Bean::trash($conn);
            return true;
        }, false);

        if (!$gone) { $this->jsonError('Connection not found', 404); return; }
        $this->jsonSuccess([], 'Disconnected');
    }

    /**
     * POST /connections/webhooksecret — set (or clear) a connection's webhook
     * verification secret, stored ENCRYPTED on the connection. Each payment connector
     * interprets it its own way (Stripe whsec HMAC, Square signature key, PayPal id).
     */
    public function webhooksecret($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        // INSTALL-LOCAL, and forced -- the same constraint as githubwebhook(). This
        // secret is sealed with EncryptionService, i.e. the running install's APP key,
        // and it is opened by that install's own /webhook/* handler. Core setting it
        // for an instance would seal it with core's key and hand the instance a value
        // it can never verify against -- and the failure would show up as an HMAC
        // mismatch on a live webhook, nowhere near the button that caused it.
        $this->jsonError('Set the webhook secret from the instance\'s own Connections page: '
            . 'it is encrypted with that install\'s key, which the control plane does not hold.', 409);
    }

    /**
     * POST /connections/publishfeed — publish (or unpublish) a PUBLIC social showcase
     * at /social/<slug> for a Social-category connection the member owns. Does a
     * best-effort immediate fetch; scripts/sync-social-feeds.php keeps it fresh + mirrors
     * media locally.
     */
    public function publishfeed($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        if (($t = $this->hubTarget()) === null) return;
        [$iid, $cid] = $t;

        // The connection lives in the instance's file; socialpage lives HERE, because
        // /social/<slug> is served by core. So the credential-shaped work happens
        // inside withInstall and only plain values come back out.
        // The bean comes back out and the token with it. Reading a bean outside its
        // database is fine -- it is store() that writes to whatever is selected, which
        // is why nothing here saves it. The token must be decrypted inside, while the
        // instance's key is the one in scope.
        $src = InstanceConnections::withInstall($iid, function () use ($cid) {
            $conn = Bean::load('connections', $cid);
            if (!$conn->id) return null;
            return ['conn' => $conn, 'token' => ConnectionStore::ownToken($conn)];
        }, null);

        if ($src === null) { $this->jsonError('Connection not found', 404); return; }
        $conn = $src['conn'];

        $connector = ConnectorRegistry::get((string)$conn->connectorType);
        if (!$connector || (string)($connector->meta()['category'] ?? '') !== 'Social') {
            $this->jsonError('This connection is not a social feed.', 409); return;
        }
        $meta = json_decode((string)($conn->metadataJson ?: '{}'), true) ?: [];

        $slug = strtolower(trim((string)$this->getParam('slug', '')));
        if ($slug === '') $slug = strtolower((string)($meta['username'] ?? ''));
        $slug = preg_replace('/[^a-z0-9_.-]/', '', (string)$slug);
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,49}$/', $slug)) {
            $this->jsonError('Choose a valid page name (letters, numbers, . _ -).', 400); return;
        }
        // The slug must be free, unless it already belongs to this member.
        $taken = Bean::findOne('socialpage', 'slug = ? AND member_id != ?', [$slug, (int)$this->member->id]);
        if ($taken && $taken->id) { $this->jsonError('That page name is taken — pick another.', 409); return; }

        // instance_ref is what makes connection_ref findable again: a connection id is
        // only unique WITHIN one instance's file now, so the pair identifies it and a
        // bare id does not. Both are _ref, not _id -- the bean type is plural
        // ('connections'), so connection_id would have RedBean chasing a bean type
        // 'connection' that does not exist, and the instance is hard-deleted on
        // teardown, which a real FK would forbid.
        $page = Bean::findOne('socialpage', 'member_id = ? AND instance_ref = ? AND connection_ref = ?',
            [(int)$this->member->id, $iid, $cid]);
        if (!$page || !$page->id) { $page = Bean::dispense('socialpage'); $page->createdAt = date('Y-m-d H:i:s'); $page->feedJson = '[]'; }
        $page->memberId      = (int)$this->member->id;
        $page->instanceRef   = $iid;
        $page->connectionRef = $cid;
        $page->slug         = $slug;
        $page->title        = trim((string)$this->getParam('title', '')) ?: ('@' . ltrim((string)($meta['username'] ?? $conn->externalName), '@'));
        $page->handle       = (string)($meta['username'] ?? ltrim((string)$conn->externalName, '@'));
        $page->externalUrl  = (string)$conn->externalUrl;
        $page->maxItems     = max(1, min(60, (int)$this->getParam('max_items', 30)));
        $page->published    = filter_var($this->getParam('published', '1'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $page->updatedAt    = date('Y-m-d H:i:s');
        Bean::store($page);

        // Best-effort immediate fetch so the page isn't empty (cron mirrors media later).
        $count = 0;
        try {
            $token = $src['token'];
            if ($token === '') throw new \Exception('the stored token could not be decrypted on that instance');
            $feed  = $connector->fetchFeed($conn, $token, ['limit' => (int)$page->maxItems]);
            $page->feedJson = json_encode(array_values($feed['items'] ?? []), JSON_UNESCAPED_SLASHES);
            $page->syncedAt = date('Y-m-d H:i:s');
            Bean::store($page);
            $count = count($feed['items'] ?? []);
            if (function_exists('sodium_memzero')) sodium_memzero($token);
        } catch (\Throwable $e) { /* leave empty; the cron / a reconnect will fill it */ }

        $base = app_url();
        $this->jsonSuccess([
            'published' => (bool)$page->published,
            'url'       => $base . '/social/' . $slug,
            'items'     => $count,
        ], $page->published ? 'Showcase published' : 'Showcase updated');
    }

}
