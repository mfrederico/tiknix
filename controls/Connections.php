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
        return \Model_Instance::dirForSlug($sub, self::APP);
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
        return \app\ConnectionStore::forInstall($instanceId, 'github');
    }

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

    /** GET /connections/setup?id=<instance> — guided connect page (new tab). */
    public function setup($params = []): void {
        if (!$this->requireLogin()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/sidecar/app/workbench'); return; }
        // Default (tiknix-core) instances publish back to main — root only.
        $isDefault = (bool)$inst->isDefault;
        if ($isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { Flight::redirect('/sidecar/app/workbench'); return; }
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

        // One GitHub connection per instance — PUSHED into that instance's own store
        // (Phase 3). This wrote core's table until 2026-08-04, encrypted with core's
        // key, then wrote the instance's file directly, which only worked while core
        // shared a disk with it. It now goes through the instance's own
        // /connectorapi/receive, so core holds nothing and a self-hosted instance is
        // served by the same call.
        $prev     = $this->githubConn((int)$inst->id);
        $prevMeta = json_decode((string) ($prev->metadataJson ?? '{}'), true) ?: [];

        try {
            $connId = \app\ConnectorPush::push((int)$inst->id, 'github', 'production', [
                'external_eid'    => $fullName,
                'external_name'   => $fullName,
                'external_url'    => 'https://github.com/' . $owner . '/' . $repo,
                'connection_name' => $fullName,
                'token_type'      => 'Bearer',
                'auth_type'       => $authType,
                'access_token'    => $pat,
                'metadata'        => ['owner' => $owner, 'repo' => $repo, 'defaultBranch' => $defaultBranch,
                    'autoPublish' => $auto, 'resolvesTo' => array_values($prevMeta['resolvesTo'] ?? [])],
            ]);
        } catch (\Throwable $e) {
            // The reason, not a shrug: "could not be stored" sends people looking at
            // GitHub when the answer is a broker key or an unreachable instance.
            $this->jsonError('GitHub was authorized but the connection could not be stored: ' . $e->getMessage(), 502);
            return;
        }
        if ($useOauth) unset($_SESSION['gh_oauth']);

        $this->jsonSuccess([
            'id'            => $connId,
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
        if (!$this->oauthEnabled()) { Flight::redirect('/sidecar/app/workbench'); return; }
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/sidecar/app/workbench'); return; }
        if ($inst->isDefault && !Flight::hasLevel(LEVELS['ROOT'])) { Flight::redirect('/sidecar/app/workbench'); return; }

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
            unset($_SESSION['gh_oauth']); Flight::redirect('/sidecar/app/workbench'); return;
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

    /** POST /connections/createrepo — create a NEW repo under the OAuth'd user, then the client
     *  connects to it via /connections/add. Uses the pending OAuth token (repo scope). JSON. */
    public function createrepo($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        $sess = $_SESSION['gh_oauth'] ?? null;
        if (!$sess || empty($sess['token']) || (int)($sess['instance_id'] ?? 0) !== (int)$inst->id) {
            $this->jsonError('GitHub authorization expired — reconnect.', 400); return;
        }
        $name = trim((string)$this->getParam('name', ''));
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name)) { $this->jsonError('Repo name: letters, numbers, . _ - (max 100).', 400); return; }
        $private = filter_var($this->getParam('private', true), FILTER_VALIDATE_BOOLEAN);
        try {
            $gh = new GitHubService((string)$sess['token'], '', '');
            $r  = $gh->createRepo($name, $private, 'Created by tiknix AI Builder for ' . $inst->slug);
        } catch (\Throwable $e) { $this->jsonError('GitHub could not create it: ' . $e->getMessage(), 400); return; }
        $full = (string)($r['full_name'] ?? '');
        [$owner, $repo] = array_pad(explode('/', $full, 2), 2, '');
        $this->jsonSuccess(['owner' => $owner, 'repo' => $repo, 'full_name' => $full], 'Repository created');
    }

    // --- Custom-domain deploy targets ("resolves to", on the GitHub connection) ---
    // A list of {domain, branch} mappings (like Shopify's multiple stores). Phase 1:
    // register + DNS-verify. The publish/deploy into /hosted/<domain> is Phase 2.

    /** GET /connections/branches?id=<inst> — the connected repo's real branches. JSON. */
    public function branches($params = []): void {
        if (!$this->requireLogin()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        $conn = $this->githubConn((int)$inst->id);
        if (!$conn || !$conn->id) { $this->jsonError('Connect a GitHub repo first.', 400); return; }
        $meta = json_decode((string)($conn->metadataJson ?: '{}'), true) ?: [];
        try {
            $gh = new GitHubService(EncryptionService::decrypt($conn->accessToken), (string)($meta['owner'] ?? ''), (string)($meta['repo'] ?? ''));
            $this->jsonSuccess(['branches' => $gh->listBranches(), 'default' => $meta['defaultBranch'] ?? 'main']);
        } catch (\Throwable $e) { $this->jsonError('Could not list branches: ' . $e->getMessage(), 400); }
    }

    /** POST /connections/resolveadd — map {domain, branch}. */
    public function resolveadd($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        $conn = $this->githubConn((int)$inst->id);
        if (!$conn || !$conn->id) { $this->jsonError('Connect a GitHub repo first.', 400); return; }
        $domain = strtolower(trim((string)$this->getParam('domain', '')));
        $branch = trim((string)$this->getParam('branch', ''));
        if (!$this->validHost($domain)) { $this->jsonError('Enter a valid domain (e.g. app.example.com).', 400); return; }
        if ($branch === '' || !preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) { $this->jsonError('Pick a branch.', 400); return; }
        $meta = json_decode((string)($conn->metadataJson ?: '{}'), true) ?: [];
        $rt = array_values($meta['resolvesTo'] ?? []);
        foreach ($rt as $r) if (($r['domain'] ?? '') === $domain) { $this->jsonError('That domain is already mapped.', 409); return; }
        $rt[] = ['domain' => $domain, 'branch' => $branch, 'verified' => false, 'verifiedAt' => null, 'live' => false];
        $meta['resolvesTo'] = $rt;
        $conn->metadataJson = json_encode($meta);
        Bean::store($conn);
        $this->jsonSuccess(['resolvesTo' => $rt, 'cnameTarget' => $this->stagingHost($inst)], 'Domain added');
    }

    /** POST /connections/resolveverify — confirm the domain's CNAME points at the staging host. */
    public function resolveverify($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        $conn = $this->githubConn((int)$inst->id);
        if (!$conn || !$conn->id) { $this->jsonError('No connection', 400); return; }
        $domain = strtolower(trim((string)$this->getParam('domain', '')));
        $target = $this->stagingHost($inst);
        $ok = false;
        foreach ((array) @dns_get_record($domain, DNS_CNAME) as $r) {
            if (rtrim(strtolower((string)($r['target'] ?? '')), '.') === strtolower($target)) { $ok = true; break; }
        }
        if (!$ok) {   // fallback: same resolved IP (CNAME flattened by the DNS provider)
            $dIp = @gethostbyname($domain); $tIp = @gethostbyname($target);
            if ($dIp && $dIp !== $domain && $dIp === $tIp) $ok = true;
        }
        $meta = json_decode((string)($conn->metadataJson ?: '{}'), true) ?: [];
        $rt = array_values($meta['resolvesTo'] ?? []); $found = false;
        foreach ($rt as &$r) { if (($r['domain'] ?? '') === $domain) { $r['verified'] = $ok; $r['verifiedAt'] = $ok ? date('Y-m-d H:i:s') : null; $found = true; } }
        unset($r);
        if (!$found) { $this->jsonError('Domain not found in this connection.', 404); return; }
        $meta['resolvesTo'] = $rt; $conn->metadataJson = json_encode($meta); Bean::store($conn);
        if ($ok) { $this->jsonSuccess(['resolvesTo' => $rt], 'DNS verified — points at ' . $target); }
        else { $this->jsonError('Not pointing at ' . $target . ' yet. Add a CNAME: ' . $domain . ' → ' . $target . ' (DNS can take a few minutes).', 422); }
    }

    /** POST /connections/resolveremove — drop a {domain,branch} mapping. */
    public function resolveremove($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        $conn = $this->githubConn((int)$inst->id);
        if (!$conn || !$conn->id) { $this->jsonError('No connection', 400); return; }
        $domain = strtolower(trim((string)$this->getParam('domain', '')));
        $meta = json_decode((string)($conn->metadataJson ?: '{}'), true) ?: [];
        $meta['resolvesTo'] = array_values(array_filter($meta['resolvesTo'] ?? [], fn($r) => ($r['domain'] ?? '') !== $domain));
        $conn->metadataJson = json_encode($meta); Bean::store($conn);
        $this->jsonSuccess(['resolvesTo' => $meta['resolvesTo']], 'Domain removed');
    }

    /** POST /connections/deploy — clone/update the mapping's branch into /hosted/<domain>. */
    public function deploy($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        $conn = $this->githubConn((int)$inst->id);
        if (!$conn || !$conn->id) { $this->jsonError('Connect a GitHub repo first.', 400); return; }
        $domain = strtolower(trim((string)$this->getParam('domain', '')));
        $meta = json_decode((string)($conn->metadataJson ?: '{}'), true) ?: [];
        $rt   = array_values($meta['resolvesTo'] ?? []);
        $map  = null; foreach ($rt as $r) if (($r['domain'] ?? '') === $domain) { $map = $r; break; }
        if (!$map) { $this->jsonError('That domain is not mapped.', 404); return; }
        if (empty($map['verified'])) { $this->jsonError('Verify DNS for ' . $domain . ' before deploying.', 400); return; }
        $repoFull = (string)($meta['owner'] ?? '') . '/' . (string)($meta['repo'] ?? '');
        try { $token = EncryptionService::decrypt((string)$conn->accessToken); }
        catch (\Throwable $e) { $this->jsonError('Connection token unreadable — reconnect GitHub.', 400); return; }

        $res = \app\HostedDeploy::deploy($domain, $repoFull, (string)($map['branch'] ?? 'main'), $token, $this->instanceDir($inst->slug));
        if (empty($res['ok'])) { $this->jsonError('Deploy failed: ' . ($res['error'] ?? 'unknown'), 500); return; }

        foreach ($rt as &$r) if (($r['domain'] ?? '') === $domain) { $r['live'] = true; $r['deployedAt'] = date('Y-m-d H:i:s'); }
        unset($r);
        $meta['resolvesTo'] = $rt; $conn->metadataJson = json_encode($meta); Bean::store($conn);
        $this->jsonSuccess(['resolvesTo' => $rt, 'steps' => $res['steps'] ?? []],
            'Deployed to /hosted/' . $domain . ' — provision TLS + nginx to finish going live.');
    }

    // ---------------------------------------------------------------- LXC hosting
    //
    // Deploying an instance to its own container is a CORE action, not a builder-sidecar
    // one: it mutates the instance registry, mints a deploy token, and drives the
    // hypervisor. It lives beside the GitHub deploy targets because it answers the same
    // question — "where does this instance actually run" — just with a container as the
    // target instead of /hosted.

    /** Current container state for the instance card. Read-only, safe to poll. */
    public function lxcstatus($params = []): void {
        if (!$this->requireLogin()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }
        $this->jsonSuccess(\app\ProxmoxDeploy::status($inst));
    }

    /**
     * Create (or replace) this instance's container.
     *
     * `recreate` is passed through but NOT forced: ProxmoxDeploy refuses it once the
     * tenant is past first-run setup, because recreate purges the data volumes. Code
     * changes never need a deploy at all — the in-container puller applies them within
     * a minute — so this button is for standing an instance up or changing its shape.
     */
    public function lxcdeploy($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }

        $domain = strtolower(trim((string) $this->getParam('domain', '')));
        if ($domain !== '' && !$this->validHost($domain)) { $this->jsonError('Invalid domain.', 400); return; }

        $res = \app\ProxmoxDeploy::deploy((string) $inst->slug, $domain, [
            'recreate' => (bool) $this->getParam('recreate', false),
            'cert'     => (bool) $this->getParam('cert', true),
        ]);
        if (empty($res['ok'])) { $this->jsonError((string) ($res['error'] ?? 'Deploy failed'), 400); return; }

        $this->jsonSuccess(['steps' => $res['steps'] ?? []] + \app\ProxmoxDeploy::status($inst),
            'Container ' . $res['vmid'] . ' is up at ' . $res['domain'] . '. First boot clones and installs, so give it a minute.');
    }

    /**
     * Re-apply the boot command to a running container and restart it, volumes intact.
     * The entrypoint is frozen at create time, so this is how a deploy-level change
     * (policy, resolver, puller) reaches a tenant that already has data.
     */
    public function lxcrefresh($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }

        $res = \app\ProxmoxDeploy::refreshBoot((string) $inst->slug);
        if (empty($res['ok'])) { $this->jsonError((string) ($res['error'] ?? 'Refresh failed'), 400); return; }
        $this->jsonSuccess(['steps' => $res['steps'] ?? []], 'Settings re-applied and the container restarted.');
    }

    /** The CNAME target customers point their domain at = this instance's staging host. */
    private function stagingHost($inst): string {
        $ini  = @parse_ini_file($this->instanceDir($inst->slug) . '/conf/config.ini', true) ?: [];
        $host = parse_url((string)($ini['app']['baseurl'] ?? ''), PHP_URL_HOST);
        return $host ?: ($inst->slug . '.tiknix.com');
    }

    /** Strict host allowlist (mirrors capricorn's valid_host): DNS chars, no traversal. */
    private function validHost(string $h): bool {
        $h = strtolower(trim($h));
        if ($h === '' || strlen($h) > 253) return false;
        if (strpos($h, '..') !== false) return false;
        if (!preg_match('/^[a-z0-9.-]+$/', $h)) return false;
        if (in_array($h[0], ['.', '-'], true) || in_array(substr($h, -1), ['.', '-'], true)) return false;
        return strpos($h, '.') !== false;
    }

    // --- Generic connector OAuth (registry-driven; e.g. Shopify) --------------

    /** GET /connections[?id=<instance>] — connections hub; defaults to the member's most-recent store. */
    public function index($params = []): void {
        if (!$this->requireLogin()) return;
        // Inside an instance there is no owner/instance picker — show the read-only
        // list of what this app is connected to (metadata via the broker).
        if (!builder_tools_enabled()) { $this->instanceConnections(); return; }
        $instances = R::find('instance', 'member_id = ? ORDER BY created_at DESC', [(int)$this->member->id]);

        // An explicit ?id= wins (deep links from the builder), then the project the
        // member selected. NOT "most recently created" — that guess meant Connections
        // showed a different project's stores than the one you were working on, and
        // connecting from here could bind a store to the wrong instance entirely.
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) {
            $project = ProjectContext::current((int)$this->member->id);
            if ($project) $inst = $this->ownedInstance((int)$project->id);
        }
        // No project chosen → choose one; do not silently fall back to some instance.
        if (!$inst) { Flight::redirect('/projects'); return; }

        // A just-completed connect (a prior request) writes a connections row; bust
        // the cache before reading so a newly-connected store shows on the FIRST view
        // instead of after a second connect / cache TTL.
        $ad = Flight::get('cachedDatabaseAdapter');
        if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('connections');

        // The SELECTED instance's own store. member_id is gone from the scoping: a
        // connection belongs to the instance, whoever attached it, and the file is
        // the boundary that used to be a WHERE clause.
        //
        // The key hint is computed INSIDE the closure, because that is the only place
        // the instance's key is the one in scope -- ConnectionStore::ownToken() reads
        // secure/connections.key from whichever install useInstall() named.
        //
        // The webhook-secret hint is NOT computed at all, and cannot be. That secret is
        // sealed with the instance's APPLICATION key (EncryptionService), which core
        // does not hold and which switching databases does not bring into scope. The
        // old code called EncryptionService::decrypt() here and swallowed the failure
        // in a catch, so the hint silently rendered blank and looked like "no secret
        // set". Core now reports only WHETHER one is set, which is the whole of what
        // it honestly knows.
        $byType = ConnectionStore::withInstall((int)$inst->id, function () {
            $out = [];
            foreach (Bean::find('connections', 'ORDER BY connector_type, environment') as $c) {
                if (!$c->id) continue;

                $keyHint = '';
                if ((string)($c->accessToken ?? '') !== '') {
                    $plainKey = ConnectionStore::ownToken($c);
                    if ($plainKey !== '') $keyHint = substr($plainKey, -4);
                }

                $out[(string)$c->connectorType][] = [
                    'id'          => (int)$c->id,
                    'environment' => $c->environment ?: 'production',
                    'name'        => $c->externalName ?: $c->externalEid,
                    'eid'         => $c->externalEid,
                    'url'         => $c->externalUrl,
                    'enabled'     => (int)$c->enabled === 1,
                    'revoked'     => !empty($c->revokedAt),
                    'lastError'   => $c->lastError,
                    'webhookSet'  => (string)($c->webhookSecret ?? '') !== '',
                    'webhookHint' => '',   // core cannot open the instance's app key
                    'keyHint'     => $keyHint,
                ];
            }
            return $out;
        }, []);

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
                // The connect form reads key_label / key_placeholder / key_required /
                // key_hint / fields from here, so a connector that needs more than a
                // pasted key describes itself instead of the view special-casing it.
                'meta'         => $meta,
                'manage_url'   => null,
                'connections'  => $byType[$conn->key()] ?? [],
            ];
        }

        $this->render('connections/index', [
            'title'          => 'Connections',
            'instance'       => $inst,
            'instances'      => $instances,
            'cards'          => $cards,
            // Where this instance runs. Belongs on the hub, not on the GitHub connector's
            // page: binding a domain should not require connecting a repo first.
            'publishDrivers' => \app\Publish\PublishRegistry::hosting(),
            // Only for the GitHub deploy-webhook hint ("a push fires N pipelines");
            // the pipelines themselves are shown on /integrations, not here.
            'pipelines'      => \app\InstanceAutomations::pipelines($this->instanceDir($inst->slug)),
            'environments'   => ['development', 'production'],
            'categoryOrder'  => ['Deploy', 'Project', 'Payments', 'Stores', 'Messaging', 'Social', 'Other'],
        ]);
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
            ];
        }

        $this->render('connections/instance', [
            'title'           => 'Connections',
            'connections'     => $connections,
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
            Flight::jsonError('Telegram only delivers to https. This install\'s [app] baseurl is "'
                            . $url . '".', 400);
            return;
        }

        $secret = bin2hex(random_bytes(24));

        try {
            $connector->setWebhook($token, $url, $secret);
        } catch (\Throwable $e) {
            Flight::jsonError($e->getMessage(), 400);
            return;
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

        // Advisory allowlist: the connectors this instance actually has connections
        // for, read from its own store.
        $keys = ConnectionStore::withInstall((int)$inst->id, function () {
            $k = [];
            foreach (Bean::find('connections', 'enabled = 1') as $c) {
                if ($c->connectorType) $k[(string)$c->connectorType] = true;
            }
            return $k;
        }, []);

        $res = BrokerService::mint((int)$inst->id, (int)$this->member->id, array_keys($keys));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host  = $_SERVER['HTTP_HOST'] ?? 'tiknix.com';
        $this->jsonSuccess([
            'token'    => $res['token'],
            'endpoint' => ($https ? 'https' : 'http') . '://' . $host . '/mcp/message',
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
    private function connectorRedirectUri(string $type): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host . '/connections/callback/' . $type;
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
                Flight::redirect('/sidecar/app/workbench'); return;
            }
            $inst = $this->ownedInstance($iid);
            if (!$inst) {
                $this->flash('error', 'You no longer own that instance.');
                Flight::redirect('/sidecar/app/workbench'); return;
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

        return \app\ConnectorPush::push(
            (int) $claims['instance_id'],
            $type,
            $this->normalizeEnv($claims['environment'] ?? 'production'),
            $payload
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
    private function hubTarget(): ?array {
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found.', 404); return null; }
        $cid = (int)$this->getParam('cid', 0);
        if ($cid <= 0) { $this->jsonError('Connection not found', 404); return null; }
        return [(int)$inst->id, $cid];
    }

    /** POST /connections/test — re-validate a stored connection. */
    public function test($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        if (($t = $this->hubTarget()) === null) return;
        [$iid, $cid] = $t;

        // The whole unit of work runs inside the instance's store: the token is
        // decrypted with that instance's key (ownToken), and the outcome is written
        // back to the same file. Carrying the bean out and storing it afterwards
        // would save it to core -- see ConnectionStore::withInstall.
        $res = ConnectionStore::withInstall($iid, function () use ($cid) {
            $conn = Bean::load('connections', $cid);
            if (!$conn->id) return ['error' => 'Connection not found', 'code' => 404];

            $meta = json_decode(($conn->metadataJson ?: '{}') ?? '', true) ?: [];
            $pat  = ConnectionStore::ownToken($conn);
            if ($pat === '') return ['error' => 'The stored token could not be decrypted on this instance.', 'code' => 500];

            try {
                $gh = new GitHubService($pat, $meta['owner'] ?? '', $meta['repo'] ?? '');
                $r  = $gh->getRepository();
                $conn->lastUsedAt = date('Y-m-d H:i:s'); $conn->lastError = null; Bean::store($conn);
                return ['ok' => ['repo' => $r['full_name'] ?? '', 'defaultBranch' => $r['default_branch'] ?? 'main']];
            } catch (\Throwable $e) {
                $conn->lastError = $e->getMessage(); Bean::store($conn);
                return ['error' => 'Test failed: ' . $e->getMessage(), 'code' => 400];
            }
        }, ['error' => 'That instance has no connections store on this host.', 'code' => 404]);

        if (isset($res['error'])) { $this->jsonError($res['error'], (int)$res['code']); return; }
        $this->jsonSuccess($res['ok'], 'Connection OK');
    }

    /** POST /connections/disconnect — remove a stored connection. */
    public function disconnect($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        if (($t = $this->hubTarget()) === null) return;
        [$iid, $cid] = $t;

        $gone = ConnectionStore::withInstall($iid, function () use ($cid) {
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
        if (builder_tools_enabled()) {
            $this->jsonError('Set the webhook secret from the instance\'s own Connections page: '
                . 'it is encrypted with that install\'s key, which the control plane does not hold.', 409);
            return;
        }

        $cid = (int)$this->getParam('cid', 0);
        if ($cid <= 0) { $this->jsonError('Connection not found', 404); return; }
        $secret = trim((string)$this->getParam('secret', ''));
        $clear  = filter_var($this->getParam('clear', false), FILTER_VALIDATE_BOOLEAN);

        $set = ConnectionStore::withOwnDb(function () use ($cid, $secret, $clear) {
            $conn = Bean::load('connections', $cid);
            if (!$conn->id) return null;
            if ($secret !== '')   $conn->webhookSecret = EncryptionService::encrypt($secret);
            elseif ($clear)       $conn->webhookSecret = '';
            $conn->updatedAt = date('Y-m-d H:i:s');
            Bean::store($conn);
            return !empty($conn->webhookSecret);
        }, null, true);

        if ($set === null) { $this->jsonError('Connection not found on this install', 404); return; }
        $this->jsonSuccess(['set' => $set], 'Webhook secret saved');
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
        $src = ConnectionStore::withInstall($iid, function () use ($cid) {
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
