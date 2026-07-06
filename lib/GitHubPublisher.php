<?php
/**
 * GitHubPublisher — push an AI Builder instance's HEAD to a branch on the connected
 * GitHub repo and open (or reuse) a pull request into its default branch.
 *
 * Shared by Connections::publish (manual "Publish" button) and Aibuilder::checkpoint
 * (auto-publish when the connection has autoPublish enabled). Keeps the push/PR logic
 * in one place. Credentials come from an encrypted `connections` bean.
 */

namespace app;

use app\EncryptionService;
use app\GitHubService;

class GitHubPublisher {

    public const BRANCH = 'aibuilder-publish';
    private const APP    = 'tiknix';
    private const SLUG_RE = '/^[a-z][a-z0-9]{1,49}$/';

    private static function instanceDir(string $slug): string {
        return '/var/www/html/default/' . $slug . '.' . self::APP;
    }

    private static function git(string $slug, array $args): array {
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'out' => '', 'code' => 1];
        $cmd = 'git -C ' . escapeshellarg(self::instanceDir($slug));
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    /**
     * @param object $inst Instance bean (uses ->slug)
     * @param object $conn `connections` bean (github, with encrypted accessToken + metadataJson)
     * @return array {ok, pushed, pr:{number,url}|null, message, error:?string, note?:string}
     */
    public static function publish($inst, $conn): array {
        $fail = fn($msg) => ['ok' => false, 'pushed' => false, 'pr' => null, 'message' => '', 'error' => $msg];

        $meta  = json_decode($conn->metadataJson ?: '{}', true) ?: [];
        $owner = $meta['owner'] ?? '';
        $repo  = $meta['repo'] ?? '';
        if ($owner === '' || $repo === '') return $fail('Connection is missing owner/repo');

        try { $pat = EncryptionService::decrypt($conn->accessToken); }
        catch (\Throwable $e) { return $fail('Could not read the stored token'); }

        $slug = (string)$inst->slug;
        $head = self::git($slug, ['rev-parse', 'HEAD']);
        if (!$head['ok']) return $fail('This instance has no commits to publish yet.');
        $shortSha = substr(trim($head['out']), 0, 7);

        // Push HEAD to the integration branch on the customer repo.
        // NOTE: token embedded in URL (visible to `ps` during the push). Hardening
        // TODO: pass via GIT_ASKPASS / credential helper.
        $url  = 'https://x-access-token:' . $pat . '@github.com/' . $owner . '/' . $repo . '.git';
        $push = self::git($slug, ['push', '--force', '--follow-tags', $url, 'HEAD:refs/heads/' . self::BRANCH]);
        if (!$push['ok']) {
            $out = $pat !== '' ? str_replace($pat, '***', $push['out']) : $push['out'];
            return $fail('git push failed: ' . $out);
        }

        // Open or reuse a PR: integration branch -> repo default branch.
        try {
            $gh   = new GitHubService($pat, $owner, $repo);
            $base = $meta['defaultBranch'] ?? $gh->getDefaultBranch();

            $pr = null;
            foreach ($gh->listPullRequests('open', 50) as $p) {
                if (($p['head']['ref'] ?? '') === self::BRANCH) { $pr = $p; break; }
            }
            $reused = (bool)$pr;
            if (!$pr) {
                $title = 'AI Builder: ' . $slug . ' updates';
                $body  = "Automated update from the tiknix AI Builder.\n\n"
                       . '- Instance: `' . $slug . ".tiknix`\n"
                       . '- Branch: `' . self::BRANCH . "`\n"
                       . '- HEAD: `' . $shortSha . "`\n";
                $pr = $gh->createPullRequest($title, $body, self::BRANCH, $base, false);
            }
            return [
                'ok' => true, 'pushed' => true,
                'pr' => ['number' => $pr['number'] ?? null, 'url' => $pr['html_url'] ?? null],
                'message' => 'Published to ' . $owner . '/' . $repo . ($reused ? ' (updated existing PR)' : ''),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            // Push landed even if the PR couldn't be opened (e.g. empty base repo).
            return [
                'ok' => true, 'pushed' => true, 'pr' => null,
                'message' => 'Pushed to ' . $owner . '/' . $repo,
                'error' => null,
                'note' => 'PR could not be created: ' . $e->getMessage(),
            ];
        }
    }
}
