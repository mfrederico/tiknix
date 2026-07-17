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

    /** GET /connections — connections hub for an instance (?id=<instance>). */
    public function index($params = []): void {
        if (!$this->requireLogin()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/aibuilder'); return; }

        $rows = Bean::find('connections',
            'member_id = ? AND instance_id = ? ORDER BY connector_type, environment',
            [(int)$this->member->id, (int)$inst->id]);
        $connections = [];
        foreach ($rows as $c) {
            $connections[] = [
                'id'          => (int)$c->id,
                'type'        => $c->connectorType,
                'environment' => $c->environment ?: 'production',
                'name'        => $c->externalName ?: $c->externalEid,
                'eid'         => $c->externalEid,
                'scopes'      => $c->scopes,
                'enabled'     => (int)$c->enabled === 1,
                'revoked'     => !empty($c->revokedAt),
                'lastUsed'    => $c->lastUsedAt,
                'lastError'   => $c->lastError,
            ];
        }
        $connectors = [];
        foreach (ConnectorRegistry::all() as $conn) {
            $connectors[] = ['key' => $conn->key(), 'meta' => $conn->meta(), 'configured' => $conn->isConfigured()];
        }
        $this->render('connections/index', [
            'instance'     => $inst,
            'connections'  => $connections,
            'connectors'   => $connectors,
            'environments' => ['development', 'staging', 'production'],
        ]);
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

    /** GET /connections/callback/<type> — registry connector OAuth redirect target. */
    private function connectorCallback(string $type): void {
        $connector = ConnectorRegistry::get($type);
        if (!$connector) { Flight::redirect('/aibuilder'); return; }
        if (!Flight::isLoggedIn()) { Flight::redirect('/auth/login'); return; }

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
        // Identity comes from the signed state, never from the query string.
        if ((int)($claims['member_id'] ?? 0) !== (int)$this->member->id) {
            $this->flash('error', 'This authorization was started by a different account.');
            Flight::redirect('/aibuilder'); return;
        }
        $iid = (int)($claims['instance_id'] ?? 0);
        // Re-assert ownership at callback time (a connection cannot outlive its owner's grant).
        $inst = $this->ownedInstance($iid);
        if (!$inst) {
            $this->flash('error', 'You no longer own that instance.');
            Flight::redirect('/aibuilder'); return;
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
            $this->flash('error', ucfirst($type) . ' connection failed: ' . $e->getMessage());
            Flight::redirect('/connections?id=' . $iid); return;
        }
        // Wire the instance so its app can reach this store immediately — no keys
        // for the user to handle. Best-effort: never fail the connect over this.
        try {
            BrokerService::ensureInstanceConfig($iid, (int)$this->member->id, $this->instanceDir($inst->slug));
        } catch (\Throwable $e) {
            error_log('[connections] store wiring failed for instance ' . $iid . ': ' . $e->getMessage());
        }
        $this->flash('success', ucfirst($type) . ' store connected.');
        Flight::redirect('/connections?id=' . $iid);
    }

    /**
     * Upsert an encrypted connection for a registry connector. One row per
     * (member, instance, connector, environment, store) so a builder can hold
     * distinct dev / staging / production stores side by side.
     */
    private function upsertConnection(string $type, array $claims, array $payload): int {
        $memberId   = (int)$claims['member_id'];
        $instanceId = (int)$claims['instance_id'];
        $env        = $this->normalizeEnv($claims['environment'] ?? 'production');
        $eid        = (string)($payload['external_eid'] ?? '');

        $conn = Bean::findOne('connections',
            'member_id = ? AND instance_id = ? AND connector_type = ? AND environment = ? AND external_eid = ?',
            [$memberId, $instanceId, $type, $env, $eid]);
        if (!$conn || !$conn->id) $conn = Bean::dispense('connections');

        $now = date('Y-m-d H:i:s');
        $conn->connectorType  = $type;
        $conn->memberId       = $memberId;
        $conn->instanceId     = $instanceId;
        $conn->environment    = $env;
        $conn->authType       = 'oauth';
        $conn->accessToken    = EncryptionService::encrypt((string)$payload['access_token']);
        $conn->tokenType      = (string)($payload['token_type'] ?? 'Bearer');
        $conn->scopes         = (string)($payload['scopes'] ?? '');
        $conn->externalEid    = $eid;
        $conn->externalName   = (string)($payload['external_name'] ?? $eid);
        $conn->externalUrl    = (string)($payload['external_url'] ?? '');
        $conn->connectionName = (string)($payload['external_name'] ?? $eid) . ' (' . $env . ')';
        $conn->metadataJson   = json_encode($payload['metadata'] ?? []);
        $conn->enabled        = 1;
        $conn->shared         = 0;
        $conn->revokedAt      = null;
        $conn->exportedAt     = $conn->exportedAt ?? null;
        $conn->lastError      = null;
        $conn->lastUsedAt     = $now;
        if (!$conn->id) $conn->createdAt = $now;
        $conn->updatedAt      = $now;
        Bean::store($conn);
        return (int)$conn->id;
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
