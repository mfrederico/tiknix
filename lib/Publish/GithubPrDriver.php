<?php
/**
 * GithubPrDriver — "GitHub Pull Request": the project goes live as a branch and a pull
 * request on a repo the customer owns.
 *
 * This is a REPOSITORY target, not a hosting one. It owns no container, no domain and no
 * certificate — it answers "how does a change reach my code" rather than "where does this
 * instance run". So it is the one driver whose deploy() legitimately ships a commit
 * (see the note in PublishDriver), and it reports `code => true` to say so.
 *
 * It is a thin wrapper: lib/GitHubPublisher.php already builds the clean snapshot, pushes
 * it and opens or reuses the PR. This class exists to put that behind the same interface
 * as the hosting targets so ONE dispatcher (controls/Publish) can run either.
 *
 * CUSTODY: the PAT lives encrypted in the instance's `connections` row and is decrypted
 * here, on the control plane, in-process. That is why this driver never runs inside a
 * sidecar or an instance — both reach it through controls/Publish, which authenticates
 * the caller by its broker key and resolves the instance from that key alone.
 */
namespace app\Publish;

use app\Bean;
use app\GitHubPublisher;

class GithubPrDriver implements PublishDriver {

    public static function key(): string   { return 'github-pr'; }
    public static function label(): string { return 'GitHub Pull Request'; }

    public static function blurb(): string {
        return 'Pushes a clean snapshot of this project to your own GitHub repo and opens (or updates) a pull request. Secrets, the database and vendor/ are never included.';
    }

    public static function capabilities(): array {
        return [
            'code'     => true,    // ships a commit — this target IS the delivery mechanism
            'domain'   => false,   // a repo has no hostname; where it then runs is a separate target
            'tls'      => false,
            'refresh'  => true,    // publishing again updates the same branch + PR
            'recreate' => false,
            'sshKey'   => false,
        ];
    }

    /**
     * Any member may publish to a repo they own — it is their credential, their repo, and
     * it costs this control plane nothing. The connection row is the real gate: without
     * one there is nowhere to push.
     */
    public static function minLevel(string $op): int {
        return LEVELS['MEMBER'];
    }

    /**
     * Nothing to configure here. The repo, the branch and the token all come from the
     * GitHub connection, which is set up on the Connections page — asking for them again
     * would be a second place to get them wrong.
     */
    public static function fields(): array {
        return [];
    }

    /** Push the snapshot and open/reuse the PR. */
    public function deploy(object $inst, array $config, array $opts = []): array {
        $conn = self::connection($inst);
        if (!$conn) {
            return ['ok' => false, 'error' => 'This project is not connected to GitHub yet — connect a repo on the Connections page first.'];
        }

        $res = GitHubPublisher::publish($inst, $conn);

        // Record the outcome on the connection so the Connections card and the next
        // status() read agree with what actually happened, exactly as the manual
        // Publish button did.
        $conn->lastUsedAt = date('Y-m-d H:i:s');
        $conn->lastError  = !empty($res['ok']) ? ($res['note'] ?? null) : ($res['error'] ?? 'publish failed');
        Bean::store($conn);

        if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Publish failed')];

        // Report the branch actually pushed, not the integration branch: an empty repo has
        // no base to open a PR against, so the first publish initializes the DEFAULT branch
        // instead, and saying "aibuilder-publish" there would be a lie in the run log.
        $branch = (string) ($res['branch'] ?? GitHubPublisher::BRANCH);
        $steps  = ['Built a clean snapshot of the working tree', 'Pushed to ' . $branch];
        if (!empty($res['pr']['url'])) $steps[] = 'Pull request: ' . $res['pr']['url'];
        if (!empty($res['note']))      $steps[] = $res['note'];

        return [
            'ok'      => true,
            'steps'   => $steps,
            'message' => (string) ($res['message'] ?? 'Published'),
            'pr'      => $res['pr'] ?? null,
            'branch'  => $branch,
        ];
    }

    /** Never throws; distinguishes "no repo connected" from "connected, never published". */
    public function status(object $inst, array $config): array {
        $conn = self::connection($inst);
        if (!$conn) {
            return ['configured' => false, 'connected' => false,
                    'detail' => 'No GitHub repo connected to this project.'];
        }
        $meta = json_decode((string) ($conn->metadataJson ?: '{}'), true) ?: [];
        $repo = trim((string) ($meta['owner'] ?? '') . '/' . (string) ($meta['repo'] ?? ''), '/');
        return [
            'configured'    => $repo !== '',
            'connected'     => true,
            'repo'          => $repo,
            'branch'        => GitHubPublisher::BRANCH,
            'defaultBranch' => (string) ($meta['defaultBranch'] ?? 'main'),
            'autoPublish'   => !empty($meta['autoPublish']),
            'lastPublished' => $conn->lastUsedAt ?: null,
            'lastError'     => $conn->lastError ?: null,
        ];
    }

    /** Handshake: can the stored token actually see the repo it claims? Reads only. */
    public function verify(object $inst, array $config): array {
        $conn = self::connection($inst);
        if (!$conn) return ['ok' => false, 'message' => 'No GitHub repo is connected to this project.'];

        $meta  = json_decode((string) ($conn->metadataJson ?: '{}'), true) ?: [];
        $owner = (string) ($meta['owner'] ?? '');
        $repo  = (string) ($meta['repo'] ?? '');
        if ($owner === '' || $repo === '') return ['ok' => false, 'message' => 'The connection is missing owner/repo.'];

        try {
            $token = \app\EncryptionService::decrypt($conn->accessToken);
            $gh    = new \app\GitHubService($token, $owner, $repo);
            $r     = $gh->getRepository();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'GitHub rejected the stored token: ' . $e->getMessage()];
        }

        $branch = (string) ($r['default_branch'] ?? $meta['defaultBranch'] ?? 'main');
        $detail = ['Repository ' . $owner . '/' . $repo . ' is reachable', 'Pull requests open against ' . $branch];
        // A token that can read but not write produces a push failure at the worst moment.
        if (isset($r['permissions']) && empty($r['permissions']['push'])) {
            return ['ok' => false, 'message' => 'The token can read ' . $owner . '/' . $repo . ' but cannot push to it.'];
        }
        return ['ok' => true, 'message' => 'Connection works.', 'detail' => $detail];
    }

    /** Publishing again IS the refresh — the branch is force-updated and the PR reused. */
    public function refresh(object $inst, array $config, array $opts = []): array {
        return $this->deploy($inst, $config, $opts);
    }

    /**
     * The enabled GitHub connection for this instance.
     *
     * Scoped by instance only — NOT by member — because the caller here is the instance
     * itself (via its broker key), not a logged-in person. The instance's own connection
     * is the correct one to use no matter which team member set it up.
     */
    private static function connection(object $inst) {
        $conn = Bean::findOne('connections',
            'instance_id = ? AND connector_type = ? AND enabled = 1',
            [(int) $inst->id, 'github']);
        if (!$conn || !$conn->id) return null;
        if (!empty($conn->revokedAt)) return null;
        return $conn;
    }
}
