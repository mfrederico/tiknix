<?php
/**
 * InstanceUpdate — the instance's OWN update (CONNECTOR-CATALOG-PLAN.md §13, cutover C2):
 * `php scripts/clitool.php --update`, run in the instance by the instance's user.
 *
 *   1. builds    bring the live tree level with its origin (pullBuilds) — what
 *                the executor merged there since the last update
 *   2. release   fetch the control plane's tags (`core` remote) and pick the target: the
 *                one asked for, or the newest release tag; nothing to do when already on it
 *   3. preflight uncommitted code edits stop the update and are named (the tracked runtime
 *                database is expected to be modified and is not an edit)
 *   4. checkpoint a LOCAL commit + tag of everything as it is (the database included), the
 *                rollback point — never pushed anywhere: checkpoints are the instance's,
 *                and the origin holds code only (owner's call, 2026-09-28)
 *   5. merge     the release tag into the instance's branch; a conflict aborts, names the
 *                files and stops here — an operator's hand in the tree is not the answer
 *   6. post      concepts.lock synced from disk, composer when composer.json changed, the
 *                schema seeds (--build) and every enabled plugin's seeds, agent guidance,
 *                the permission cache, the claude link; then a smoke test of / and
 *                /auth/login
 *   7. pin       `.release` at the root records the release now running (read by
 *                /site/status); the report is what this returns
 *
 * Identity: whoever the instance's user is. On the control plane's disk that is the tree
 * owner for code (git, composer) — the pool is not the owner and files it created would be
 * — while every write into the instance's DATA already runs as the pool (seed 23 is the
 * shape: IsolatedPool::runAsPool). On a tenant container it is one user. Core never runs
 * this INTO an instance; it tags a release and says so.
 */

namespace app;

class InstanceUpdate {

    public const PIN_FILE = '.release';
    public const RELEASE_RE = '/^v\d+\.\d+(\.\d+)?$/D';
    public const CORE_REMOTE = 'core';

    /** @var callable(string $dir, string $command): array{0:int,1:string[]}  shell runner, injectable for tests */
    private $run;
    /** @var callable(string $url): int  HTTP status of a GET, injectable for tests */
    private $http;

    public function __construct(?callable $run = null, ?callable $http = null) {
        $this->run  = $run  ?? [self::class, 'sh'];
        $this->http = $http ?? [self::class, 'httpStatus'];
    }

    /**
     * @param array{release?:string,dry_run?:bool,skip_post?:bool} $opts
     * @return array{ok:bool,status:string,release:string,from:string,lines:string[]}
     *         status: updated | up-to-date | refused | conflict | failed
     */
    public function run(string $root, array $opts = []): array {
        $root = rtrim($root, '/');
        $lines = [];
        $say = function (string $s) use (&$lines): void { $lines[] = $s; };
        $dry = !empty($opts['dry_run']);

        // --- where we are -----------------------------------------------------------------
        if (!is_dir($root . '/.git')) return $this->done(false, 'refused', '', '', ["{$root} is not a git clone"]);
        $branch = trim($this->git($root, ['rev-parse', '--abbrev-ref', 'HEAD'])['out']);
        if (strpos($branch, 'instance/') !== 0) {
            return $this->done(false, 'refused', '', '', ["{$root} is on '{$branch}', not an instance branch — this is the control plane (or not an instance); a release is tagged here with --release, never merged into it"]);
        }
        $coreUrl = trim($this->git($root, ['remote', 'get-url', self::CORE_REMOTE])['out']);
        if ($coreUrl === '' || str_starts_with($coreUrl, 'fatal')) {
            return $this->done(false, 'refused', '', '', ["no '" . self::CORE_REMOTE . "' remote: the control plane is not reachable from here — on core's disk run scripts/instance-origin.php --slug=<slug> (C1); a tenant sets core = its /git/core.git URL"]);
        }
        $pinned = self::pinned($root);

        // --- 1. builds from the origin ------------------------------------------------------
        try {
            $s = self::pullBuilds($root);
        } catch (\Throwable $e) {
            return $this->done(false, 'failed', '', $pinned, ["origin: " . $e->getMessage()]);
        }
        if ($s['status'] === 'failed') return $this->done(false, 'failed', '', $pinned, ["origin: {$s['out']}"]);
        $say("origin: {$s['status']}" . ($s['out'] !== '' ? " ({$s['out']})" : ''));

        // --- 2. the release --------------------------------------------------------------------
        $f = $this->git($root, ['fetch', '--quiet', '--tags', self::CORE_REMOTE]);
        if (!$f['ok']) return $this->done(false, 'failed', '', $pinned, ['fetch from ' . self::CORE_REMOTE . " ({$coreUrl}) failed: " . trim($f['out'])] + $lines);
        $tags = self::releaseTags($this->git($root, ['tag', '--list', 'v*'])['out']);
        $want = trim((string) ($opts['release'] ?? ''));
        if ($want !== '') {
            if (!preg_match(self::RELEASE_RE, $want)) return $this->done(false, 'refused', '', $pinned, array_merge($lines, ["'{$want}' is not a release tag (vMAJOR.MINOR[.PATCH])"]));
            if (!in_array($want, $tags, true)) return $this->done(false, 'refused', '', $pinned, array_merge($lines, ["release {$want} is not a tag on " . self::CORE_REMOTE . ($tags ? '; releases: ' . implode(', ', $tags) : '; it has no release tags yet')]));
            $target = $want;
        } else {
            if (!$tags) return $this->done(false, 'refused', '', $pinned, array_merge($lines, ['the control plane has no release tags yet — tag one there with: php scripts/clitool.php --release']));
            $target = end($tags);
        }
        $targetSha = trim($this->git($root, ['rev-list', '-n', '1', $target])['out']);
        $have = $this->git($root, ['merge-base', '--is-ancestor', $targetSha, 'HEAD'])['ok'];
        if ($have) {
            if ($pinned !== $target && !$dry) self::pin($root, $target, $targetSha);
            $say("release: {$target} already merged" . ($pinned !== $target ? " (pin was '{$pinned}', now {$target})" : ''));
            return $this->done(true, 'up-to-date', $target, $pinned, $lines);
        }
        $count = (int) trim($this->git($root, ['rev-list', '--count', 'HEAD..' . $targetSha])['out']);
        $say("release: {$target} — {$count} commit(s) to merge" . ($pinned !== '' ? " (running {$pinned})" : ''));

        // --- 3. preflight -------------------------------------------------------------------------
        $dirt = self::codeEdits($this->git($root, ['status', '--porcelain'])['out']);
        if ($dirt) return $this->done(false, 'refused', $target, $pinned, array_merge($lines, ['uncommitted code edits in the live tree: ' . implode(', ', array_slice($dirt, 0, 8)) . (count($dirt) > 8 ? ' …' : '') . ' — commit them (a checkpoint) or discard them, then update']));
        if ($dry) { $say('dry-run: would checkpoint, merge, run seeds/guidance/cache, smoke-test and pin'); return $this->done(true, 'dry-run', $target, $pinned, $lines); }

        // --- 4. local checkpoint ------------------------------------------------------------------
        $tag = 'checkpoint-update-' . date('Ymd-His');
        $this->git($root, ['add', '-A']);
        foreach (self::trackedDbs($this->git($root, ['ls-files', '--', '*.db', '*.sqlite'])['out']) as $db) $this->git($root, ['add', '-f', '--', $db]);
        if (!$this->git($root, ['diff', '--cached', '--quiet'])['ok']) {
            $c = $this->git($root, ['-c', 'user.email=update@tiknix.local', '-c', 'user.name=update', 'commit', '-q', '-m', "checkpoint: before {$target}"]);
            if (!$c['ok']) return $this->done(false, 'failed', $target, $pinned, array_merge($lines, ['checkpoint commit failed: ' . trim($c['out'])]));
        }
        $t = $this->git($root, ['tag', '-f', $tag]);
        if (!$t['ok']) return $this->done(false, 'failed', $target, $pinned, array_merge($lines, ['checkpoint tag failed: ' . trim($t['out'])]));
        $say("checkpoint: {$tag} (local; roll back with: git reset --hard {$tag} — the instance's own decision)");

        // --- 5. merge --------------------------------------------------------------------------------
        $composerBefore = is_file("{$root}/composer.json") ? hash_file('sha256', "{$root}/composer.json") : '';
        $m = $this->git($root, ['-c', 'user.email=update@tiknix.local', '-c', 'user.name=update', 'merge', '--no-edit', '-m', "release {$target}", $targetSha]);
        if (!$m['ok']) {
            $conf = array_values(array_filter(array_map('trim', explode("\n", (string) $this->git($root, ['diff', '--name-only', '--diff-filter=U'])['out']))));
            $this->git($root, ['merge', '--abort']);
            $isConflict = stripos($m['out'], 'conflict') !== false;
            \Flight::get('log')?->error('InstanceUpdate: release merge ' . ($isConflict ? 'conflict' : 'failed'), ['release' => $target, 'files' => $conf, 'out' => trim($m['out'])]);
            return $this->done(false, $isConflict ? 'conflict' : 'failed', $target, $pinned, array_merge($lines,
                [($isConflict ? "merge conflict with {$target}" : "merge of {$target} failed") . ($conf ? ' — files: ' . implode(', ', $conf) : '') . ' — aborted; the tree is as it was' . ($isConflict ? ' (resolve on a task branch, then update again)' : ''), trim($m['out'])]));
        }
        $say("merged {$target} (" . substr($targetSha, 0, 7) . ')');

        // --- 6. post -----------------------------------------------------------------------------------
        $failed = [];
        if (empty($opts['skip_post'])) {
            foreach ($this->post($root, $coreUrl, $composerBefore) as $step => [$ok, $detail]) {
                $say("{$step}: " . ($ok ? 'ok' : 'FAILED') . ($detail !== '' ? " — {$detail}" : ''));
                if (!$ok) $failed[] = $step;
            }
        }

        // --- 7. pin ------------------------------------------------------------------------------------
        self::pin($root, $target, $targetSha);
        $say("release: {$target} pinned in " . self::PIN_FILE);
        if ($failed) {
            \Flight::get('log')?->error('InstanceUpdate: post-merge step(s) failed', ['release' => $target, 'failed' => $failed]);
            return $this->done(false, 'failed', $target, $pinned, array_merge($lines, ['merged, but ' . implode(', ', $failed) . ' FAILED — the code is on ' . $target . '; fix the step and re-run --update (the merge is not repeated)']));
        }
        return $this->done(true, 'updated', $target, $pinned, $lines);
    }

    /**
     * Everything after the merge. Each step: [ok, detail]. Composer only when composer.json
     * changed: with the control plane on this disk its lock is copied (that is how the fleet
     * has always been kept identical); a tenant resolves its own.
     *
     * @return array<string,array{0:bool,1:string}>
     */
    private function post(string $root, string $coreUrl, string $composerBefore): array {
        $out = [];
        $cli = 'php -d error_reporting=0 scripts/clitool.php';
        try { $r = ConceptLock::sync($root); $out['concepts.lock'] = [true, count($r['kept']) . ' kept, ' . count($r['added']) . ' added, ' . count($r['removed']) . ' removed']; }
        catch (\Throwable $e) { $out['concepts.lock'] = [false, $e->getMessage()]; }

        $composerAfter = is_file("{$root}/composer.json") ? hash_file('sha256', "{$root}/composer.json") : '';
        if ($composerAfter !== $composerBefore) {
            $coreLock = is_dir($coreUrl) && is_file("{$coreUrl}/composer.lock") ? "{$coreUrl}/composer.lock" : '';
            if ($coreLock !== '') {
                copy($coreLock, "{$root}/composer.lock");
                [$code, $o] = ($this->run)($root, 'COMPOSER_ALLOW_SUPERUSER=1 php -d error_reporting=0 /usr/bin/composer install --no-interaction --no-dev -q 2>&1');
                $out['composer'] = [$code === 0, ($code === 0 ? "install from the control plane's lock" : trim(implode(' ', array_slice($o, -2))))];
            } else {
                [$code, $o] = ($this->run)($root, 'COMPOSER_ALLOW_SUPERUSER=1 php -d error_reporting=0 /usr/bin/composer update --no-interaction --no-dev -q 2>&1');
                $out['composer'] = [$code === 0, ($code === 0 ? 'update (no control-plane lock reachable: resolved here)' : trim(implode(' ', array_slice($o, -2))))];
            }
            [$code] = ($this->run)($root, 'php -d error_reporting=0 /usr/bin/composer dump-autoload -o -q 2>&1');
            if ($code !== 0) $out['composer'] = [false, 'dump-autoload failed'];
        } else {
            $out['composer'] = [true, 'composer.json unchanged'];
        }

        [$code, $o] = ($this->run)($root, "{$cli} --build 2>&1");
        // --build exits non-zero when a seed failed; its "<seed>: error: …" line is the detail
        $errs = array_values(array_filter($o, fn($l) => preg_match('/: error: |ERROR|FAILED/', $l) && !str_contains($l, 'fluid schema change')));
        $out['schema seeds'] = [$code === 0 && !$errs, $errs ? trim(implode(' | ', array_slice($errs, 0, 2))) : ($code !== 0 ? 'exit ' . $code . ': ' . trim(implode(' ', array_slice($o, -2))) : '')];
        if (is_file("{$root}/concepts.lock")) {
            [$code, $o] = ($this->run)($root, "{$cli} --concept-seeds=all 2>&1");
            $out['plugin seeds'] = [$code === 0, $code === 0 ? '' : trim(implode(' ', array_slice($o, -2)))];
        }
        [$code, $o] = ($this->run)($root, "{$cli} --agent-sync 2>&1");
        $out['agent guidance'] = [$code === 0, $code === 0 ? trim((string) end($o)) : trim(implode(' ', array_slice($o, -2)))];
        [$code] = ($this->run)($root, 'php -d error_reporting=0 scripts/resetcache.php 2>&1');
        $out['permission cache'] = [$code === 0, ''];
        if (is_file("{$root}/scripts/claude-link.php")) {
            [$code, $o] = ($this->run)($root, 'php -d error_reporting=0 scripts/claude-link.php --root=' . escapeshellarg($root) . ' 2>&1');
            $out['claude link'] = [$code === 0, trim((string) end($o))];
        }

        $base = self::baseUrl($root);
        if ($base === '') {
            $out['smoke'] = [false, 'conf/config.ini has no [app] baseurl to test'];
        } else {
            $bad = [];
            foreach (['/', '/auth/login'] as $p) { $st = ($this->http)($base . $p); if ($st !== 200) $bad[] = "{$p} → {$st}"; }
            $out['smoke'] = [!$bad, $bad ? implode(', ', $bad) : "{$base}/ and /auth/login answer 200"];
        }

        // Overrides (the owner's rule, 2026-09-28): an app file that replaces a runtime file is
        // NOT upgraded by this update — the app's copy keeps answering. Not a failure: the
        // runtime moved on as it should. Said by name, so the owner knows which of their
        // copies now lags the runtime and must be reconciled by hand (--overrides).
        $rt = is_dir("{$root}/runtime") ? "{$root}/runtime" : "{$root}/vendor/tiknix/runtime";
        if (is_dir($rt)) {
            try {
                $rep = Overrides::report($root, $rt);
                $stale = array_keys(array_filter($rep, fn($r) => $r['status'] === 'STALE'));
                $unrec = array_keys(array_filter($rep, fn($r) => $r['status'] === 'unrecorded'));
                $detail = count($rep) . ' override(s)';
                if ($stale) $detail .= '; STALE — the runtime changed these and your copies were NOT upgraded, reconcile them yourself: ' . implode(', ', $stale);
                if ($unrec) $detail .= '; unrecorded (no base to compare): ' . implode(', ', $unrec);
                if ($stale || $unrec) \Flight::get('log')?->warning('InstanceUpdate: overrides need the owner', ['stale' => $stale, 'unrecorded' => $unrec]);
                $out['overrides'] = [true, $detail];
            } catch (\Throwable $e) {
                $out['overrides'] = [false, $e->getMessage()];
            }
        }
        return $out;
    }

    /**
     * Bring this live tree level with its origin — the builds merged there (RUNTIME-SPLIT-MAP
     * step 2: moved here from InstanceRepo::syncLive so the runtime needs nothing of the
     * control plane's). Fetch; fast-forward when the origin is ahead; merge locally when both
     * moved. NEVER pushes: the live tree's own commits are checkpoints — its database and
     * local state — and stay the instance's; the origin holds code only. A merge the tree
     * cannot take is reported with the files, never forced.
     *
     * @param callable|null $git  (dir, args) → {ok, out}; the control plane passes one that
     *                            names its origin as a safe directory
     * @return array{status:string,out:string} up-to-date | pulled | local-ahead | merged | failed
     */
    public static function pullBuilds(string $liveDir, ?callable $git = null, string $label = ''): array {
        $liveDir = rtrim($liveDir, '/');
        $git = $git ?? fn(string $dir, array $args) => (new self())->git($dir, $args);
        $slug = $label !== '' ? $label : basename($liveDir);
        $branch = trim($git($liveDir, ['rev-parse', '--abbrev-ref', 'HEAD'])['out']);
        if ($branch === '' || $branch === 'HEAD') return ['status' => 'failed', 'out' => "{$liveDir} is not on a branch"];
        $f = $git($liveDir, ['fetch', '--quiet', 'origin']);
        if (!$f['ok']) return ['status' => 'failed', 'out' => 'fetch from the origin failed: ' . trim($f['out'])];
        $local  = trim($git($liveDir, ['rev-parse', 'HEAD'])['out']);
        $remote = $git($liveDir, ['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/' . $branch]);
        {
            if (!$remote['ok']) {
                return ['status' => 'failed', 'out' => "the origin has no branch {$branch} — recreate it with scripts/instance-origin.php"];
            }
            $remoteSha = trim($remote['out']);
            if ($remoteSha === $local) return ['status' => 'up-to-date', 'out' => substr($local, 0, 7)];
            $behind = $git($liveDir, ['merge-base', '--is-ancestor', $local, $remoteSha])['ok'];
            $ahead  = $git($liveDir, ['merge-base', '--is-ancestor', $remoteSha, $local])['ok'];
            if ($behind) {
                $m = $git($liveDir, ['merge', '--ff-only', '--quiet', $remoteSha]);
                return $m['ok'] ? ['status' => 'pulled', 'out' => substr($local, 0, 7) . ' → ' . substr($remoteSha, 0, 7)]
                                : ['status' => 'failed', 'out' => 'the live tree could not fast-forward: ' . trim($m['out'])];
            }
            if ($ahead) {
                return ['status' => 'local-ahead', 'out' => 'the live tree has ' . trim($git($liveDir, ['rev-list', '--count', $remoteSha . '..' . $local])['out']) . ' local commit(s) (checkpoints stay here)'];
            }
            $m = $git($liveDir, ['-c', 'user.email=update@tiknix.local', '-c', 'user.name=update', 'merge', '--no-ff', '--quiet', '-m', "sync: builds from the origin of {$slug}", $remoteSha]);
            if (!$m['ok']) {
                $conf = $git($liveDir, ['diff', '--name-only', '--diff-filter=U']);
                $files = trim(str_replace("\n", ', ', (string) $conf['out']));
                $git($liveDir, ['merge', '--abort']);
                return ['status' => 'failed', 'out' => 'the live tree and the origin both changed and the merge failed' . ($files !== '' ? " — conflicting: {$files}" : '') . ': ' . trim($m['out'])];
            }
            return ['status' => 'merged', 'out' => 'both sides had commits; merged locally (nothing pushed)'];
        }
    }

    // ---- release tags on the control plane -----------------------------------------------------

    /**
     * Tag a release on the control plane: the suite passes, main is checked out and clean,
     * the tag is the next patch of the newest release (or the one given). Nothing is pushed
     * anywhere; instances pull it with --update.
     *
     * @return array{ok:bool,tag:string,message:string}
     */
    public function tagRelease(string $root, string $tag = '', string $notes = ''): array {
        $root = rtrim($root, '/');
        $branch = trim($this->git($root, ['rev-parse', '--abbrev-ref', 'HEAD'])['out']);
        if ($branch !== 'main') return ['ok' => false, 'tag' => '', 'message' => "releases are tagged on main; this tree is on '{$branch}'"];
        if (self::codeEdits($this->git($root, ['status', '--porcelain'])['out'])) return ['ok' => false, 'tag' => '', 'message' => 'main has uncommitted edits; commit first'];
        $tags = self::releaseTags($this->git($root, ['tag', '--list', 'v*'])['out']);
        if ($tag === '') {
            $last = $tags ? end($tags) : '';
            if ($last === '') return ['ok' => false, 'tag' => '', 'message' => 'no release tag exists yet — name the first one: --release=v1.6.0'];
            $p = array_map('intval', explode('.', substr($last, 1)));
            $tag = 'v' . $p[0] . '.' . ($p[1] ?? 0) . '.' . (($p[2] ?? 0) + 1);
        }
        if (!preg_match(self::RELEASE_RE, $tag)) return ['ok' => false, 'tag' => '', 'message' => "'{$tag}' is not a release tag (vMAJOR.MINOR[.PATCH])"];
        if (in_array($tag, $tags, true)) return ['ok' => false, 'tag' => '', 'message' => "{$tag} already exists"];
        [$code, $o] = ($this->run)($root, 'tests/run.sh 2>&1');
        if ($code !== 0) return ['ok' => false, 'tag' => '', 'message' => 'the suite is red — not tagging: ' . trim(implode(' ', array_slice($o, -2)))];
        $t = $this->git($root, ['tag', '-a', $tag, '-m', $notes !== '' ? $notes : "release {$tag}"]);
        if (!$t['ok']) return ['ok' => false, 'tag' => '', 'message' => trim($t['out'])];
        return ['ok' => true, 'tag' => $tag, 'message' => "tagged {$tag} at " . trim($this->git($root, ['rev-parse', '--short', 'HEAD'])['out'])];
    }

    /** @return string[] release tags, oldest first (v1.5 < v1.5.1 < v1.6.0); rc and other tags left out */
    public static function releaseTags(string $list): array {
        $tags = array_values(array_filter(array_map('trim', explode("\n", $list)), fn($t) => preg_match(self::RELEASE_RE, $t)));
        usort($tags, fn($a, $b) => version_compare(substr($a, 1), substr($b, 1)));
        return $tags;
    }

    // ---- pin ---------------------------------------------------------------------------------------

    public static function pinned(string $root): string {
        $f = rtrim($root, '/') . '/' . self::PIN_FILE;
        if (!is_file($f)) return '';
        return trim((string) strtok((string) file_get_contents($f), " \n"));
    }

    private static function pin(string $root, string $tag, string $sha): void {
        file_put_contents(rtrim($root, '/') . '/' . self::PIN_FILE, "{$tag} {$sha} " . date('c') . "\n");
    }

    // ---- helpers -----------------------------------------------------------------------------------

    /** Paths with local edits that are code — the tracked runtime database churns on every request and is not one. */
    public static function codeEdits(string $porcelain): array {
        $out = [];
        // Never trim the whole text: porcelain lines START with a status column that may be
        // a space (" M app.txt"), and trimming it off the first line eats the path's first letter.
        foreach (explode("\n", rtrim($porcelain, "\n")) as $line) {
            if (!preg_match('/^([ MADRCU?!]{2}) (.+)$/', $line, $m)) continue;
            if ($m[1] === '??') continue;                    // untracked files are not edits of tracked code
            $path = $m[2];
            if (str_contains($path, ' -> ')) $path = substr($path, strpos($path, ' -> ') + 4);
            if (preg_match('/\.(db|sqlite)$/', $path)) continue;
            $out[] = $path;
        }
        return $out;
    }

    private static function trackedDbs(string $list): array {
        return array_values(array_filter(array_map('trim', explode("\n", $list))));
    }

    private static function baseUrl(string $root): string {
        $cfg = @parse_ini_file("{$root}/conf/config.ini", true) ?: [];
        return rtrim(trim((string) ($cfg['app']['baseurl'] ?? '')), '/');
    }

    private function done(bool $ok, string $status, string $release, string $from, array $lines): array {
        return ['ok' => $ok, 'status' => $status, 'release' => $release, 'from' => $from, 'lines' => $lines];
    }

    /** @return array{ok:bool,out:string} a clean-environment git, like InstanceRepo's */
    private function git(string $dir, array $args): array {
        $cmd = 'env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE -u GIT_PREFIX git -c ' . escapeshellarg('safe.directory=' . $dir) . ' -C ' . escapeshellarg($dir) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $out)];
    }

    /** @return array{0:int,1:string[]} */
    private static function sh(string $dir, string $command): array {
        // TIKNIX_WORKBENCH_DB must not leak into what runs in the instance (see PlanExecutor::runInProject)
        exec('cd ' . escapeshellarg($dir) . ' && env -u TIKNIX_WORKBENCH_DB ' . $command, $out, $code);
        return [(int) $code, $out];
    }

    private static function httpStatus(string $url): int {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);   // no curl_close: deprecated in PHP 8.5
    }
}
