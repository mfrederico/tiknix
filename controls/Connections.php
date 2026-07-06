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
 * Routes (auto-routed /connections/<method>):
 *   GET  /connections/setup?id=<instance>  - guided connect page (opened in a new tab)
 *   GET  /connections/status?id=<instance> - JSON: is this instance connected?
 *   POST /connections/add                  - store/replace a GitHub PAT connection
 *   POST /connections/test                 - re-validate a stored connection
 *   POST /connections/disconnect           - remove a connection
 *   POST /connections/publish              - push HEAD + open/reuse a PR on the repo
 *
 * The full pluggable connector registry (OAuth, Shopify, Jira) is deferred; this
 * hardcodes GitHub to get the publish->PR loop working end to end.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;
use app\EncryptionService;
use app\GitHubService;
use RedBeanPHP\R;

class Connections extends Control {

    private const SLUG_RE        = '/^[a-z][a-z0-9]{1,49}$/';
    private const APP            = 'tiknix';
    private const PUBLISH_BRANCH = 'aibuilder-publish';

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

    /** Run git inside an instance's directory (its own repo only). */
    private function gitInstance(string $slug, array $args): array {
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'out' => '', 'code' => 1];
        $cmd = 'git -C ' . escapeshellarg($this->instanceDir($slug));
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    /** Strip a secret from a string before it is returned or logged. */
    private function redact(string $s, string $secret): string {
        return $secret !== '' ? str_replace($secret, '***', $s) : $s;
    }

    /** The enabled GitHub connection bound to member + instance, or null. */
    private function githubConn(int $instanceId) {
        return Bean::findOne('connections',
            'member_id = ? AND instance_id = ? AND connector_type = ? AND enabled = 1',
            [(int)$this->member->id, $instanceId, 'github']);
    }

    private function connSummary($conn): array {
        $meta = json_decode($conn->metadataJson ?: '{}', true) ?: [];
        return [
            'id'          => (int)$conn->id,
            'type'        => $conn->connectorType,
            'repo'        => ($meta['owner'] ?? '') . '/' . ($meta['repo'] ?? ''),
            'defaultBranch' => $meta['defaultBranch'] ?? 'main',
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
        $conn = $this->githubConn((int)$inst->id);
        $this->render('connections/setup', [
            'instance'   => $inst,
            'connection' => $conn && $conn->id ? $this->connSummary($conn) : null,
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

    /** POST /connections/add — store/replace a GitHub PAT connection for an instance. */
    public function add($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Instance not found', 404); return; }

        $type = strtolower(trim((string)$this->getParam('type', 'github')));
        if ($type !== 'github') { $this->jsonError('Unsupported connector', 400); return; }

        $pat   = trim((string)$this->getParam('token', ''));
        $owner = trim((string)$this->getParam('owner', ''));
        $repo  = trim((string)$this->getParam('repo', ''));
        $repo  = preg_replace('/\.git$/', '', $repo);
        if ($pat === '' || $owner === '' || $repo === '') {
            $this->jsonError('Token, owner and repo are required', 400); return;
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
        $conn->authType       = 'token';
        $conn->accessToken    = EncryptionService::encrypt($pat);
        $conn->tokenType      = 'Bearer';
        $conn->externalEid    = $fullName;
        $conn->externalName   = $fullName;
        $conn->externalUrl    = 'https://github.com/' . $owner . '/' . $repo;
        $conn->connectionName = $fullName;
        $conn->metadataJson   = json_encode(['owner' => $owner, 'repo' => $repo, 'defaultBranch' => $defaultBranch]);
        $conn->enabled        = 1;
        $conn->shared         = 0;
        $conn->lastError      = null;
        $conn->lastUsedAt     = $now;
        if (!$conn->id) $conn->createdAt = $now;
        $conn->updatedAt      = $now;
        Bean::store($conn);

        $this->jsonSuccess([
            'id'            => (int)$conn->id,
            'repo'          => $fullName,
            'defaultBranch' => $defaultBranch,
            'tokenMask'     => EncryptionService::mask($pat),
        ], 'GitHub connected');
    }

    /** POST /connections/test — re-validate a stored connection. */
    public function test($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $conn = Bean::load('connections', (int)$this->getParam('cid', 0));
        if (!$conn->id || (int)$conn->memberId !== (int)$this->member->id) { $this->jsonError('Connection not found', 404); return; }
        $meta = json_decode($conn->metadataJson ?: '{}', true) ?: [];
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

        $conn = $this->githubConn((int)$inst->id);
        if (!$conn) { $this->jsonError('This instance is not connected to GitHub yet.', 409); return; }

        $meta  = json_decode($conn->metadataJson ?: '{}', true) ?: [];
        $owner = $meta['owner'] ?? '';
        $repo  = $meta['repo'] ?? '';
        if ($owner === '' || $repo === '') { $this->jsonError('Connection is missing owner/repo', 400); return; }

        try { $pat = EncryptionService::decrypt($conn->accessToken); }
        catch (\Throwable $e) { $this->jsonError('Could not read the stored token', 500); return; }

        $branch = self::PUBLISH_BRANCH;

        // Must have a commit to publish.
        $head = $this->gitInstance($inst->slug, ['rev-parse', 'HEAD']);
        if (!$head['ok']) { $this->jsonError('This instance has no commits to publish yet.', 400); return; }
        $shortSha = substr(trim($head['out']), 0, 7);

        // Push HEAD to the integration branch on the customer repo.
        // NOTE: token is embedded in the URL (visible to `ps` on this host for the
        // duration of the push). Hardening TODO: pass via GIT_ASKPASS/credential helper.
        $url  = 'https://x-access-token:' . $pat . '@github.com/' . $owner . '/' . $repo . '.git';
        $push = $this->gitInstance($inst->slug, ['push', '--force', '--follow-tags', $url, 'HEAD:refs/heads/' . $branch]);
        $pushOut = $this->redact($push['out'], $pat);
        if (!$push['ok']) {
            $conn->lastError = 'push failed'; Bean::store($conn);
            $this->jsonError('git push failed: ' . $pushOut, 502); return;
        }

        // Open or reuse a PR: integration branch -> repo default branch.
        try {
            $gh   = new GitHubService($pat, $owner, $repo);
            $base = $meta['defaultBranch'] ?? $gh->getDefaultBranch();

            $pr = null;
            foreach ($gh->listPullRequests('open', 50) as $p) {
                if (($p['head']['ref'] ?? '') === $branch) { $pr = $p; break; }
            }
            $reused = (bool)$pr;
            if (!$pr) {
                $title = 'AI Builder: ' . $inst->slug . ' updates';
                $body  = "Automated update from the tiknix AI Builder.\n\n"
                       . '- Instance: `' . $inst->slug . ".tiknix`\n"
                       . '- Branch: `' . $branch . "`\n"
                       . '- HEAD: `' . $shortSha . "`\n";
                $pr = $gh->createPullRequest($title, $body, $branch, $base, false);
            }
            $conn->lastUsedAt = date('Y-m-d H:i:s'); $conn->lastError = null; Bean::store($conn);
            $this->jsonSuccess([
                'pushed' => true,
                'branch' => $branch,
                'pr'     => ['number' => $pr['number'] ?? null, 'url' => $pr['html_url'] ?? null],
            ], 'Published to ' . $owner . '/' . $repo . ($reused ? ' (updated existing PR)' : ''));
        } catch (\Throwable $e) {
            // The push landed even if the PR couldn't be opened (e.g. empty base repo).
            $conn->lastUsedAt = date('Y-m-d H:i:s'); $conn->lastError = 'PR: ' . $e->getMessage(); Bean::store($conn);
            $this->jsonSuccess([
                'pushed' => true,
                'branch' => $branch,
                'pr'     => null,
                'note'   => 'Pushed, but the PR could not be created: ' . $e->getMessage(),
            ], 'Pushed to ' . $owner . '/' . $repo);
        }
    }
}
