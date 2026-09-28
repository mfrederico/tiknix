<?php
/**
 * InstanceRepo — an instance's ORIGIN: the bare repository builds land on, that the live
 * tree and a tenant container pull from (CONNECTOR-CATALOG-PLAN.md §13, cutover C1).
 *
 *   <ROOT>/_origins/<slug>.git     the bare origin; HEAD → refs/heads/instance/<slug>
 *   <ROOT>/_origins/<slug>.merge   a worktree of it on that branch, used ONLY to merge
 *                                  (a bare repo cannot merge); always clean, reset before use
 *   <live>/.aibuilder/wt/…         task worktrees — cut from the ORIGIN, living in the live
 *                                  tree's gitignored .aibuilder/ (the jail binds that path)
 *
 * Until 2026-09-28 every task worktree was cut from the live clone's .git and every task
 * branch was merged INTO the live tree by the executor — core writing git history into a
 * running site because it could reach the directory. Now the live tree is a plain clone
 * whose `origin` remote is this bare repository: builds merge here, the live tree pulls
 * (syncLive — the seed of the instance's own `update`, C2), and the git endpoint serves this
 * same repository to tenants, so what a container fetches is what was merged, never the
 * state a live tree happens to be in. The live tree's `core` remote is the control plane's
 * repository, for release merges until C2 turns those into tags.
 *
 * Every write here is by the tree owner into a repository the tree owner created; the
 * instance's pool user gets an ACL on the origin so an agent running as the pool can use
 * its worktree (index, logs) and, in C2, fetch from it.
 */

namespace app;

class InstanceRepo {

    public const ORIGINS = '_origins';

    private static ?string $root = null;

    /** The directory that holds instance trees and `_origins/` — overridable for tests. */
    public static function useRoot(?string $root): void { self::$root = $root !== null ? rtrim($root, '/') : null; }

    public static function root(): string { return self::$root ?? \Model_Instance::ROOT; }

    public static function originsDir(): string { return self::root() . '/' . self::ORIGINS; }

    public static function originPath(string $slug): string { return self::originsDir() . '/' . self::assertSlug($slug) . '.git'; }

    public static function mergeTreePath(string $slug): string { return self::originsDir() . '/' . self::assertSlug($slug) . '.merge'; }

    public static function hasOrigin(string $slug): bool { return is_file(self::originPath($slug) . '/HEAD'); }

    /** `<slug>.<app>` → slug. A path that is not an instance directory is refused, not guessed. */
    public static function slugFromDir(string $dir): string {
        $base = basename(rtrim($dir, '/'));
        $slug = strpos($base, '.') !== false ? substr($base, 0, strpos($base, '.')) : '';
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $slug)) {
            throw new \RuntimeException("InstanceRepo: {$dir} is not an <slug>.<app> instance directory.");
        }
        return $slug;
    }

    /** The origin's default branch (instance/<slug>) — what worktrees are cut from and merged into. */
    public static function baseBranch(string $slug): string {
        self::assertOrigin($slug);
        $r = self::git(self::originPath($slug), ['symbolic-ref', '--short', 'HEAD']);
        if (!$r['ok'] || trim($r['out']) === '') throw new \RuntimeException("InstanceRepo: origin of '{$slug}' has no HEAD branch — " . $r['out']);
        return trim($r['out']);
    }

    /**
     * Create the origin from a live clone, once: a bare clone of it, HEAD on its branch, the
     * merge worktree, the pool's ACL, and the live tree re-pointed (`origin` → here, its old
     * origin — the control plane — kept as `core`). Refuses an origin that already exists.
     *
     * @return string[] what was done, one line each
     */
    public static function createOrigin(string $slug, string $liveDir, ?string $poolUser = null): array {
        self::assertSlug($slug);
        $liveDir = rtrim($liveDir, '/');
        if (self::hasOrigin($slug)) throw new \RuntimeException("InstanceRepo: '{$slug}' already has an origin at " . self::originPath($slug) . '.');
        if (!is_dir($liveDir . '/.git')) throw new \RuntimeException("InstanceRepo: {$liveDir} has no .git directory to create an origin from.");
        $branch = trim(self::git($liveDir, ['rev-parse', '--abbrev-ref', 'HEAD'])['out']);
        if ($branch === '' || $branch === 'HEAD') throw new \RuntimeException("InstanceRepo: {$liveDir} is not on a branch (detached HEAD); check out instance/{$slug} first.");
        $steps = [];
        $origin = self::originPath($slug);
        if (!is_dir(self::originsDir()) && !mkdir(self::originsDir(), 0755, true)) throw new \RuntimeException('InstanceRepo: could not create ' . self::originsDir());

        $r = self::git(self::originsDir(), ['clone', '--bare', '--quiet', $liveDir, $origin]);
        if (!$r['ok']) throw new \RuntimeException("InstanceRepo: bare clone of {$liveDir} failed — " . $r['out']);
        $steps[] = "bare origin {$origin} from {$liveDir}";
        self::must($origin, ['symbolic-ref', 'HEAD', 'refs/heads/' . $branch]);
        // A bare clone records the live tree as its remote; nothing may ever fetch FROM the live tree.
        self::git($origin, ['remote', 'remove', 'origin']);
        // Should anything ever push the branch the merge tree has checked out (a migration
        // pushing by hand), update that worktree in place instead of refusing. The live tree
        // itself never pushes (syncLive): checkpoints stay local.
        self::must($origin, ['config', 'receive.denyCurrentBranch', 'updateInstead']);
        $steps[] = "HEAD → {$branch}";

        $mt = self::mergeTreePath($slug);
        self::must($origin, ['worktree', 'add', '--quiet', $mt, $branch]);
        $steps[] = "merge worktree {$mt}";

        if ($poolUser !== null && $poolUser !== '') {
            foreach ([$origin, $mt] as $p) {
                exec('setfacl -R -m ' . escapeshellarg("u:{$poolUser}:rwx") . ' -m ' . escapeshellarg("d:u:{$poolUser}:rwx") . ' ' . escapeshellarg($p) . ' 2>&1', $o, $c);
                if ($c !== 0) throw new \RuntimeException("InstanceRepo: setfacl for {$poolUser} on {$p} failed — " . implode(' ', $o));
            }
            $steps[] = "ACL: {$poolUser} rwx on the origin and the merge worktree";
        }

        // Re-point the live tree: its `origin` becomes this repository; what it called origin
        // (the control plane's repository) stays reachable as `core` for release merges.
        $cur = trim(self::git($liveDir, ['remote', 'get-url', 'origin'])['out']);
        $hasCore = self::git($liveDir, ['remote', 'get-url', 'core'])['ok'];
        if ($cur !== '' && $cur !== $origin) {
            if (!$hasCore) { self::must($liveDir, ['remote', 'rename', 'origin', 'core']); $steps[] = "live remote origin ({$cur}) renamed core"; }
            else           { self::must($liveDir, ['remote', 'remove', 'origin']); }
        }
        if (trim(self::git($liveDir, ['remote', 'get-url', 'origin'])['out']) !== $origin) {
            self::must($liveDir, ['remote', 'add', 'origin', $origin]);
        }
        self::must($liveDir, ['config', "branch.{$branch}.remote", 'origin']);
        self::must($liveDir, ['config', "branch.{$branch}.merge", 'refs/heads/' . $branch]);
        self::must($liveDir, ['fetch', '--quiet', 'origin']);
        $steps[] = "live remote origin → {$origin} (tracking {$branch})";
        return $steps;
    }

    /** Cut a worktree of the origin at $abs on $branch (created from $base unless it exists). */
    public static function addWorktree(string $slug, string $abs, string $branch, string $base): void {
        self::assertOrigin($slug);
        $origin = self::originPath($slug);
        self::git($origin, ['worktree', 'prune']);
        if (is_dir($abs)) throw new \RuntimeException("InstanceRepo: {$abs} exists and is not a worktree the origin knows about; remove it before running this task.");
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true)) throw new \RuntimeException('InstanceRepo: could not create ' . dirname($abs));
        $exists = self::git($origin, ['rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch])['ok'];
        $r = $exists
            ? self::git($origin, ['worktree', 'add', '--quiet', $abs, $branch])
            : self::git($origin, ['worktree', 'add', '--quiet', '-b', $branch, $abs, $base]);
        if (!$r['ok']) throw new \RuntimeException("InstanceRepo: git worktree add at {$abs} failed — " . trim($r['out']));
    }

    /**
     * The repository a worktree belongs to, read from its .git pointer file — the origin for
     * a worktree cut after C1, the live clone's .git for one cut before. Never guessed.
     */
    public static function repoOf(string $worktreeAbs): string {
        $file = rtrim($worktreeAbs, '/') . '/.git';
        if (is_dir($file)) return rtrim($worktreeAbs, '/');            // a clone, not a worktree
        if (!is_file($file)) throw new \RuntimeException("InstanceRepo: {$worktreeAbs} has no .git — not a worktree.");
        if (!preg_match('/^gitdir:\s*(.+?)\s*$/m', (string) file_get_contents($file), $m)) {
            throw new \RuntimeException("InstanceRepo: {$file} is not a worktree pointer.");
        }
        $gitdir = $m[1];
        if (!str_starts_with($gitdir, '/')) $gitdir = rtrim($worktreeAbs, '/') . '/' . $gitdir;
        // <repo>/worktrees/<name> → <repo> (the bare origin, or a clone's .git — both take git -C)
        return dirname(dirname($gitdir));
    }

    /** Unregister and delete a worktree; optionally drop its branch in the repository it belongs to. */
    public static function removeWorktree(string $abs, bool $dropBranch = false, string $branch = ''): void {
        $abs = rtrim($abs, '/');
        if (is_dir($abs)) {
            $repo = self::repoOf($abs);
            $r = self::git($repo, ['worktree', 'remove', '--force', $abs]);
            if (!$r['ok']) throw new \RuntimeException("InstanceRepo: git worktree remove {$abs} failed — " . trim($r['out']));
            if ($dropBranch && $branch !== '') self::git($repo, ['branch', '-D', $branch]);
        }
    }

    /**
     * Merge a task branch into the origin's base branch, in the merge worktree, under a
     * per-instance lock. $fetchFrom names a repository holding the branch when the origin
     * does not (a worktree cut from the live clone before C1); otherwise the branch is the
     * origin's own. A conflict aborts and names the files; the merge tree is left clean.
     *
     * @return array{status:string,out:string,files:string[],sha:string} status merged | conflict | failed
     */
    public static function merge(string $slug, string $branch, string $message, ?string $fetchFrom = null): array {
        self::assertOrigin($slug);
        $mt = self::mergeTreePath($slug);
        if (!is_dir($mt . '/.git') && !is_file($mt . '/.git')) throw new \RuntimeException("InstanceRepo: '{$slug}' has no merge worktree at {$mt} — recreate the origin.");
        $base = self::baseBranch($slug);
        $lock = self::lock($slug);
        try {
            self::git($mt, ['merge', '--abort']);                       // a half-finished earlier merge, if any
            self::must($mt, ['checkout', '--quiet', $base]);
            self::must($mt, ['reset', '--quiet', '--hard', 'refs/heads/' . $base]);
            $ref = $branch;
            if ($fetchFrom !== null && !self::git($mt, ['rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch])['ok']) {
                $f = self::git($mt, ['fetch', '--quiet', $fetchFrom, $branch]);
                if (!$f['ok']) return ['status' => 'failed', 'out' => "could not fetch {$branch} from {$fetchFrom}: " . trim($f['out']), 'files' => [], 'sha' => ''];
                $ref = 'FETCH_HEAD';
            }
            $m = self::git($mt, ['merge', '--no-ff', '-m', $message, $ref]);
            if ($m['ok']) {
                return ['status' => 'merged', 'out' => '', 'files' => [], 'sha' => trim(self::git($mt, ['rev-parse', '--short', 'HEAD'])['out'])];
            }
            $conf = self::git($mt, ['diff', '--name-only', '--diff-filter=U']);
            $files = array_values(array_filter(array_map('trim', explode("\n", (string) $conf['out']))));
            self::git($mt, ['merge', '--abort']);
            self::git($mt, ['reset', '--quiet', '--hard', 'refs/heads/' . $base]);
            $isConflict = stripos($m['out'], 'conflict') !== false;
            return ['status' => $isConflict ? 'conflict' : 'failed', 'out' => trim($m['out']), 'files' => $files, 'sha' => ''];
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Bring the live tree level with its origin (what --update runs first, C2): fetch;
     * fast-forward the live tree when the origin is ahead; merge locally when both moved.
     * The live tree NEVER pushes: its own commits are checkpoints — the runtime database
     * and local state — and those stay the instance's (owner's call, 2026-09-28); the origin
     * holds code only, which is why a tenant can fetch it. A merge the live tree cannot take
     * (a file changed on both sides) is reported with the files, never forced — the tracked
     * runtime database is expected to be modified and is not touched by task commits, so
     * it does not block a fast-forward.
     *
     * @return array{status:string,out:string} status up-to-date | pulled | local-ahead | merged | failed
     */
    public static function syncLive(string $liveDir): array {
        $liveDir = rtrim($liveDir, '/');
        $slug = self::slugFromDir($liveDir);
        self::assertOrigin($slug);
        $branch = trim(self::git($liveDir, ['rev-parse', '--abbrev-ref', 'HEAD'])['out']);
        if ($branch === '' || $branch === 'HEAD') return ['status' => 'failed', 'out' => "{$liveDir} is not on a branch"];
        if (trim(self::git($liveDir, ['remote', 'get-url', 'origin'])['out']) !== self::originPath($slug)) {
            return ['status' => 'failed', 'out' => "{$liveDir}: remote origin is not " . self::originPath($slug) . ' — run scripts/instance-origin.php --slug=' . $slug];
        }
        $f = self::git($liveDir, ['fetch', '--quiet', 'origin']);
        if (!$f['ok']) return ['status' => 'failed', 'out' => 'fetch from the origin failed: ' . trim($f['out'])];
        $local  = trim(self::git($liveDir, ['rev-parse', 'HEAD'])['out']);
        $remote = self::git($liveDir, ['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/' . $branch]);
        $lock = self::lock($slug);
        try {
            if (!$remote['ok']) {
                return ['status' => 'failed', 'out' => "the origin has no branch {$branch} — recreate it with scripts/instance-origin.php"];
            }
            $remoteSha = trim($remote['out']);
            if ($remoteSha === $local) return ['status' => 'up-to-date', 'out' => substr($local, 0, 7)];
            $behind = self::git($liveDir, ['merge-base', '--is-ancestor', $local, $remoteSha])['ok'];
            $ahead  = self::git($liveDir, ['merge-base', '--is-ancestor', $remoteSha, $local])['ok'];
            if ($behind) {
                $m = self::git($liveDir, ['merge', '--ff-only', '--quiet', $remoteSha]);
                return $m['ok'] ? ['status' => 'pulled', 'out' => substr($local, 0, 7) . ' → ' . substr($remoteSha, 0, 7)]
                                : ['status' => 'failed', 'out' => 'the live tree could not fast-forward: ' . trim($m['out'])];
            }
            if ($ahead) {
                return ['status' => 'local-ahead', 'out' => 'the live tree has ' . trim(self::git($liveDir, ['rev-list', '--count', $remoteSha . '..' . $local])['out']) . ' local commit(s) (checkpoints stay here)'];
            }
            $m = self::git($liveDir, ['-c', 'user.email=update@tiknix.local', '-c', 'user.name=update', 'merge', '--no-ff', '--quiet', '-m', "sync: builds from the origin of {$slug}", $remoteSha]);
            if (!$m['ok']) {
                $conf = self::git($liveDir, ['diff', '--name-only', '--diff-filter=U']);
                $files = trim(str_replace("\n", ', ', (string) $conf['out']));
                self::git($liveDir, ['merge', '--abort']);
                return ['status' => 'failed', 'out' => 'the live tree and the origin both changed and the merge failed' . ($files !== '' ? " — conflicting: {$files}" : '') . ': ' . trim($m['out'])];
            }
            return ['status' => 'merged', 'out' => 'both sides had commits; merged locally (nothing pushed)'];
        } finally {
            self::unlock($lock);
        }
    }

    // ---- internals ------------------------------------------------------------------------

    private static function assertSlug(string $slug): string {
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 64) throw new \InvalidArgumentException("InstanceRepo: '{$slug}' is not an instance slug.");
        return $slug;
    }

    private static function assertOrigin(string $slug): void {
        if (!self::hasOrigin($slug)) {
            throw new \RuntimeException("Instance '{$slug}' has no origin repository at " . self::originPath($slug)
                . ' — create it with: php scripts/instance-origin.php --slug=' . $slug);
        }
    }

    /**
     * git -C $dir, with the directories this class deliberately operates on declared safe
     * for THIS invocation: the origin is owned by the provisioning user and used by the
     * pool user (a worktree's commands resolve to it; C2's update fetches from it), and git
     * refuses a repository owned by somebody else unless told which one. Named per call —
     * never `*`, never a global config the pool would have to be given.
     *
     * @return array{ok:bool,out:string,code:int}
     */
    private static function git(string $dir, array $args): array {
        $safe = ['-c', 'safe.directory=' . $dir];
        $origins = self::originsDir();
        if (!str_starts_with($dir, $origins . '/') && preg_match('#^(.+?/[^/]+\.[^/]+)(?:/|$)#', $dir, $m) && is_dir($m[1] . '/.git')) {
            try { $safe = array_merge($safe, ['-c', 'safe.directory=' . $m[1], '-c', 'safe.directory=' . self::originPath(self::slugFromDir($m[1]))]); }
            catch (\Throwable $e) { /* not an instance tree: only $dir is declared */ }
        }
        // A clean git environment: a process started BY git (a hook, a filter) inherits
        // GIT_DIR / GIT_INDEX_FILE / GIT_WORK_TREE for ITS repository, and every command here
        // would then act on that one — the unit suite run by the pre-commit hook created the
        // merge worktree against the hook's index ("index file open failed: Not a directory").
        $env = 'env ' . implode(' ', array_map(fn($v) => '-u ' . $v, ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_PREFIX', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_NAMESPACE']));
        $cmd = $env . ' git ' . implode(' ', array_map('escapeshellarg', $safe)) . ' -C ' . escapeshellarg($dir) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $out), 'code' => (int) $code];
    }

    private static function must(string $dir, array $args): void {
        $r = self::git($dir, $args);
        if (!$r['ok']) throw new \RuntimeException('InstanceRepo: git ' . implode(' ', $args) . " in {$dir} failed — " . trim($r['out']));
    }

    /** @return resource */
    private static function lock(string $slug) {
        $file = self::originsDir() . '/' . $slug . '.lock';
        $h = fopen($file, 'c');
        if ($h === false || !flock($h, LOCK_EX)) throw new \RuntimeException("InstanceRepo: could not lock {$file}.");
        return $h;
    }

    private static function unlock($h): void {
        if (is_resource($h)) { flock($h, LOCK_UN); fclose($h); }
    }
}
