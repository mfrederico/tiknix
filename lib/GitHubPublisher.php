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

    /** Like git(), but with extra environment (e.g. GIT_INDEX_FILE) prefixed. */
    private static function gitEnv(string $slug, array $env, array $args): array {
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'out' => '', 'code' => 1];
        $cmd = '';
        foreach ($env as $k => $v) { $cmd .= $k . '=' . escapeshellarg((string)$v) . ' '; }
        $cmd .= 'git -C ' . escapeshellarg(self::instanceDir($slug));
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

        // Build a CLEAN SNAPSHOT as one commit. Snapshot the CURRENT WORKING TREE (not just
        // committed HEAD) so Publish always reflects the customer's latest edits even if they
        // haven't checkpointed. A fresh temp index + `add -A` respects .gitignore, so the
        // SQLite db, vendor/, real conf/*.ini, .aibuilder creds, caches and logs are excluded.
        $tmpIndex = self::instanceDir($slug) . '/.git/aibuilder-publish.index';
        @unlink($tmpIndex);
        $ienv = ['GIT_INDEX_FILE' => $tmpIndex];
        self::gitEnv($slug, $ienv, ['add', '-A']);
        // Belt-and-suspenders: drop any secret config that slipped past .gitignore (keep examples).
        self::gitEnv($slug, $ienv, ['rm', '--cached', '-r', '--ignore-unmatch', '--quiet',
            'conf/*.ini', ':(exclude)conf/*.example.ini', '.aibuilder']);
        $tree = trim(self::gitEnv($slug, $ienv, ['write-tree'])['out']);
        @unlink($tmpIndex);
        if ($tree === '') return $fail('could not build a clean snapshot tree');
        $url = 'https://x-access-token:' . $pat . '@github.com/' . $owner . '/' . $repo . '.git';
        $redact = fn($s) => $pat !== '' ? str_replace($pat, '***', $s) : $s;

        // Determine the repo's default branch and whether it exists yet.
        try {
            $gh = new GitHubService($pat, $owner, $repo);
            $base = $meta['defaultBranch'] ?? $gh->getDefaultBranch();
            $baseExists = $gh->branchExists($base);
        } catch (\Throwable $e) {
            $gh = null; $base = $meta['defaultBranch'] ?? 'main'; $baseExists = false;
        }

        // Parent the snapshot on the current base-branch tip when the base exists, so the
        // integration branch shares history with it. GitHub refuses to open a PR between two
        // branches "with no history in common" — which a parentless commit always is. Empty
        // repo -> a parentless commit initializes the default branch (nothing to descend from).
        $parentArgs = [];
        if ($baseExists) {
            self::git($slug, ['fetch', '--no-tags', '--force', $url, $base]);
            $baseSha = trim(self::git($slug, ['rev-parse', 'FETCH_HEAD'])['out']);
            if (preg_match('/^[0-9a-f]{7,40}$/', $baseSha)) { $parentArgs = ['-p', $baseSha]; }
        }

        $idenv  = ['GIT_AUTHOR_NAME' => 'AI Builder', 'GIT_AUTHOR_EMAIL' => 'aibuilder@tiknix',
                   'GIT_COMMITTER_NAME' => 'AI Builder', 'GIT_COMMITTER_EMAIL' => 'aibuilder@tiknix'];
        $commitArgs = array_merge(['commit-tree', $tree], $parentArgs,
            ['-m', 'AI Builder: ' . $slug . ' snapshot (' . $shortSha . ')']);
        $commit = trim(self::gitEnv($slug, $idenv, $commitArgs)['out']);
        if ($commit === '') return $fail('could not create snapshot commit');

        // Empty repo (no base branch yet): push the snapshot straight to the default branch
        // to initialize it — there is nothing to open a PR against.
        if (!$baseExists) {
            $push = self::git($slug, ['push', '--force', $url, $commit . ':refs/heads/' . $base]);
            if (!$push['ok']) return $fail('git push failed: ' . $redact($push['out']));
            return ['ok' => true, 'pushed' => true, 'pr' => null,
                'message' => 'Published to ' . $owner . '/' . $repo . ' (initialized ' . $base . ')', 'error' => null];
        }

        // Base exists: push the snapshot to the integration branch and open/reuse a PR.
        $push = self::git($slug, ['push', '--force', $url, $commit . ':refs/heads/' . self::BRANCH]);
        if (!$push['ok']) return $fail('git push failed: ' . $redact($push['out']));
        try {
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
            return ['ok' => true, 'pushed' => true,
                'pr' => ['number' => $pr['number'] ?? null, 'url' => $pr['html_url'] ?? null],
                'message' => 'Published to ' . $owner . '/' . $repo . ($reused ? ' (updated existing PR)' : ''), 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => true, 'pushed' => true, 'pr' => null,
                'message' => 'Pushed to ' . $owner . '/' . $repo, 'error' => null,
                'note' => 'PR could not be created: ' . $e->getMessage()];
        }
    }

    /** Derive owner/repo for tiknix's own GitHub repo from main's origin remote. */
    public static function mainGithubRepo(): array {
        $lines = []; $code = 0;
        exec('git -C ' . escapeshellarg('/var/www/html/default/' . self::APP) . ' remote get-url origin 2>/dev/null', $lines, $code);
        $url = trim($lines[0] ?? '');
        if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            return ['owner' => $m[1], 'repo' => $m[2]];
        }
        return ['owner' => '', 'repo' => ''];
    }
}
