<?php
/**
 * An app's own update (RUNTIME-SPLIT-MAP.md step 3): the tiknix/runtime package moves to a
 * newer release. Real git repositories under a temp root; Composer is played by the injected
 * runner — it lists the published releases, rewrites composer.lock on an update, or fails.
 *
 *   refusals   not a repository; the lock does not list the runtime; a path-repository
 *              checkout (developed, not updated); nothing published; an unknown release;
 *              uncommitted code edits (a tracked database is not one)
 *   update     builds from the origin first; the newest release; a LOCAL checkpoint that never
 *              reaches the origin; the lock committed; post steps run; pinned; then up-to-date
 *   failure    composer fails: composer.json and the lock back as they were, vendor reinstalled,
 *              nothing committed or pinned
 *   release    cut where the runtime is developed: the checkout's main, next patch or named
 */

namespace tests\unit;

use app\InstanceUpdate;
use PHPUnit\Framework\TestCase;

class InstanceUpdateTest extends TestCase {

    private string $root;
    private string $app;
    private string $origin;
    private array $ran = [];
    /** @var string[] what "composer show --all" lists */
    private array $published = [];
    private bool $composerFails = false;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-update-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->app = $this->root . '/app';
        $this->origin = $this->root . '/origin.git';
        mkdir($this->app . '/conf', 0700, true);
        mkdir($this->app . '/vendor/tiknix/runtime', 0700, true);   // an (empty) installed runtime
        $this->git($this->root, 'init -q --bare ' . escapeshellarg($this->origin));
        $this->git($this->app, 'init -q -b main');
        $this->ident($this->app);
        file_put_contents($this->app . '/.gitignore', "vendor/\n");
        file_put_contents($this->app . '/composer.json', json_encode(['require' => ['tiknix/runtime' => '^2.0@alpha']]) . "\n");
        $this->lock('v2.0.0-alpha.1');
        file_put_contents($this->app . '/app.txt', "app\n");
        file_put_contents($this->app . '/site.db', "db1\n");
        file_put_contents($this->app . '/conf/config.ini', "[app]\nbaseurl = \"https://demo.example.com\"\n");
        $this->git($this->app, 'add -A'); $this->git($this->app, 'add -f site.db'); $this->git($this->app, 'commit -q -m app');
        $this->git($this->app, 'remote add origin ' . escapeshellarg($this->origin));
        $this->git($this->app, 'push -q origin main');
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function git(string $dir, string $args): string {
        exec('env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE -u GIT_PREFIX git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "git {$args} in {$dir}: " . implode("\n", $out));
        return trim(implode("\n", $out));
    }

    private function ident(string $dir): void { $this->git($dir, 'config user.email t@example.com'); $this->git($dir, 'config user.name t'); }

    /** composer.lock listing the runtime at $version (a release from a VCS repo, or a path checkout). */
    private function lock(string $version, bool $path = false, string $dir = ''): void {
        $dir = $dir ?: $this->app;
        $pkg = ['name' => 'tiknix/runtime', 'version' => $version,
                'source' => ['type' => 'git', 'url' => '/srv/tiknix-runtime', 'reference' => sha1($version)]];
        if ($path) $pkg = ['name' => 'tiknix/runtime', 'version' => $version, 'dist' => ['type' => 'path', 'url' => '../tiknix-runtime', 'reference' => sha1($version)]];
        file_put_contents($dir . '/composer.lock', json_encode(['packages' => [$pkg], 'packages-dev' => []], JSON_PRETTY_PRINT) . "\n");
    }

    /** An updater whose shell is recorded and whose composer is simulated; the smoke test answers 200. */
    private function updater(): InstanceUpdate {
        $this->ran = [];
        return new InstanceUpdate(function (string $dir, string $cmd): array {
            $this->ran[] = $cmd;
            if (str_contains($cmd, ' show tiknix/runtime --all')) {
                return [0, [json_encode(['name' => 'tiknix/runtime', 'versions' => array_merge($this->published, ['dev-main'])])]];
            }
            if (preg_match("/ update tiknix\\/runtime --with='tiknix\\/runtime:([^']+)'/", $cmd, $m)) {
                if ($this->composerFails) { file_put_contents($dir . '/composer.lock', "half-written\n"); return [2, ['Your requirements could not be resolved']]; }
                $this->lock('v' . $m[1], false, $dir);
                return [0, ['Upgrading tiknix/runtime']];
            }
            if (str_contains($cmd, '--agent-sync')) {   // the new release's guidance differs
                file_put_contents($dir . '/AGENTS.md', 'guidance ' . count($this->ran) . "\n");
                return [0, ['# AGENTS.md: regenerated (0 enabled concept(s))']];
            }
            return [0, ['ok']];
        }, fn(string $url) => str_contains($url, "/site/status?probe=") ? 204 : 200);   // the app answers its identity probe
    }

    public function testRefusals(): void {
        $u = $this->updater();
        mkdir($this->root . '/not-a-repo');
        $this->assertStringContainsString('not the top of a git repository', implode(' ', $u->run($this->root . '/not-a-repo')['lines']));

        $this->assertSame('refused', ($r = $u->run($this->app))['status']);
        $this->assertStringContainsString('no tiknix/runtime release is published yet', implode(' ', $r['lines']));

        $this->published = ['v2.0.0-alpha.1'];
        $r = $u->run($this->app, ['release' => 'v9.9.9']);
        $this->assertSame('refused', $r['status']); $this->assertStringContainsString('v9.9.9 is not published', implode(' ', $r['lines']));
        $this->assertSame('refused', $u->run($this->app, ['release' => 'v2.0'])['status'], 'not a release name');

        $r = $u->run($this->app);
        $this->assertSame('up-to-date', $r['status'], implode("\n", $r['lines']));
        $this->assertSame('v2.0.0-alpha.1', InstanceUpdate::pinned($this->app), 'pinned all the same');

        $this->published[] = 'v2.0.0-alpha.2';
        file_put_contents($this->app . '/app.txt', "edited by hand\n");
        file_put_contents($this->app . '/site.db', "db-churn\n");
        $r = $u->run($this->app);
        $this->assertSame('refused', $r['status']);
        $this->assertStringContainsString('uncommitted code edits', implode(' ', $r['lines']));
        $this->assertStringContainsString('app.txt', implode(' ', $r['lines']));
        $this->assertStringNotContainsString('site.db', implode(' ', $r['lines']), 'a tracked database churns; it is not an edit');

        $this->git($this->app, 'checkout -q -- app.txt');
        $this->lock('dev-main', true);
        $r = $u->run($this->app);
        $this->assertSame('refused', $r['status']);
        $this->assertStringContainsString('developed, not updated', implode(' ', $r['lines']));

        unlink($this->app . '/composer.lock');
        $this->assertStringContainsString('does not list tiknix/runtime', implode(' ', $u->run($this->app)['lines']));
    }

    public function testUpdateMovesTheRuntimeKeepsTheCheckpointLocalAndPins(): void {
        $u = $this->updater();
        // a build on the origin, not yet in the app
        $clone = $this->root . '/builder';
        $this->git($this->root, 'clone -q ' . escapeshellarg($this->origin) . ' ' . escapeshellarg($clone));
        $this->ident($clone);
        file_put_contents($clone . '/built.txt', "by the builder\n"); $this->git($clone, 'add -A'); $this->git($clone, 'commit -q -m build'); $this->git($clone, 'push -q origin main');
        $this->published = ['v2.0.0-alpha.1', 'v2.0.0-alpha.2', 'v2.0.0-alpha.10'];
        file_put_contents($this->app . '/site.db', "db-live-state\n");   // churning, as always

        $r = $u->run($this->app);
        $this->assertSame('updated', $r['status'], implode("\n", $r['lines']));
        $this->assertSame('v2.0.0-alpha.10', $r['release'], 'the newest by version, not by string');
        $this->assertSame('v2.0.0-alpha.1', $r['from']);
        $this->assertFileExists($this->app . '/built.txt', "the origin's build came first");
        $this->assertSame('v2.0.0-alpha.10', InstanceUpdate::installedRuntime($this->app)['version']);
        $this->assertSame('v2.0.0-alpha.10', InstanceUpdate::pinned($this->app));
        $this->assertSame('', $this->git($this->app, 'status --porcelain -- composer.lock'), 'the new lock is committed');
        $this->assertStringContainsString('runtime v2.0.0-alpha.10 (was v2.0.0-alpha.1)', $this->git($this->app, 'log -2 --format=%s'));
        $this->assertSame('', $this->git($this->app, 'status --porcelain -- AGENTS.md CLAUDE.md'), 'the regenerated guidance is committed');
        $this->assertSame('Agent guidance (AGENTS.md) regenerated for the runtime update', $this->git($this->app, 'log -1 --format=%s -- AGENTS.md'));
        $this->assertStringContainsString('agent guidance: ok', implode("\n", $r['lines']));

        // the checkpoint: a local tag holding the database as it was, and NOT on the origin
        $ck = trim(explode("\n", $this->git($this->app, 'tag --list checkpoint-update-*'))[0]);
        $this->assertNotSame('', $ck);
        $this->assertSame('db-live-state', $this->git($this->app, "show {$ck}:site.db"));
        $this->assertSame('', $this->git($this->origin, 'tag --list checkpoint-update-*'), 'checkpoints never reach the origin');

        // the post steps ran in the app, through the runner
        $joined = implode("\n", $this->ran);
        foreach (['--concept-lock', '--build', '--agent-sync', 'resetcache.php'] as $needle) $this->assertStringContainsString($needle, $joined, $needle);
        $this->assertStringContainsString('smoke: ok', implode("\n", $r['lines']));
        $this->assertStringContainsString('overrides: ok', implode("\n", $r['lines']));

        $r2 = $u->run($this->app);
        $this->assertSame('up-to-date', $r2['status'], implode("\n", $r2['lines']));
    }

    public function testANotYetInstalledAppPassesTheSmokeTestOnlyWhenItsWizardAnswers(): void {
        $this->published = ['v2.0.0-alpha.1', 'v2.0.0-alpha.2'];
        $wizard = 200;
        $u = new InstanceUpdate(function (string $dir, string $cmd): array {
            if (str_contains($cmd, ' show tiknix/runtime --all')) return [0, [json_encode(['versions' => $this->published])]];
            if (preg_match("/ update tiknix\\/runtime --with='tiknix\\/runtime:([^']+)'/", $cmd, $m)) { $this->lock('v' . $m[1], false, $dir); return [0, []]; }
            return [0, ['ok']];
        }, function (string $url) use (&$wizard): int { if (str_contains($url, '/site/status?probe=')) return 204;   // /site/status is not behind the wizard
            return str_ends_with($url, '/install') ? $wizard : 303; });
        $r = $u->run($this->app);
        $this->assertSame('updated', $r['status'], implode("\n", $r['lines']));
        $this->assertStringContainsString('not set up yet', implode("\n", $r['lines']));

        $this->published[] = 'v2.0.0-alpha.3';
        $wizard = 500;
        $r = $u->run($this->app);
        $this->assertSame('failed', $r['status'], 'redirects to a wizard that is broken are a failure');
        $this->assertStringContainsString('smoke: FAILED', implode("\n", $r['lines']));
    }

    public function testARedirectTheAppDeclaresIntendedPassesSmoke(): void {
        // start.tiknix sends guests to tiknix.com on purpose and says so in X-Redirect-Reason; the
        // identity probe proved it is the app answering, so that is the app working.
        $this->published = ['v2.0.0-alpha.1', 'v2.0.0-alpha.2'];
        $reason = 'intended: guests are sent to tiknix.com';
        $u = new InstanceUpdate(function (string $dir, string $cmd): array {
            if (str_contains($cmd, ' show tiknix/runtime --all')) return [0, [json_encode(['versions' => $this->published])]];
            if (preg_match("/ update tiknix\\/runtime --with='tiknix\\/runtime:([^']+)'/", $cmd, $m)) { $this->lock('v' . $m[1], false, $dir); return [0, []]; }
            return [0, ['ok']];
        }, function (string $url) use (&$reason): array { if (str_contains($url, '/site/status?probe=')) return [204, null]; return [302, $reason]; });
        $r = $u->run($this->app);
        $this->assertSame('updated', $r['status'], implode("\n", $r['lines']));
        $this->assertStringContainsString("redirects by the app's own design", implode("\n", $r['lines']));

        // The same redirect WITHOUT the app's word for it is what it always was: not answering.
        $this->published[] = 'v2.0.0-alpha.3';
        $reason = null;
        $r = $u->run($this->app);
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('smoke: FAILED', implode("\n", $r['lines']));
    }

    public function testAComposerFailurePutsEverythingBackAndPinsNothing(): void {
        $u = $this->updater();
        $this->published = ['v2.0.0-alpha.1', 'v2.0.0-alpha.2'];
        $this->composerFails = true;
        $lockBefore = file_get_contents($this->app . '/composer.lock');
        $head = $this->git($this->app, 'rev-parse HEAD');

        $r = $u->run($this->app);
        $this->assertSame('failed', $r['status'], implode("\n", $r['lines']));
        $this->assertStringContainsString('could not move the runtime to v2.0.0-alpha.2', implode(' ', $r['lines']));
        $this->assertSame($lockBefore, file_get_contents($this->app . '/composer.lock'), 'the lock is back');
        $this->assertStringContainsString(' install --no-interaction', implode("\n", $this->ran), 'vendor/ reinstalled from it');
        $this->assertSame($head, $this->git($this->app, 'rev-parse HEAD'), 'nothing committed');
        $this->assertSame('', InstanceUpdate::pinned($this->app), 'nothing pinned');
        $this->assertStringNotContainsString('--build', implode("\n", $this->ran), 'no post step ran');
    }

    public function testCuttingAReleaseWhereTheRuntimeIsDeveloped(): void {
        $u = $this->updater();
        $this->assertStringContainsString('installs tiknix/runtime as a release', $u->tagRelease($this->app)['message']);

        // the checkout: a repository the app's vendor/tiknix/runtime points at
        $rt = $this->root . '/tiknix-runtime';
        mkdir($rt);
        $this->git($rt, 'init -q -b main'); $this->ident($rt);
        file_put_contents($rt . '/lib.txt', "v1\n"); $this->git($rt, 'add -A'); $this->git($rt, 'commit -q -m rt1');
        exec('rm -rf ' . escapeshellarg($this->app . '/vendor/tiknix/runtime'));
        symlink($rt, $this->app . '/vendor/tiknix/runtime');
        $this->lock('dev-main', true);

        $r = $u->tagRelease($this->app);
        $this->assertFalse($r['ok']); $this->assertStringContainsString('--release=vX.Y.Z', $r['message']);
        $r = $u->tagRelease($this->app, 'v2.0.0', 'first');
        $this->assertTrue($r['ok'], $r['message']); $this->assertSame('v2.0.0', $r['tag']);
        $this->assertContains('tests/run.sh 2>&1', $this->ran, 'the suite ran first');
        $this->assertSame('v2.0.0', $this->git($rt, 'tag --list ' . escapeshellarg('v*')), 'the tag is on the runtime checkout, not the app');
        file_put_contents($rt . '/lib.txt', "v2\n"); $this->git($rt, 'commit -qam more');
        $this->assertSame('v2.0.1', $u->tagRelease($this->app)['tag'], 'next patch by default');
        $this->assertFalse($u->tagRelease($this->app, 'v2.0.1')['ok'], 'exists');
        $this->assertFalse($u->tagRelease($this->app, 'v2.1-rc1')['ok'], 'not a release');
        $this->git($rt, 'checkout -q -b feature');
        $this->assertStringContainsString("on 'feature'", $u->tagRelease($this->app, 'v2.0.2')['message']);

        $this->assertSame(['v2.0.0-alpha.1', 'v2.0.0-alpha.2', 'v2.0.0-alpha.10', 'v2.0.0-rc.1', 'v2.0.0', 'v2.0.1'],
            InstanceUpdate::releaseTags("v2.0.1\nv2.0.0-alpha.10\nv2.0.0\nv2.0.0-alpha.1\nv2.0.0-rc.1\nv2.0.0-alpha.2\nv1.6\ndev-main\ncheckpoint-x\n"));
    }
}
