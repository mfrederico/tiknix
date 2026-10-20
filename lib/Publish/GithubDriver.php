<?php
/**
 * GithubDriver — a project kept in step with a GitHub repository, both ways (app\GitSync).
 *
 * What the customer gives: the repository (owner/name) and its branch. What the platform makes
 * and keeps: an SSH deploy key (SshTargetDriver: sealed in core's deploykey row, unsealed for one
 * git command at a time) — its PUBLIC half goes into the repository's Deploy keys, with write
 * access — and a webhook secret GitHub signs its push notices with. What the customer adds, to
 * have each request opened as a pull request: a fine-grained token for that one repository
 * (Contents and Pull requests: read and write), sealed beside the key. None of it ever enters the
 * app's container, where its agent and code run.
 *
 * deploy() = push now (the app's main to the branch; GitHub's own new commits come in first).
 */
namespace app\Publish;

use app\Bean;
use app\EncryptionService;
use app\GitSync;

class GithubDriver extends SshTargetDriver {

    public static function key(): string { return 'github'; }
    public static function label(): string { return 'GitHub repository'; }
    public static function blurb(): string {
        return 'Keeps a GitHub repository in step with this app, both ways: each request is opened there as a pull request and merged, '
             . 'and what is pushed to its branch comes into the app and is checked. Use an empty repository, or one this app was pushed to.';
    }

    public static function capabilities(): array {
        return ['code' => true, 'domain' => false, 'tls' => false, 'refresh' => true, 'recreate' => false, 'sshKey' => true, 'sync' => true];
    }

    public static function fields(): array {
        return [
            ['name' => 'repo', 'label' => 'Repository', 'type' => 'text', 'required' => true, 'placeholder' => 'yourname/inresonance',
             'help' => 'owner/name on GitHub. Create it empty — no README, licence or .gitignore.'],
            ['name' => 'branch', 'label' => 'Branch', 'type' => 'text', 'placeholder' => 'main', 'help' => 'The branch that always equals the live app.'],
            ['name' => 'token', 'label' => 'Token, for pull requests', 'type' => 'secret', 'placeholder' => 'github_pat_…',
             'help' => 'A fine-grained token for this one repository: Contents and Pull requests, read and write. Kept sealed on the platform; leave empty to keep the one saved.'],
        ];
    }

    // ---------------------------------------------------------------- what the card shows

    public function status(object $inst, array $config): array {
        $row = self::keyRow($inst, false);
        $t = GitSync::target($inst);
        $state = $t ? GitSync::state($t) : [];
        return [
            'configured'  => trim((string) ($config['repo'] ?? '')) !== '',
            'target'      => trim((string) ($config['repo'] ?? '')) . ' · ' . (trim((string) ($config['branch'] ?? '')) ?: 'main'),
            'publicKey'   => $row ? (string) $row->publicKey : '',
            'fingerprint' => $row ? (string) $row->fingerprint : '',
            'keyReady'    => $row && (string) $row->publicKey !== '',
            'tokenSet'    => $row && trim((string) ($row->token ?? '')) !== '',
            'webhookUrl'  => $t ? self::webhookUrl((int) $t->id) : '',
            'lastUsed'    => $row ? ($row->lastUsedAt ?: null) : null,
            'lastError'   => $row ? ($row->lastError ?: null) : null,
            'pushed'      => (string) ($state['pushed_at'] ?? ''),
            'pulled'      => (string) ($state['pulled_at'] ?? ''),
            'waiting'     => !empty($state['pending_pull']),
            'prs'         => array_slice((array) ($state['prs'] ?? []), -5, null, true),
        ];
    }

    /** The address GitHub posts its push notices to for this target. */
    public static function webhookUrl(int $targetId): string {
        return rtrim((string) \Flight::get('app.baseurl'), '/') . '/githook/push?t=' . $targetId;
    }

    // ---------------------------------------------------------------- the handshake

    /** Reach the repository with the deploy key, and GitHub's API with the token; nothing written. */
    public function verify(object $inst, array $config): array {
        try { [$repo, $branch] = GitSync::repo($config); }
        catch (\InvalidArgumentException $e) { return ['ok' => false, 'message' => $e->getMessage()]; }
        $row = self::keyRow($inst);
        if (!$row) return ['ok' => false, 'message' => 'Could not make a deploy key for this repository.'];
        self::webhookSecret($inst);
        $detail = [];
        try {
            $out = SshKey::withKeyFile((string) $row->privateKey, function (string $keyFile) use ($inst, $repo) {
                return self::run('env -u GIT_DIR GIT_SSH_COMMAND=' . escapeshellarg('ssh -i ' . escapeshellarg($keyFile) . ' -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . escapeshellarg(self::knownHosts($inst)))
                    . ' git ls-remote --heads ' . escapeshellarg('git@github.com:' . $repo . '.git'));
            });
        } catch (\Throwable $e) { return ['ok' => false, 'message' => $e->getMessage()]; }
        if (empty($out['ok'])) return ['ok' => false, 'message' => self::explainGit((string) $out['out'], $inst)];
        $detail[] = preg_match('~refs/heads/' . preg_quote($branch, '~') . '$~m', (string) $out['out'])
            ? "The deploy key reaches {$repo}; {$branch} is there."
            : "The deploy key reaches {$repo}; it is empty — the first push sends the app's whole history.";
        $token = self::token($inst);
        if ($token === '') {
            $detail[] = 'No token: changes are pushed, but no pull request is opened for each request.';
        } else {
            try {
                $r = GitSync::api($token, 'GET', "/repos/{$repo}");
                if ($r['status'] !== 200) return ['ok' => false, 'message' => "The token cannot see {$repo} (HTTP {$r['status']}): " . GitSync::apiReason($r) . ' — make it a fine-grained token for this repository.', 'detail' => $detail];
                if (empty($r['body']['permissions']['push'])) return ['ok' => false, 'message' => "The token can read {$repo} but not write to it: give it Contents and Pull requests, read and write.", 'detail' => $detail];
                $detail[] = 'The token can open pull requests on ' . $repo . '.';
            } catch (\Throwable $e) { return ['ok' => false, 'message' => $e->getMessage(), 'detail' => $detail]; }
        }
        $t = GitSync::target($inst);
        if ($t) $detail[] = 'Webhook (push events, content type application/json): ' . self::webhookUrl((int) $t->id);
        return ['ok' => true, 'message' => 'GitHub connection works.', 'detail' => $detail];
    }

    /** Push now: GitHub's own new commits come in first, then the app's main goes out. */
    public function deploy(object $inst, array $config, array $opts = []): array {
        $r = GitSync::push($inst);
        if (!empty($r['needsPull'])) {
            $p = GitSync::pull($inst);
            if (empty($p['ok']) || !empty($p['deferred'])) return ['ok' => false, 'error' => $p['message']];
            $r = GitSync::push($inst);
        }
        return !empty($r['ok']) ? ['ok' => true, 'message' => $r['message']] : ['ok' => false, 'error' => $r['message']];
    }

    // ---------------------------------------------------------------- what the platform keeps

    public static function keyRow(object $inst, bool $create = true) { return self::keyConnection($inst, self::key(), $create); }

    public static function knownHosts(object $inst): string { return self::knownHostsFile($inst); }

    /** The project's token, or '' when none was given. A token that will not open is a fault, not "none". */
    public static function token(object $inst): string {
        $row = self::keyRow($inst, false);
        $sealed = $row ? trim((string) ($row->token ?? '')) : '';
        if ($sealed === '') return '';
        $plain = EncryptionService::decrypt($sealed);
        if (!is_string($plain) || $plain === '') throw new \RuntimeException("{$inst->slug}'s GitHub token is stored but will not open — save it again on the Deploy page");
        return $plain;
    }

    public static function setToken(object $inst, string $token): void {
        if (!preg_match('~^(github_pat_|ghp_|gho_|ghs_)[A-Za-z0-9_]{20,}$~', $token)) throw new \InvalidArgumentException('That does not look like a GitHub token (github_pat_… for a fine-grained one).');
        $row = self::keyRow($inst);
        if (!$row) throw new \RuntimeException('Could not make the deploy key the token is kept with.');
        $row->token = EncryptionService::encrypt($token);
        Bean::store($row);
    }

    /** The secret GitHub signs its notices with (made on first use); shown to the owner to paste into the webhook. */
    public static function webhookSecret(object $inst): string {
        $row = self::keyRow($inst);
        if (!$row) throw new \RuntimeException('Could not make the deploy key the webhook secret is kept with.');
        if (trim((string) ($row->webhookSecret ?? '')) === '') { $row->webhookSecret = EncryptionService::encrypt(bin2hex(random_bytes(24))); Bean::store($row); }
        $plain = EncryptionService::decrypt((string) $row->webhookSecret);
        if (!is_string($plain) || $plain === '') throw new \RuntimeException("{$inst->slug}'s webhook secret will not open");
        return $plain;
    }

    /** A failed git call against GitHub, said as what to do. */
    public static function explainGit(string $out, object $inst): string {
        $row = self::keyRow($inst, false);
        $pk = $row ? (string) $row->publicKey : '';
        if (stripos($out, 'read only') !== false || stripos($out, 'read-only') !== false) {
            return 'GitHub accepted the deploy key but only for reading — on the repository, Settings → Deploy keys, tick "Allow write access" for it.';
        }
        if (stripos($out, 'permission denied') !== false || stripos($out, 'repository not found') !== false || stripos($out, 'could not read from remote') !== false) {
            return 'GitHub did not let the deploy key in. Check the repository name, and add this key under Settings → Deploy keys with "Allow write access":' . ($pk !== '' ? "\n" . $pk : '');
        }
        return trim($out) !== '' ? trim($out) : 'git failed with no output.';
    }
}
