<?php
/**
 * The instance's own update (CONNECTOR-CATALOG-PLAN.md §13 C2), on real repositories under a
 * temp root: a fake control plane with release tags, an instance clone with its origin.
 *
 *   refusals     not an instance branch; no core remote; no release tags; an unknown tag;
 *                uncommitted code edits (the runtime database is not one)
 *   update       builds from the origin come first; the newest release merges; a LOCAL
 *                checkpoint tag exists and never reaches the origin; .release is pinned;
 *                the post steps ran (through the injected runner); a second run is up-to-date
 *   conflict     aborted, files named, the tree as it was, nothing pinned
 *   release      tagging on the control plane: next patch, named tag, refused off main
 */

namespace tests\unit;

use app\InstanceRepo;
use app\InstanceUpdate;
use PHPUnit\Framework\TestCase;

class InstanceUpdateTest extends TestCase {

    private string $root;
    private string $core;
    private string $live;
    private array $ran = [];
    private const SLUG = 'demo-u1';

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-update-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->core = $this->root . '/tiknix';
        $this->live = $this->root . '/' . self::SLUG . '.tiknix';
        mkdir($this->core, 0700, true);
        InstanceRepo::useRoot($this->root);
        // the control plane: main, two commits, tests/run.sh that passes
        $this->git($this->core, 'init -q -b main');
        $this->ident($this->core);
        mkdir($this->core . '/tests');
        file_put_contents($this->core . '/tests/run.sh', "#!/bin/sh\nexit 0\n"); chmod($this->core . '/tests/run.sh', 0755);
        file_put_contents($this->core . '/lib.txt', "core v1\n");
        file_put_contents($this->core . '/composer.json', "{}\n");
        $this->git($this->core, 'add -A'); $this->git($this->core, 'commit -q -m core1');
        // the instance: a clone on instance/<slug> with its own file and a tracked runtime db
        $this->git($this->root, 'clone -q ' . escapeshellarg($this->core) . ' ' . escapeshellarg($this->live));
        $this->ident($this->live);
        $this->git($this->live, 'checkout -q -b instance/' . self::SLUG);
        file_put_contents($this->live . '/app.txt', "app\n");
        file_put_contents($this->live . '/site.db', "db1\n");
        mkdir($this->live . '/conf'); file_put_contents($this->live . '/conf/config.ini', "[app]\nbaseurl = \"https://demo.example.com\"\n");
        $this->git($this->live, 'add -A'); $this->git($this->live, 'commit -q -m instance');
        InstanceRepo::createOrigin(self::SLUG, $this->live);   // origin → bare, core → the fake control plane
        $this->assertSame($this->core, $this->git($this->live, 'remote get-url core'));
    }

    protected function tearDown(): void {
        InstanceRepo::useRoot(null);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function git(string $dir, string $args): string {
        exec('env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE -u GIT_PREFIX git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "git {$args} in {$dir}: " . implode("\n", $out));
        return trim(implode("\n", $out));
    }

    private function ident(string $dir): void { $this->git($dir, 'config user.email t@example.com'); $this->git($dir, 'config user.name t'); }

    private function coreCommit(string $file, string $body, string $msg): void {
        file_put_contents($this->core . '/' . $file, $body);
        $this->git($this->core, 'add -A'); $this->git($this->core, 'commit -q -m ' . escapeshellarg($msg));
    }

    /** An updater whose post steps are recorded, not run, and whose smoke test answers 200. */
    private function updater(): InstanceUpdate {
        $this->ran = [];
        return new InstanceUpdate(function (string $dir, string $cmd): array { $this->ran[] = $cmd; return [0, ['ok']]; }, fn(string $url) => 200);
    }

    public function testRefusals(): void {
        $u = $this->updater();
        $r = $u->run($this->core);
        $this->assertSame('refused', $r['status']); $this->assertStringContainsString('control plane', implode(' ', $r['lines']));

        $r = $u->run($this->live);
        $this->assertSame('refused', $r['status']); $this->assertStringContainsString('no release tags yet', implode(' ', $r['lines']));

        $this->git($this->core, 'tag v1.6.0');
        $r = $u->run($this->live, ['release' => 'v9.9.9']);
        $this->assertSame('refused', $r['status']); $this->assertStringContainsString('v9.9.9 is not a tag', implode(' ', $r['lines']));

        $r = $u->run($this->live);
        $this->assertSame('up-to-date', $r['status'], 'v1.6.0 is the commit the instance was cloned from');
        $this->assertSame('v1.6.0', InstanceUpdate::pinned($this->live), 'pinned all the same');

        $this->coreCommit('lib.txt', "core v2\n", 'core2');
        $this->git($this->core, 'tag v1.6.1');
        file_put_contents($this->live . '/app.txt', "edited by hand\n");
        file_put_contents($this->live . '/site.db', "db-churn\n");
        $r = $u->run($this->live);
        $this->assertSame('refused', $r['status']);
        $this->assertStringContainsString('uncommitted code edits', implode(' ', $r['lines']));
        $this->assertStringContainsString('app.txt', implode(' ', $r['lines']));
        $this->assertStringNotContainsString('site.db', implode(' ', $r['lines']), 'the runtime database is churn, not an edit');
    }

    public function testUpdateMergesTheReleaseKeepsTheCheckpointLocalAndPins(): void {
        $u = $this->updater();
        // a build merged on the origin, and two releases on core
        $wt = $this->live . '/.aibuilder/wt/task-1';
        InstanceRepo::addWorktree(self::SLUG, $wt, 'plan-1/task-1', InstanceRepo::baseBranch(self::SLUG));
        $this->ident($wt);
        file_put_contents($wt . '/built.txt', "by a task\n"); $this->git($wt, 'add -A'); $this->git($wt, 'commit -q -m task');
        $this->assertSame('merged', InstanceRepo::merge(self::SLUG, 'plan-1/task-1', 'merge task 1')['status']);
        $this->git($this->core, 'tag v1.6.0');
        $this->coreCommit('lib.txt', "core v2\n", 'core2');
        $this->coreCommit('composer.json', "{\"require\":{}}\n", 'composer change');
        $this->git($this->core, 'tag v1.6.1');
        file_put_contents($this->live . '/site.db', "db-live-state\n");   // churning, as always

        $r = $u->run($this->live);
        $this->assertSame('updated', $r['status'], implode("\n", $r['lines']));
        $this->assertSame('v1.6.1', $r['release'], 'the newest release, not the first');
        $this->assertFileExists($this->live . '/built.txt', 'the origin\'s build came first');
        $this->assertSame("core v2\n", file_get_contents($this->live . '/lib.txt'));
        $this->assertSame("app\n", file_get_contents($this->live . '/app.txt'), 'the instance\'s own file survives');
        $this->assertSame('v1.6.1', InstanceUpdate::pinned($this->live));
        $this->assertStringStartsWith('v1.6.1 ', file_get_contents($this->live . '/.release'));

        // the checkpoint: a local tag on a commit holding the database state, and NOT on the origin
        $tags = $this->git($this->live, 'tag --list checkpoint-update-*');
        $this->assertNotSame('', $tags);
        $ck = trim(explode("\n", $tags)[0]);
        $this->assertSame('db-live-state', $this->git($this->live, "show {$ck}:site.db"), 'the checkpoint holds the database as it was');
        $this->assertSame('', $this->git(InstanceRepo::originPath(self::SLUG), 'tag --list checkpoint-update-*'), 'checkpoints never reach the origin');
        $this->assertStringNotContainsString('checkpoint', $this->git(InstanceRepo::originPath(self::SLUG), 'log --oneline instance/' . self::SLUG));

        // the post steps ran, in the instance, through the runner; composer because composer.json changed
        $joined = implode("\n", $this->ran);
        foreach (['--build', '--concept-seeds=all', '--agent-sync', 'resetcache.php', 'composer'] as $needle) $this->assertStringContainsString($needle, $joined, $needle);
        $this->assertStringContainsString('smoke: ok', implode("\n", $r['lines']));

        // again: nothing to do
        $r2 = $u->run($this->live);
        $this->assertSame('up-to-date', $r2['status'], implode("\n", $r2['lines']));

        // a named older release that is already merged is up-to-date too; an explicit pin change is recorded
        $r3 = $u->run($this->live, ['release' => 'v1.6.0']);
        $this->assertSame('up-to-date', $r3['status']);
    }

    public function testAConflictAbortsNamesTheFilesAndPinsNothing(): void {
        $u = $this->updater();
        $this->git($this->core, 'tag v1.6.0');
        // the instance changed a file core also changes in the next release
        file_put_contents($this->live . '/lib.txt', "instance's own lib\n");
        $this->git($this->live, 'add -A'); $this->git($this->live, 'commit -q -m "instance edits lib"');
        $this->coreCommit('lib.txt', "core v2\n", 'core2');
        $this->git($this->core, 'tag v1.6.1');
        $head = $this->git($this->live, 'rev-parse HEAD');

        $r = $u->run($this->live);
        $this->assertSame('conflict', $r['status'], implode("\n", $r['lines']));
        $this->assertStringContainsString('lib.txt', implode(' ', $r['lines']));
        $this->assertSame('', $this->git($this->live, 'status --porcelain'), 'the tree is as it was');
        $this->assertSame("instance's own lib\n", file_get_contents($this->live . '/lib.txt'));
        $this->assertSame('', InstanceUpdate::pinned($this->live), 'nothing pinned');
        $this->assertNotSame('', $this->git($this->live, 'tag --list checkpoint-update-*'), 'the checkpoint stays, for the retry');
        $this->assertSame([], $this->ran, 'no post step ran');
        $ck = trim(explode("\n", $this->git($this->live, 'tag --list checkpoint-update-*'))[0]);
        $this->assertSame($head, $this->git($this->live, "rev-parse {$ck}^{commit}"), 'a clean tree needed no checkpoint commit: the tag marks HEAD as it was');
        $this->assertSame($head, $this->git($this->live, 'rev-parse HEAD'));
    }

    public function testTaggingAReleaseOnTheControlPlane(): void {
        $u = $this->updater();
        $r = $u->tagRelease($this->core);
        $this->assertFalse($r['ok']); $this->assertStringContainsString('--release=v1.6.0', $r['message']);
        $r = $u->tagRelease($this->core, 'v1.6.0', 'first release');
        $this->assertTrue($r['ok'], $r['message']); $this->assertSame('v1.6.0', $r['tag']);
        $this->assertContains('tests/run.sh 2>&1', $this->ran, 'the suite ran first');
        $this->coreCommit('lib.txt', "v2\n", 'more');
        $r = $u->tagRelease($this->core);
        $this->assertSame('v1.6.1', $r['tag'], 'next patch by default');
        $this->assertFalse($u->tagRelease($this->core, 'v1.6.1')['ok'], 'exists');
        $this->assertFalse($u->tagRelease($this->core, 'v1.7-rc1')['ok'], 'not a release tag');
        $this->assertFalse($u->tagRelease($this->live)['ok'], 'not main');
        $this->assertSame(['v1.5', 'v1.5.1', 'v1.6.0', 'v1.6.1'], InstanceUpdate::releaseTags("v1.6.1\nv1.5\nv1.6-rc1\nv1.6.0\nv1.5.1\ncheckpoint-x\n"));
    }
}
