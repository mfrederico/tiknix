<?php
/**
 * An instance's ORIGIN (CONNECTOR-CATALOG-PLAN.md §13 C1): created once from the live clone,
 * task worktrees are cut from it and merged back INTO it, and the live tree only ever pulls,
 * pushes its own commits, or merges when both moved — never receives a task merge.
 *
 * Real git repositories under a temp root; no instance, no database.
 */

namespace tests\unit;

use app\InstanceRepo;
use PHPUnit\Framework\TestCase;

class InstanceRepoTest extends TestCase {

    private string $root;
    private string $live;
    private const SLUG = 'demo-x1';

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-origin-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->live = $this->root . '/' . self::SLUG . '.tiknix';
        mkdir($this->live, 0700, true);
        InstanceRepo::useRoot($this->root);
        $this->git($this->live, 'init -q -b instance/' . self::SLUG);
        $this->git($this->live, 'config user.email t@example.com');
        $this->git($this->live, 'config user.name t');
        file_put_contents($this->live . '/app.txt', "v1\n");
        file_put_contents($this->live . '/site.db', "db-state-1\n");
        $this->git($this->live, 'add -A');
        $this->git($this->live, 'commit -q -m base');
        // the live clone's origin is the control plane — must survive as `core`
        $this->git($this->live, 'remote add origin /var/www/html/default/tiknix');
    }

    protected function tearDown(): void {
        InstanceRepo::useRoot(null);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function git(string $dir, string $args): string {
        // Scrubbed like InstanceRepo::git: under the pre-commit hook this process carries
        // GIT_DIR/GIT_INDEX_FILE for core's repository, and a plain git here would commit there.
        exec('env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE -u GIT_PREFIX git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "git {$args} in {$dir}: " . implode("\n", $out));
        return trim(implode("\n", $out));
    }

    private function commitIn(string $dir, string $file, string $body, string $msg): void {
        file_put_contents("{$dir}/{$file}", $body);
        $this->git($dir, 'add -A');
        $this->git($dir, 'commit -q -m ' . escapeshellarg($msg));
    }

    public function testOriginWorktreeMergeAndLiveSync(): void {
        $this->assertFalse(InstanceRepo::hasOrigin(self::SLUG));
        try { InstanceRepo::baseBranch(self::SLUG); $this->fail('no origin'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('instance-origin.php --slug=' . self::SLUG, $e->getMessage()); }

        $steps = InstanceRepo::createOrigin(self::SLUG, $this->live);
        $this->assertTrue(InstanceRepo::hasOrigin(self::SLUG));
        $this->assertSame('instance/' . self::SLUG, InstanceRepo::baseBranch(self::SLUG));
        $this->assertSame(InstanceRepo::originPath(self::SLUG), $this->git($this->live, 'remote get-url origin'), 'live origin → the bare repo');
        $this->assertSame('/var/www/html/default/tiknix', $this->git($this->live, 'remote get-url core'), 'what was origin is core now');
        $this->assertSame('', $this->git(InstanceRepo::originPath(self::SLUG), 'remote'), 'the origin fetches from nobody');
        $this->assertDirectoryExists(InstanceRepo::mergeTreePath(self::SLUG));
        $this->assertStringContainsString('renamed core', implode("\n", $steps));
        try { InstanceRepo::createOrigin(self::SLUG, $this->live); $this->fail('twice'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('already has an origin', $e->getMessage()); }

        // a task worktree, cut from the origin, living in the live tree's .aibuilder/
        $wt = $this->live . '/.aibuilder/wt/task-1';
        InstanceRepo::addWorktree(self::SLUG, $wt, 'plan-1/task-1', 'instance/' . self::SLUG);
        $this->assertSame(InstanceRepo::originPath(self::SLUG), InstanceRepo::repoOf($wt), 'the worktree belongs to the origin, not the live clone');
        $this->assertSame('plan-1/task-1', $this->git($wt, 'rev-parse --abbrev-ref HEAD'));
        $this->git($wt, 'config user.email a@example.com'); $this->git($wt, 'config user.name a');
        $this->commitIn($wt, 'feature.txt', "hello\n", 'task 1');

        // the live runtime database is modified (as it always is) — it must not block a sync
        file_put_contents($this->live . '/site.db', "db-state-2\n");

        $m = InstanceRepo::merge(self::SLUG, 'plan-1/task-1', 'merge task 1');
        $this->assertSame('merged', $m['status'], $m['out']);
        $this->assertFileDoesNotExist($this->live . '/feature.txt', 'a merge on the origin does not touch the live tree');
        $s = InstanceRepo::syncLive($this->live);
        $this->assertSame('pulled', $s['status'], $s['out']);
        $this->assertFileExists($this->live . '/feature.txt');
        $this->assertSame("db-state-2\n", file_get_contents($this->live . '/site.db'), 'the live database was left alone');
        $this->assertSame('up-to-date', InstanceRepo::syncLive($this->live)['status']);

        InstanceRepo::removeWorktree($wt, true, 'plan-1/task-1');
        $this->assertDirectoryDoesNotExist($wt);
        $this->assertSame('', $this->git(InstanceRepo::originPath(self::SLUG), 'branch --list plan-1/task-1'));

        // the live tree commits (a checkpoint) → it stays local; the origin never learns of it
        $this->commitIn($this->live, 'site.db', "db-state-3\n", 'checkpoint: before plan 2');
        $s = InstanceRepo::syncLive($this->live);
        $this->assertSame('local-ahead', $s['status'], $s['out']);
        $originTip = $this->git(InstanceRepo::originPath(self::SLUG), 'rev-parse instance/' . self::SLUG);
        $this->assertNotSame($this->git($this->live, 'rev-parse HEAD'), $originTip, 'nothing was pushed');
        $wt2 = $this->live . '/.aibuilder/wt/task-2';
        InstanceRepo::addWorktree(self::SLUG, $wt2, 'plan-2/task-2', InstanceRepo::baseBranch(self::SLUG));
        $this->assertSame("db-state-1\n", file_get_contents($wt2 . '/site.db'), 'a task is cut from the origin: code only, no checkpoint state');

        // both moved: a live checkpoint and a task merged on the origin → merged locally, still nothing pushed
        $this->git($wt2, 'config user.email a@example.com'); $this->git($wt2, 'config user.name a');
        $this->commitIn($wt2, 'two.txt', "2\n", 'task 2');
        $this->assertSame('merged', InstanceRepo::merge(self::SLUG, 'plan-2/task-2', 'merge task 2')['status']);
        $s = InstanceRepo::syncLive($this->live);
        $this->assertSame('merged', $s['status'], $s['out']);
        $this->assertFileExists($this->live . '/two.txt');
        $this->assertSame("db-state-3\n", file_get_contents($this->live . '/site.db'), 'the checkpointed database state survived the merge');
        $this->assertFalse(is_file(InstanceRepo::mergeTreePath(self::SLUG) . '/note.txt'));
        $this->assertStringNotContainsString('checkpoint', $this->git(InstanceRepo::originPath(self::SLUG), 'log --oneline instance/' . self::SLUG), 'no checkpoint ever reached the origin');
        InstanceRepo::removeWorktree($wt2, true, 'plan-2/task-2');
    }

    public function testAConflictAbortsAndNamesTheFilesAndTheMergeTreeStaysClean(): void {
        InstanceRepo::createOrigin(self::SLUG, $this->live);
        $base = InstanceRepo::baseBranch(self::SLUG);
        $a = $this->live . '/.aibuilder/wt/task-a';
        $b = $this->live . '/.aibuilder/wt/task-b';
        InstanceRepo::addWorktree(self::SLUG, $a, 'plan-1/task-a', $base);
        InstanceRepo::addWorktree(self::SLUG, $b, 'plan-1/task-b', $base);
        foreach ([$a, $b] as $d) { $this->git($d, 'config user.email a@example.com'); $this->git($d, 'config user.name a'); }
        $this->commitIn($a, 'app.txt', "from a\n", 'a');
        $this->commitIn($b, 'app.txt', "from b\n", 'b');
        $this->assertSame('merged', InstanceRepo::merge(self::SLUG, 'plan-1/task-a', 'merge a')['status']);
        $m = InstanceRepo::merge(self::SLUG, 'plan-1/task-b', 'merge b');
        $this->assertSame('conflict', $m['status']);
        $this->assertSame(['app.txt'], $m['files']);
        $mt = InstanceRepo::mergeTreePath(self::SLUG);
        $this->assertSame('', $this->git($mt, 'status --porcelain'), 'the merge tree is clean after an aborted merge');
        $this->assertSame("from a\n", file_get_contents($mt . '/app.txt'));

        // a branch the origin does not have (a workspace cut before C1) is fetched from where it lives
        $legacy = $this->root . '/legacy-clone';
        $this->git($this->root, 'clone -q ' . escapeshellarg(InstanceRepo::originPath(self::SLUG)) . ' legacy-clone');
        $this->git($legacy, 'config user.email a@example.com'); $this->git($legacy, 'config user.name a');
        $this->git($legacy, 'checkout -q -b feature/legacy');
        $this->commitIn($legacy, 'legacy.txt', "old way\n", 'legacy');
        $m = InstanceRepo::merge(self::SLUG, 'feature/legacy', 'merge legacy', $legacy);
        $this->assertSame('merged', $m['status'], $m['out']);
        $this->assertSame('pulled', InstanceRepo::syncLive($this->live)['status']);
        $this->assertFileExists($this->live . '/legacy.txt');
    }

    public function testRefusals(): void {
        try { InstanceRepo::slugFromDir('/tmp/notaninstance'); $this->fail('no dot'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('not an <slug>.<app>', $e->getMessage()); }
        try { InstanceRepo::syncLive($this->live); $this->fail('no origin'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('no origin repository', $e->getMessage()); }
        $bare = $this->root . '/nogit.tiknix';
        mkdir($bare, 0700, true);
        try { InstanceRepo::createOrigin('nogit', $bare); $this->fail('no .git'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('no .git directory', $e->getMessage()); }
    }
}
