<?php
/**
 * QaDiff — what changed in a project's code, read for QA Testing's code review
 * (QA-TESTING-PLAN.md, phase 5), with the facts a machine can establish already attached.
 *
 * The code is in the project's container; it is read over SSH (git), never run. From a base
 * commit to HEAD — the commit the last review ended at, or the last few commits the first
 * time — each added or changed file comes back with its patch, the lines it added, and:
 *
 *   checks   what the platform's own validators say about the file as it is now
 *            (ValidationService: PHP syntax, the RedBean and FlightPHP rules, the security
 *            scan), kept ONLY where they are about this change — on a line the change added,
 *            or anywhere in a file the change created. An old problem in a touched file is
 *            not this review's finding.
 *   and for the whole app, the runtime files it replaces without a record (Overrides).
 *
 * Deleted files, vendored code, lock files, minified assets and the generated CLAUDE.md are
 * left out. Sizes are bounded and what was cut is said, never silently dropped.
 */

namespace app;

class QaDiff {

    public const FIRST_COMMITS = 10;        // how far back the first review of a project looks
    public const MAX_FILES     = 40;
    public const MAX_PATCH     = 12000;     // characters of one file's patch
    public const MAX_TOTAL     = 600000;    // bytes of diff read from the container
    private const EXCLUDE = ['vendor', 'node_modules', 'public/rt', '*.lock', '*.min.js', '*.min.css', 'CLAUDE.md', '*.png', '*.jpg', '*.jpeg', '*.gif', '*.webp', '*.ico', '*.woff', '*.woff2', '*.pdf', '*.zip'];

    /**
     * @return array{ok:bool,error?:string,head?:string,base?:string,first?:bool,commits?:array,files?:array,skipped?:array,overrides?:array}
     */
    public static function read(object $inst, string $since = ''): array {
        $git = fn(string $args, int $timeout = 60) => TenantHost::ssh($inst, 'app', 'git -C /srv/app ' . $args . ' 2>&1', null, $timeout);

        [$c, $head] = $git('rev-parse HEAD');
        $head = trim($head);
        if ($c !== 0 || !preg_match('/^[0-9a-f]{40}$/', $head)) return ['ok' => false, 'error' => "{$inst->slug}'s repository could not be read: " . mb_substr($head, 0, 200)];

        $first = false;
        if ($since !== '') {
            if (!preg_match('/^[0-9a-f]{7,40}$/', $since)) return ['ok' => false, 'error' => 'the commit to review from is not a commit id'];
            [$c, $base] = $git('rev-parse --verify --quiet ' . escapeshellarg($since . '^{commit}'));
            $base = trim($base);
            if ($c !== 0 || !preg_match('/^[0-9a-f]{40}$/', $base)) return ['ok' => false, 'error' => "the commit {$since} the last review ended at is no longer in the repository — its history was rewritten (a rollback?)"];
        } else {
            // The first review: the last few commits, or all of it when the project is younger than that.
            $first = true;
            [, $n] = $git('rev-list --count HEAD');
            if ((int) trim($n) > self::FIRST_COMMITS) { [, $base] = $git('rev-parse HEAD~' . self::FIRST_COMMITS); $base = trim($base); }
            else { [, $base] = $git('hash-object -t tree /dev/null'); $base = trim($base); }          // the empty tree: everything
            if (!preg_match('/^[0-9a-f]{40}$/', $base)) return ['ok' => false, 'error' => 'the starting commit could not be resolved'];
        }
        if ($base === $head) return ['ok' => true, 'head' => $head, 'base' => $base, 'first' => $first, 'commits' => [], 'files' => [], 'skipped' => [], 'overrides' => []];

        $commits = [];
        [, $log] = $git('log --no-merges --format=%h%x09%an%x09%ad%x09%s --date=short ' . ($first && strlen($base) === 40 && self::isTree($git, $base) ? '' : escapeshellarg($base . '..HEAD')) . ' | head -60');
        foreach (array_filter(explode("\n", $log)) as $line) {
            $p = explode("\t", $line, 4);
            if (count($p) === 4) $commits[] = ['id' => $p[0], 'by' => $p[1], 'on' => $p[2], 'subject' => mb_substr($p[3], 0, 160)];
        }

        $ex = implode(' ', array_map(fn($e) => escapeshellarg(':(exclude)' . $e), self::EXCLUDE));
        [$c, $diff] = TenantHost::ssh($inst, 'app', 'git -C /srv/app diff --no-color -U6 -M --diff-filter=AMR ' . escapeshellarg($base) . ' HEAD -- . ' . $ex . ' 2>&1 | head -c ' . self::MAX_TOTAL, null, 120);
        if ($c !== 0) return ['ok' => false, 'error' => 'the changes could not be read: ' . mb_substr($diff, 0, 200)];
        $cut = strlen($diff) >= self::MAX_TOTAL;

        $files = []; $skipped = [];
        foreach (preg_split('/^(?=diff --git )/m', $diff, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
            if (!preg_match('#^diff --git a/.+ b/(.+)$#m', $chunk, $m)) continue;
            $path = trim($m[1]);
            if (str_contains($chunk, "\nBinary files ") || str_contains($chunk, 'GIT binary patch')) continue;
            if (count($files) >= self::MAX_FILES) { $skipped[] = $path; continue; }
            $at = strpos($chunk, "\n@@");
            $patch = $at === false ? '' : substr($chunk, $at + 1);
            // The lines this change added, by their number in the file as it is now.
            $added = []; $n = 0; $adds = 0; $dels = 0;
            foreach (explode("\n", $patch) as $line) {
                if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $line, $h)) { $n = (int) $h[1]; continue; }
                if ($line === '' || $line[0] === '\\') continue;
                if ($line[0] === '+') { $added[$n] = substr($line, 1); $adds++; $n++; }
                elseif ($line[0] === '-') { $dels++; }
                else { $n++; }
            }
            $files[] = ['path' => $path, 'status' => str_contains($chunk, "\nnew file mode ") ? 'added' : 'changed', 'additions' => $adds, 'deletions' => $dels,
                        'patch' => mb_substr($patch, 0, self::MAX_PATCH), 'patch_cut' => mb_strlen($patch) > self::MAX_PATCH, 'added' => $added, 'checks' => []];
        }
        if ($cut && $files) { $last = array_pop($files); $skipped[] = $last['path']; }     // the file the cut fell in is incomplete

        self::check($inst, $files);

        [$c, $ov] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php -d error_reporting=0 -r ' . escapeshellarg(
            'require "vendor/autoload.php"; echo json_encode(array_keys(array_filter(app\Overrides::report("/srv/app", "/srv/app/vendor/tiknix/runtime"), fn($r) => $r["status"] === "unrecorded")));'), null, 60);
        $overrides = json_decode(trim($ov), true);
        if ($c !== 0 || !is_array($overrides)) return ['ok' => false, 'error' => "the app's overrides could not be read: " . mb_substr(trim($ov), 0, 200)];

        foreach ($files as &$f) unset($f['added_all']);
        return ['ok' => true, 'head' => $head, 'base' => $base, 'first' => $first, 'commits' => $commits, 'files' => $files, 'skipped' => $skipped, 'overrides' => array_values($overrides)];
    }

    private static function isTree(callable $git, string $id): bool {
        [, $t] = $git('cat-file -t ' . escapeshellarg($id));
        return trim($t) === 'tree';
    }

    /** The platform's validators on each changed PHP file as it is at HEAD; only what this change is answerable for is kept. */
    private static function check(object $inst, array &$files): void {
        $php = array_values(array_filter(array_column($files, 'path'), fn($p) => str_ends_with($p, '.php')));
        if (!$php) return;
        $mark = '@@@QA-FILE-' . bin2hex(random_bytes(6)) . ' ';
        $cmd = 'cd /srv/app && for f in ' . implode(' ', array_map('escapeshellarg', $php)) . '; do printf "\n%s%s\n" ' . escapeshellarg($mark) . ' "$f"; git show "HEAD:$f"; done';
        [$c, $out] = TenantHost::ssh($inst, 'app', $cmd, null, 120);
        if ($c !== 0) throw new \RuntimeException('the changed files could not be read for checking: ' . mb_substr($out, 0, 200));
        $source = [];
        foreach (explode("\n" . $mark, $out) as $part) {
            $nl = strpos($part, "\n");
            if ($nl === false) continue;
            $source[trim(substr($part, 0, $nl))] = substr($part, $nl + 1);
        }
        $v = new ValidationService(Paths::root());
        foreach ($files as &$f) {
            if (!isset($source[$f['path']])) continue;
            $r = $v->validateCode($source[$f['path']], $f['path']);
            if (empty($r['valid'])) {
                $f['checks'][] = ['severity' => 'red', 'line' => 0, 'message' => 'It does not parse: ' . mb_substr(preg_replace('/^PHP Parse error:\s*/', '', (string) ($r['errors'][0] ?? 'syntax error')), 0, 300)];
                continue;
            }
            foreach ((array) ($r['errors'] ?? []) as $e) {
                $line = preg_match('/^\[[^\]]*?:(\d+)\]/', (string) $e, $m) ? (int) $m[1] : 0;
                // This change's doing: on a line it added, or anywhere in a file it created.
                if (!($line > 0 ? isset($f['added'][$line]) : $f['status'] === 'added')) continue;
                $f['checks'][] = ['severity' => 'amber', 'line' => $line, 'message' => mb_substr(trim((string) preg_replace('/^\[[^\]]*\]\s*/', '', (string) $e)), 0, 400)];
            }
        }
    }
}
