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
use app\GitHubPublisher;
use app\OAuthStateService;
use app\BrokerService;
use app\services\connectors\ConnectorRegistry;
use RedBeanPHP\R;

class Connections extends Control {

    private const APP = 'tiknix';

    private function instanceDir(string $sub): string {
        return '/var/www/html/default/' . $sub . '.' . self::APP;
    }

    /** Load an instance the current member owns and that exists on disk. */
    private function ownedInstance($id) {
        $id = (int)$id;
        if (!$id) return null;
        $inst = R::load('instance', $id);
        if (!$inst->id) return null;
        if ((int)$inst->memberId !== (int)$this->member->id) return null;
        if (!is_file($this->instanceDir($inst->slug) . '/public/index.php')) return null;
        return $inst;
    }

    /** The enabled GitHub connection bound to member + instance, or null. */
    private function githubConn(int $instanceId) {
        return Bean::findOne('connections',
            'member_id = ? AND instance_id = ? AND connector_type = ? AND enabled = 1',
            [(int)$this->member->id, $instanceId, 'github']);
    }

    private function connSummary($conn): array {
        $meta = json_decode(($conn->metadataJson ?: '{}') ?? '', true) ?: [];
        return [
            'id'          => (int)$conn->id,
            'type'        => $conn->connectorType,
            'repo'        => ($meta['owner'] ?? '') . '/' . ($meta['repo'] ?? ''),
            'defaultBranch' => $meta['defaultBranch'] ?? 'main',
            'autoPublish' => !empty($meta['autoPublish']),
            'enabled'     => (int)$conn->enabled === 1,
            'lastUsed'    => $conn->lastUsedAt,
            'lastError'   => $conn->lastError,
        ];
    }

    // --- routes ---------------------------------------------------------------

    /** GET /connections/setup?id=<instance> — guided connect page (new tab). */
    public function setup($params = []): void {
        if (!$this->requireLogin()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/aibuilder'); return; }
        // Default (tiknix-core) instances publish back to main — root only.
        $isDefault = (bool)$inst->isDefault;
        if ($isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { Flight::redirect('/aibuilder'); return; }
        $conn  = $this->githubConn((int)$inst->id);
        $oauth = (string)$this->getParam('oauth', '');
        $pendingOauth = $oauth === '1'
            && !empty($_SESSION['gh_oauth']['token'])
            && (int)($_SESSION['gh_oauth']['instance_id'] ?? 0) === (int)$inst->id;
        $this->render('connections/setup', [
            'instance'     => $inst,
            'connection'   => $conn && $conn->id ? $this->connSummary($conn) : null,
            'isDefault'    => $isDefault,
            'prefill'      => $isDefault ? GitHubPublisher::mainGithubRepo() : null,
            'oauthEnabled' => $this->oauthEnabled(),
            'oauthReturn'  => $pendingOauth,
            'oauthError'   => $oauth === 'err',
        ]);
    }

    /** GET /connections/status?id=<instance> — JSON: is this instance connected? */
    public function status($params = []): void {
        if (!$this->requireLogin()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        $conn = $this->githubConn((int)$inst->id);
        $this->jsonSuccess([
            'connected'  => (bool)($conn && $conn->id),
            'connection' => $conn && $conn->id ? $this->connSummary($conn) : null,
        ]);
    }

    /** Parse a GitHub repo URL/spec into [owner, repo]. Accepts https://, git@, or owner/repo. */
    private function parseRepoSpec(string $s): array {
        $s = trim($s);
        if ($s === '') return ['', ''];
        // https://github.com/owner/repo(.git)  or  git@github.com:owner/repo(.git)
        if (preg_match('~github\.com[:/]+([^/]+)/([^/#?]+?)(?:\.git)?/?$~i', $s, $m)) {
            return [$m[1], $m[2]];
        }
        // owner/repo shorthand
        if (preg_match('~^([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+?)(?:\.git)?$~', $s, $m)) {
            return [$m[1], $m[2]];
        }
        return ['', ''];
    }

    /** POST /connections/add — store/replace a GitHub PAT connection for an instance. */
    public function add($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        if ($inst->isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { $this->jsonError('Only root can configure the tiknix-core connection.', 403); return; }

        $type = strtolower(trim((string)$this->getParam('type', 'github')));
        if ($type !== 'github') { $this->jsonError('Unsupported connector', 400); return; }

        $owner   = trim((string)$this->getParam('owner', ''));
        $repo    = preg_replace('/\.git$/', '', trim((string)$this->getParam('repo', '')));
        $repoUrl = trim((string)$this->getParam('repo_url', ''));
        if ($repoUrl !== '') { [$owner, $repo] = $this->parseRepoSpec($repoUrl); }
        $auto    = filter_var($this->getParam('auto_publish', false), FILTER_VALIDATE_BOOLEAN);

        // Token source: a freshly-completed OAuth authorization (preferred) or a pasted PAT.
        $useOauth = filter_var($this->getParam('use_oauth', false), FILTER_VALIDATE_BOOLEAN);
        $authType = 'token';
        if ($useOauth) {
            $sess = $_SESSION['gh_oauth'] ?? null;
            if (!$sess || empty($sess['token']) || (int)($sess['instance_id'] ?? 0) !== (int)$inst->id) {
                $this->jsonError('GitHub authorization expired — reconnect.', 400); return;
            }
            $pat = (string)$sess['token'];
            $authType = 'oauth';
        } else {
            $pat = trim((string)$this->getParam('token', ''));
        }
        if ($pat === '' || $owner === '' || $repo === '') {
            $this->jsonError('A token/authorization and a valid repository URL (https://github.com/owner/repo) are required', 400); return;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $owner) || !preg_match('/^[A-Za-z0-9._-]+$/', $repo)) {
            $this->jsonError('Invalid owner/repo format', 400); return;
        }

        // Validate the PAT against the repo before persisting anything.
        try {
            $gh = new GitHubService($pat, $owner, $repo);
            $r = $gh->getRepository();
            $defaultBranch = $r['default_branch'] ?? 'main';
            $fullName      = $r['full_name'] ?? ($owner . '/' . $repo);
        } catch (\Throwable $e) {
            $this->jsonError('GitHub rejected the token/repo: ' . $e->getMessage(), 400); return;
        }

        // One GitHub connection per instance — upsert.
        $conn = $this->githubConn((int)$inst->id);
        if (!$conn || !$conn->id) $conn = Bean::dispense('connections');
        $now = date('Y-m-d H:i:s');
        $conn->connectorType  = 'github';
        $conn->memberId       = (int)$this->member->id;
        $conn->instanceId     = (int)$inst->id;
        $conn->authType       = $authType;
        $conn->accessToken    = EncryptionService::encrypt($pat);
        $conn->tokenType      = 'Bearer';
        $conn->externalEid    = $fullName;
        $conn->externalName   = $fullName;
        $conn->externalUrl    = 'https://github.com/' . $owner . '/' . $repo;
        $conn->connectionName = $fullName;
        $conn->metadataJson   = json_encode(['owner' => $owner, 'repo' => $repo, 'defaultBranch' => $defaultBranch, 'autoPublish' => $auto]);
        $conn->enabled        = 1;
        $conn->shared         = 0;
        $conn->lastError      = null;
        $conn->lastUsedAt     = $now;
        if (!$conn->id) $conn->createdAt = $now;
        $conn->updatedAt      = $now;
        Bean::store($conn);
        if ($useOauth) unset($_SESSION['gh_oauth']);

        $this->jsonSuccess([
            'id'            => (int)$conn->id,
            'repo'          => $fullName,
            'defaultBranch' => $defaultBranch,
            'authType'      => $authType,
        ], 'GitHub connected');
    }

    // --- OAuth (GitHub App) ---------------------------------------------------

    private function githubOAuthConfig(): array {
        $ini = @parse_ini_file(dirname(__DIR__) . '/conf/github.ini', true) ?: [];
        $o = $ini['oauth'] ?? [];
        return [
            'client_id'     => (string)($o['client_id'] ?? ''),
            'client_secret' => (string)($o['client_secret'] ?? ''),
            'scope'         => (string)($o['scope'] ?? 'repo read:user'),
        ];
    }

    private function oauthEnabled(): bool {
        $c = $this->githubOAuthConfig();
        return $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    private function redirectUri(): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host . '/connections/callback/github';
    }

    /** Exchange an OAuth code for an access token. Returns token or null. */
    private function githubExchangeCode(string $code, array $cfg): ?string {
        $ch = curl_init('https://github.com/login/oauth/access_token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: tiknix-aibuilder'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri(),
            ]),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $j = json_decode(($resp ?: '') ?? '', true);
        $tok = is_array($j) ? ($j['access_token'] ?? '') : '';
        return $tok !== '' ? $tok : null;
    }

    /** List the authorized user's pushable repos. */
    private function githubUserRepos(string $token): array {
        $ch = curl_init('https://api.github.com/user/repos?per_page=100&sort=updated&affiliation=owner,collaborator,organization_member');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . $token, 'User-Agent: tiknix-aibuilder'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $arr  = json_decode(($resp ?: '') ?? '', true) ?: [];
        $out  = [];
        foreach ($arr as $r) {
            if (!empty($r['full_name']) && !empty($r['permissions']['push'])) {
                $out[] = ['full_name' => $r['full_name'], 'default_branch' => $r['default_branch'] ?? 'main', 'private' => !empty($r['private'])];
            }
        }
        return $out;
    }

    /** GET /connections/connect/github?id=<instance> — start the OAuth flow. */
    public function connect($params = []): void {
        if (!$this->requireLogin()) return;
        $type = strtolower((string)($params['operation']->name ?? 'github'));
        if ($type !== 'github') { $this->connectorConnect($type); return; }
        if (!$this->oauthEnabled()) { Flight::redirect('/aibuilder'); return; }
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/aibuilder'); return; }
        if ($inst->isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { Flight::redirect('/aibuilder'); return; }

        $state = bin2hex(random_bytes(16));
        $_SESSION['gh_oauth'] = ['state' => $state, 'instance_id' => (int)$inst->id, 'ts' => time()];
        $cfg = $this->githubOAuthConfig();
        Flight::redirect('https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id'    => $cfg['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope'        => $cfg['scope'],
            'state'        => $state,
            'allow_signup' => 'false',
        ]));
    }

    /** GET /connections/callback/github?code=&state= — OAuth redirect target. */
    public function callback($params = []): void {
        $type  = strtolower((string)($params['operation']->name ?? 'github'));
        if ($type !== 'github') { $this->connectorCallback($type); return; }
        $sess  = $_SESSION['gh_oauth'] ?? null;
        $state = (string)$this->getParam('state', '');
        $code  = (string)$this->getParam('code', '');
        if ($type !== 'github' || !$sess || $state === '' || !hash_equals((string)($sess['state'] ?? ''), $state) || $code === '') {
            unset($_SESSION['gh_oauth']); Flight::redirect('/aibuilder'); return;
        }
        if (!Flight::isLoggedIn()) { Flight::redirect('/auth/login'); return; }

        $iid   = (int)($sess['instance_id'] ?? 0);
        $token = $this->githubExchangeCode($code, $this->githubOAuthConfig());
        if (!$token) { unset($_SESSION['gh_oauth']); Flight::redirect('/connections/setup?id=' . $iid . '&oauth=err'); return; }

        // Stash the token for the repo-picker step; cleared once the connection is saved.
        $_SESSION['gh_oauth']['token'] = $token;
        Flight::redirect('/connections/setup?id=' . $iid . '&oauth=1');
    }

    /** GET /connections/repos — the authorized user's repos (pending OAuth token). JSON. */
    public function repos($params = []): void {
        if (!$this->requireLogin()) return;
        $sess = $_SESSION['gh_oauth'] ?? null;
        if (!$sess || empty($sess['token'])) { $this->jsonError('No pending GitHub authorization', 400); return; }
        $this->jsonSuccess(['repos' => $this->githubUserRepos((string)$sess['token'])]);
    }

    // --- Generic connector OAuth (registry-driven; e.g. Shopify) --------------

    /** GET /connections[?id=<instance>] — connections hub; defaults to the member's most-recent store. */
    public function index($params = []): void {
        if (!$this->requireLogin()) return;
        // Inside an instance there is no owner/instance picker — show the read-only
        // list of what this app is connected to (metadata via the broker).
        if (!builder_tools_enabled()) { $this->instanceConnections(); return; }
        // The member's instances (most-recent first) drive the store picker and the
        // default when no ?id= is given, so /connections never dead-ends to /aibuilder.
        $instances = R::find('instance', 'member_id = ? ORDER BY created_at DESC', [(int)$this->member->id]);
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) {
            foreach ($instances as $cand) { if ($ok = $this->ownedInstance((int)$cand->id)) { $inst = $ok; break; } }
        }
        if (!$inst) { Flight::redirect('/aibuilder'); return; }

        // A just-completed connect (a prior request) writes a connections row; bust
        // the cache before reading so a newly-connected store shows on the FIRST view
        // instead of after a second connect / cache TTL.
        $ad = Flight::get('cachedDatabaseAdapter');
        if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('connections');

        $rows = Bean::find('connections',
            'member_id = ? AND instance_id = ? ORDER BY connector_type, environment',
            [(int)$this->member->id, (int)$inst->id]);
        $byType = [];
        foreach ($rows as $c) {
            // Decrypt just far enough to show the last 4 chars as a which-secret hint
            // (never the whole value); the fields themselves stay write-only.
            $whHint = '';
            $enc = (string)($c->webhookSecret ?? '');
            if ($enc !== '') {
                try {
                    $plain = (string)EncryptionService::decrypt($enc);
                    if ($plain !== '') $whHint = substr($plain, -4);
                } catch (\Throwable $e) { $whHint = ''; }
            }
            $keyHint = '';
            $encKey = (string)($c->accessToken ?? '');
            if ($encKey !== '') {
                try {
                    $plainKey = (string)EncryptionService::decrypt($encKey);
                    if ($plainKey !== '') $keyHint = substr($plainKey, -4);
                } catch (\Throwable $e) { $keyHint = ''; }
            }
            $byType[(string)$c->connectorType][] = [
                'id'          => (int)$c->id,
                'environment' => $c->environment ?: 'production',
                'name'        => $c->externalName ?: $c->externalEid,
                'eid'         => $c->externalEid,
                'url'         => $c->externalUrl,
                'enabled'     => (int)$c->enabled === 1,
                'revoked'     => !empty($c->revokedAt),
                'lastError'   => $c->lastError,
                'webhookSet'  => $enc !== '',
                'webhookHint' => $whHint,
                'keyHint'     => $keyHint,
            ];
        }

        // Unified connector cards: GitHub (deploy) first, then every registry
        // connector. Each card carries its own existing connections so the hub
        // shows connect-vs-connected state inline — one place, nothing hidden.
        $cards = [[
            'key'          => 'github',
            'label'        => 'GitHub',
            'blurb'        => 'Publish this instance to your own GitHub repo as a branch and pull request.',
            'category'     => 'Deploy',
            'icon'         => 'github',
            'color'        => 'dark',
            'auth_type'    => 'github',
            'connect_kind' => 'github',
            'configured'   => true,
            'features'     => ['Publish', 'Pull requests', 'Your repo'],
            'manage_url'   => '/connections/setup?id=' . (int)$inst->id,
            'connections'  => $byType['github'] ?? [],
        ]];
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
                'manage_url'   => null,
                'connections'  => $byType[$conn->key()] ?? [],
            ];
        }

        $this->render('connections/index', [
            'title'          => 'Connections',
            'instance'       => $inst,
            'instances'      => $instances,
            'cards'          => $cards,
            // Only for the GitHub deploy-webhook hint ("a push fires N pipelines");
            // the pipelines themselves are shown on /integrations, not here.
            'pipelines'      => \app\InstanceAutomations::pipelines($this->instanceDir($inst->slug)),
            'environments'   => ['development', 'production'],
            'categoryOrder'  => ['Deploy', 'Payments', 'Stores', 'Social', 'Other'],
        ]);
    }

    /** Inside an instance: read-only list of what this app is connected to (broker). */
    private function instanceConnections(): void {
        if (!Flight::hasLevel(LEVELS['ADMIN'])) { Flight::redirect('/dashboard'); return; }
        $root = dirname(__DIR__);
        $broker     = \app\InstanceAutomations::brokerConnections($root);
        $connectors = \app\InstanceAutomations::connectors($root);
        $this->render('connections/instance', [
            'title'           => 'Connections',
            'connections'     => $broker['connections'] ?? [],
            'brokerError'     => $broker['error'] ?? '',
            'connectors'      => $connectors['connectors'] ?? [],
            'connectorsError' => $connectors['error'] ?? '',
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
        $returnUrl = rtrim((string)(Flight::get('app.baseurl') ?: ''), '/') . '/connections';
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
        if ($type === '' || $key === '') { $this->jsonError('Connector and key are required.', 400); return; }
        $r = \app\InstanceAutomations::connectKey(dirname(__DIR__), $type, $env, $key);
        if (!empty($r['error'])) { $this->jsonError($r['error'], 400); return; }
        $this->jsonSuccess($r['data'] ?? [], ucfirst($type) . ' connected.');
    }

    /** POST /connections/instancedisconnect — instance-driven disconnect (owner/admin). JSON. */
    public function instancedisconnect($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->instanceManageGuard(true)) return;
        if (!$this->validateCSRF()) return;
        $cid = (int)$this->getParam('cid', 0);
        if ($cid <= 0) { $this->jsonError('connection id required.', 400); return; }
        $r = \app\InstanceAutomations::disconnectConnection(dirname(__DIR__), $cid);
        if (!empty($r['error'])) { $this->jsonError($r['error'], 400); return; }
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

    /** POST /connections/pipelinerun — trigger one of the instance's pipelines (owner-scoped). */
    public function pipelinerun($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('Instance not found.', 404); return; }
        $slug = (string) $this->getParam('slug');
        if ($slug === '') { Flight::jsonError('slug is required.', 400); return; }
        $res = \app\InstanceAutomations::trigger($this->instanceDir($inst->slug), $slug);
        if (!empty($res['error'])) { Flight::jsonError($res['error'], 400); return; }
        Flight::jsonSuccess(['run_id' => $res['run_id']], 'Pipeline triggered.');
    }

    /**
     * POST /connections/githubwebhook — provision the repo's push→deploy webhook so a
     * push to GitHub fires this instance's trigger.github pipelines. Mints a secret,
     * (re)creates the hook via the GitHub API pointing at /webhook/github, and stores
     * the secret encrypted on the connection. Owner-scoped.
     */
    public function githubwebhook($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('Instance not found.', 404); return; }

        $conn = Bean::findOne('connections',
            "member_id = ? AND instance_id = ? AND connector_type = 'github' AND enabled = 1",
            [(int) $this->member->id, (int) $inst->id]);
        if (!$conn || !$conn->id) { Flight::jsonError('Connect a GitHub repo to this instance first.', 400); return; }

        $meta  = json_decode((string) ($conn->metadataJson ?: '{}'), true) ?: [];
        $owner = (string) ($meta['owner'] ?? ''); $repo = (string) ($meta['repo'] ?? '');
        if ($owner === '' || $repo === '') { Flight::jsonError('This GitHub connection has no owner/repo.', 400); return; }

        $callback = rtrim((string) (Flight::get('app.baseurl') ?: 'https://tiknix.com'), '/') . '/webhook/github';
        try {
            $pat = (string) EncryptionService::decrypt((string) $conn->accessToken);
            $gh  = new GitHubService($pat, $owner, $repo);
            $secret   = bin2hex(random_bytes(20));
            $existing = $gh->findWebhook($callback);
            if ($existing) { $gh->updateWebhook((int) $existing['id'], $callback, $secret); }
            else           { $gh->createWebhook($callback, $secret, ['push']); }
            $conn->webhookSecret = EncryptionService::encrypt($secret);
            $conn->updatedAt = date('Y-m-d H:i:s');
            Bean::store($conn);
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

        // Advisory allowlist: the connectors this instance actually has connections for.
        $conns = Bean::find('connections', 'instance_id = ? AND enabled = 1', [(int)$inst->id]);
        $keys = [];
        foreach ($conns as $c) { if ($c->connectorType) $keys[(string)$c->connectorType] = true; }

        $res = BrokerService::mint((int)$inst->id, (int)$this->member->id, array_keys($keys));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host  = $_SERVER['HTTP_HOST'] ?? 'tiknix.com';
        $this->jsonSuccess([
            'token'    => $res['token'],
            'endpoint' => ($https ? 'https' : 'http') . '://' . $host . '/mcp/message',
        ], 'Broker key minted — copy it now; it is shown only once.');
    }

    /** Constrain a free-text environment to the known set; default production. */
    private function normalizeEnv($env): string {
        $env = strtolower(trim((string)$env));
        return in_array($env, ['development', 'staging', 'production'], true) ? $env : 'production';
    }

    /** The exact, provider-allowlisted callback URL for a connector on this host. */
    private function connectorRedirectUri(string $type): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host . '/connections/callback/' . $type;
    }

    /** GET /connections/connect/<type>?id=&env=&shop= — start a registry connector's OAuth. */
    private function connectorConnect(string $type): void {
        $connector = ConnectorRegistry::get($type);
        if (!$connector) { Flight::redirect('/aibuilder'); return; }
        if (($connector->meta()['auth_type'] ?? 'oauth') === 'api_key') {
            // api_key connectors take a pasted key via POST /connections/connectkey,
            // not the OAuth GET flow.
            $this->flash('error', ucfirst($type) . ' connects with a pasted API key, not a sign-in redirect.');
            Flight::redirect('/connections?id=' . (int)$this->getParam('id', 0)); return;
        }
        if (!$connector->isConfigured()) {
            $this->flash('error', ucfirst($type) . ' is not configured on this server.');
            Flight::redirect('/aibuilder'); return;
        }
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/aibuilder'); return; }
        if ($inst->isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { Flight::redirect('/aibuilder'); return; }

        $env  = $this->normalizeEnv($this->getParam('env', 'production'));
        $shop = trim((string)$this->getParam('shop', ''));

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

        try {
            $url = $connector->authorizeUrl([
                'state'        => $state,
                'redirect_uri' => $this->connectorRedirectUri($type),
                'shop'         => $shop,
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

        $intent = (string)$this->getParam('intent', '');
        $claims = $intent !== '' ? OAuthStateService::verify($intent) : null;
        if (!$claims || (string)($claims['purpose'] ?? '') !== 'connect_handoff' || (string)($claims['provider'] ?? '') !== $type) {
            $this->handoffError('This connect link has expired or is invalid — start again from your instance.'); return;
        }
        $iid = (int)($claims['instance_id'] ?? 0);
        $mid = (int)($claims['member_id'] ?? 0);
        $inst = R::load('instance', $iid);
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

    /** GET /connections/callback/<type> — registry connector OAuth redirect target. */
    private function connectorCallback(string $type): void {
        $connector = ConnectorRegistry::get($type);
        if (!$connector) { Flight::redirect('/aibuilder'); return; }

        $state    = (string)$this->getParam('state', '');
        $claims   = $state !== '' ? OAuthStateService::verify($state) : null;
        $sessHash = (string)($_SESSION['oauth_state_hash'] ?? '');
        unset($_SESSION['oauth_state_hash']);

        if (!$claims
            || $sessHash === '' || !hash_equals($sessHash, hash('sha256', $state))
            || (string)($claims['provider'] ?? '') !== $type) {
            $this->flash('error', 'Authorization expired or invalid — please reconnect.');
            Flight::redirect('/aibuilder'); return;
        }

        $iid     = (int)($claims['instance_id'] ?? 0);
        $mid     = (int)($claims['member_id'] ?? 0);
        $handoff = !empty($claims['handoff']);

        // Identity ALWAYS comes from the signed state. Handoff mode authenticates by
        // that state + the instance's broker-minted intent (no core login); the
        // control-plane mode additionally binds to the logged-in owner's session.
        if ($handoff) {
            $inst = R::load('instance', $iid);
            if (!$inst->id || (int)$inst->memberId !== $mid) {
                $this->handoffError('You no longer own that instance.'); return;
            }
            $returnUrl = (string)($claims['return_url'] ?? '');
        } else {
            if (!Flight::isLoggedIn()) { Flight::redirect('/auth/login'); return; }
            if ($mid !== (int)$this->member->id) {
                $this->flash('error', 'This authorization was started by a different account.');
                Flight::redirect('/aibuilder'); return;
            }
            $inst = $this->ownedInstance($iid);
            if (!$inst) {
                $this->flash('error', 'You no longer own that instance.');
                Flight::redirect('/aibuilder'); return;
            }
            $returnUrl = '/connections?id=' . $iid;
        }

        try {
            $payload = $connector->exchangeCode([
                'params'       => $_GET,
                'claims'       => $claims,
                'redirect_uri' => $this->connectorRedirectUri($type),
            ]);
            $this->upsertConnection($type, $claims, $payload);
        } catch (\Throwable $e) {
            error_log('[connections] ' . $type . ' callback failed: ' . $e->getMessage());
            if ($handoff) { $this->redirectBack($returnUrl, ['connect_error' => $type]); return; }
            $this->flash('error', ucfirst($type) . ' connection failed: ' . $e->getMessage());
            Flight::redirect('/connections?id=' . $iid); return;
        }
        // Wire the instance so its app can reach this store immediately — no keys
        // for the user to handle. Best-effort: never fail the connect over this.
        try {
            BrokerService::ensureInstanceConfig($iid, $mid, $this->instanceDir($inst->slug));
        } catch (\Throwable $e) {
            error_log('[connections] store wiring failed for instance ' . $iid . ': ' . $e->getMessage());
        }
        if ($handoff) { $this->redirectBack($returnUrl, ['connected' => $type]); return; }
        $this->flash('success', ucfirst($type) . ' store connected.');
        Flight::redirect('/connections?id=' . $iid);
    }

    /** Redirect to a handoff return_url with a status query param (or core as a fallback). */
    private function redirectBack(string $returnUrl, array $params): void {
        if ($returnUrl === '' || !preg_match('#^https://#i', $returnUrl)) {
            Flight::redirect('/aibuilder'); return;
        }
        $sep = strpos($returnUrl, '?') !== false ? '&' : '?';
        Flight::redirect($returnUrl . $sep . http_build_query($params));
    }

    /**
     * Upsert an encrypted connection for a registry connector. One row per
     * (member, instance, connector, environment, store) so a builder can hold
     * distinct dev / staging / production stores side by side.
     */
    private function upsertConnection(string $type, array $claims, array $payload, string $authType = 'oauth'): int {
        return \app\ConnectionStore::upsert(
            $type,
            (int)$claims['member_id'],
            (int)$claims['instance_id'],
            $this->normalizeEnv($claims['environment'] ?? 'production'),
            $payload,
            $authType
        );
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
            $payload = $connector->validateApiKey($key);
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
            BrokerService::ensureInstanceConfig((int)$inst->id, (int)$this->member->id, $this->instanceDir($inst->slug));
        } catch (\Throwable $e) {
            error_log('[connections] store wiring failed for instance ' . (int)$inst->id . ': ' . $e->getMessage());
        }
        $this->jsonSuccess([
            'type'        => $type,
            'environment' => $env,
            'account'     => (string)($payload['external_name'] ?? $payload['external_eid'] ?? ''),
        ], ucfirst($type) . ' connected');
    }

    /** POST /connections/test — re-validate a stored connection. */
    public function test($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $conn = Bean::load('connections', (int)$this->getParam('cid', 0));
        if (!$conn->id || (int)$conn->memberId !== (int)$this->member->id) { $this->jsonError('Connection not found', 404); return; }
        $meta = json_decode(($conn->metadataJson ?: '{}') ?? '', true) ?: [];
        try {
            $pat = EncryptionService::decrypt($conn->accessToken);
            $gh  = new GitHubService($pat, $meta['owner'] ?? '', $meta['repo'] ?? '');
            $r   = $gh->getRepository();
            $conn->lastUsedAt = date('Y-m-d H:i:s'); $conn->lastError = null; Bean::store($conn);
            $this->jsonSuccess(['repo' => $r['full_name'] ?? '', 'defaultBranch' => $r['default_branch'] ?? 'main'], 'Connection OK');
        } catch (\Throwable $e) {
            $conn->lastError = $e->getMessage(); Bean::store($conn);
            $this->jsonError('Test failed: ' . $e->getMessage(), 400);
        }
    }

    /** POST /connections/disconnect — remove a stored connection. */
    public function disconnect($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $conn = Bean::load('connections', (int)$this->getParam('cid', 0));
        if (!$conn->id || (int)$conn->memberId !== (int)$this->member->id) { $this->jsonError('Connection not found', 404); return; }
        Bean::trash($conn);
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
        $conn = Bean::load('connections', (int)$this->getParam('cid', 0));
        if (!$conn->id || (int)$conn->memberId !== (int)$this->member->id) { $this->jsonError('Connection not found', 404); return; }
        $secret = trim((string)$this->getParam('secret', ''));
        if ($secret !== '') {
            $conn->webhookSecret = EncryptionService::encrypt($secret);
        } elseif (filter_var($this->getParam('clear', false), FILTER_VALIDATE_BOOLEAN)) {
            $conn->webhookSecret = '';
        }
        $conn->updatedAt = date('Y-m-d H:i:s');
        Bean::store($conn);
        $this->jsonSuccess(['set' => !empty($conn->webhookSecret)], 'Webhook secret saved');
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
        $conn = Bean::load('connections', (int)$this->getParam('cid', 0));
        if (!$conn->id || (int)$conn->memberId !== (int)$this->member->id) { $this->jsonError('Connection not found', 404); return; }

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

        $page = Bean::findOne('socialpage', 'member_id = ? AND connection_id = ?', [(int)$this->member->id, (int)$conn->id]);
        if (!$page || !$page->id) { $page = Bean::dispense('socialpage'); $page->createdAt = date('Y-m-d H:i:s'); $page->feedJson = '[]'; }
        $page->memberId     = (int)$this->member->id;
        $page->connectionId = (int)$conn->id;
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
            $token = EncryptionService::decrypt((string)$conn->accessToken);
            $feed  = $connector->fetchFeed($conn, $token, ['limit' => (int)$page->maxItems]);
            $page->feedJson = json_encode(array_values($feed['items'] ?? []), JSON_UNESCAPED_SLASHES);
            $page->syncedAt = date('Y-m-d H:i:s');
            Bean::store($page);
            $count = count($feed['items'] ?? []);
            if (function_exists('sodium_memzero')) sodium_memzero($token);
        } catch (\Throwable $e) { /* leave empty; the cron / a reconnect will fill it */ }

        $base = rtrim((string)(Flight::get('app.baseurl') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'tiknix.com'))), '/');
        $this->jsonSuccess([
            'published' => (bool)$page->published,
            'url'       => $base . '/social/' . $slug,
            'items'     => $count,
        ], $page->published ? 'Showcase published' : 'Showcase updated');
    }

    /**
     * POST /connections/publish — push the instance's HEAD to a branch on the
     * connected GitHub repo and open (or reuse) a pull request into its default branch.
     */
    public function publish($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        if ($inst->isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { $this->jsonError('Only root can publish to tiknix main.', 403); return; }

        $conn = $this->githubConn((int)$inst->id);
        if (!$conn) { $this->jsonError('This instance is not connected to GitHub yet.', 409); return; }

        $res = GitHubPublisher::publish($inst, $conn);
        $conn->lastUsedAt = date('Y-m-d H:i:s');
        $conn->lastError  = $res['ok'] ? ($res['note'] ?? null) : ($res['error'] ?? 'publish failed');
        Bean::store($conn);

        if (!$res['ok']) { $this->jsonError($res['error'] ?? 'Publish failed', 502); return; }
        $this->jsonSuccess([
            'pushed' => $res['pushed'],
            'branch' => GitHubPublisher::BRANCH,
            'pr'     => $res['pr'],
            'note'   => $res['note'] ?? null,
        ], $res['message']);
    }
}
