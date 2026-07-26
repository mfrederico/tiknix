<?php
/**
 * Snapshot — ONE definition of "what publishes".
 *
 * Every publish target ships the same thing: the customer's working tree, minus what is
 * ours, secret, or regenerable. Getting that list right matters more than any single
 * driver — a rule that lives in two places will eventually disagree in one of them, and
 * the failure mode is leaking a database or a decrypted config to a customer's server.
 *
 * The list is built from a THROWAWAY GIT INDEX rather than a hand-written exclude list:
 * `add -A` already honours .gitignore, so the instance's own ignore rules (its SQLite db,
 * vendor/, caches, logs) do the bulk of the work and cannot drift from what git considers
 * ignorable. What follows is belt-and-braces for the things we must never ship even if
 * .gitignore is edited or missing.
 *
 * Callers take it from here: GitHubPublisher writes a tree from the index and commits it;
 * RsyncDriver lists the paths and feeds them to rsync --files-from. Same index, same
 * contents, whichever way the bytes travel.
 */
namespace app\Publish;

class Snapshot {

    /**
     * Build a temp index for $dir and hand its path to $fn, cleaning up afterwards.
     *
     * @param bool $keepWorkflows tiknix-core publishes back to tiknix main and owns its
     *        CI; every other instance is a CLONE of core, so .github/workflows would ride
     *        along into a repo whose owner never asked for it — and GitHub rejects such a
     *        push outright unless the token carries the `workflow` scope.
     * @param callable(string $indexFile):mixed $fn
     * @return mixed whatever $fn returns
     */
    public static function withIndex(string $dir, bool $keepWorkflows, callable $fn) {
        $index = rtrim($dir, '/') . '/.git/tiknix-publish.index';
        @unlink($index);
        $env = ['GIT_INDEX_FILE' => $index];

        try {
            self::git($dir, $env, ['add', '-A']);
            self::git($dir, $env, ['rm', '--cached', '-r', '--ignore-unmatch', '--quiet',
                'conf/*.ini', ':(exclude)conf/*.example.ini', '.aibuilder']);
            if (!$keepWorkflows) {
                self::git($dir, $env, ['rm', '--cached', '-r', '--ignore-unmatch', '--quiet', '.github/workflows']);
            }
            return $fn($index);
        } finally {
            @unlink($index);
        }
    }

    /** The paths that would publish, relative to $dir. */
    public static function files(string $dir, bool $keepWorkflows = false): array {
        return self::withIndex($dir, $keepWorkflows, function (string $index) use ($dir) {
            $out = [];
            self::git($dir, ['GIT_INDEX_FILE' => $index], ['ls-files'], $out);
            return array_values(array_filter(array_map('trim', $out), fn($f) => $f !== ''));
        });
    }

    /** Run git in $dir with $env; collects output into $out when given. */
    private static function git(string $dir, array $env, array $args, array &$out = null): int {
        $cmd = '';
        foreach ($env as $k => $v) $cmd .= $k . '=' . escapeshellarg((string) $v) . ' ';
        $cmd .= 'git -C ' . escapeshellarg($dir);
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string) $a);
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        if ($out !== null) $out = $lines;
        return $code;
    }
}
