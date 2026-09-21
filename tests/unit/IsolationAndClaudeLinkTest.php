<?php
/**
 * Two things that failed together on lead-machine and looked like one flaky bug:
 *
 *  - IsolatedPool::inside() — "am I the confined pool?" used to be a test for open_basedir,
 *    which a CLI process started by the pool does not have.
 *  - ClaudeBinary — <root>/bin/claude was a symlink to the operator's home, which dangles
 *    inside the builder sandbox.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ClaudeBinary;
use app\IsolatedPool;

class IsolationAndClaudeLinkTest extends ConceptsTestCase {

    private function instance(bool $isolated): string {
        $root = $this->root . '/inst' . ($isolated ? 'iso' : 'plain');
        mkdir($root, 0700, true);
        if ($isolated) touch($root . '/' . IsolatedPool::MARKER);
        return $root;
    }

    /* ---- IsolatedPool ---- */

    public function testTheTreeOwnerIsNotThePool(): void {
        $root = $this->instance(true);
        // The operator / builder: same uid as the tree's owner. It may jail what it launches.
        $this->assertFalse(IsolatedPool::inside($root, fileowner($root)));
    }

    public function testAnyoneElseInAnIsolatedTreeIsThePool(): void {
        $root = $this->instance(true);
        // tiknix-i<id>: a CLI worker started by the pool. No open_basedir, and still the pool.
        $this->assertSame('', (string) ini_get('open_basedir'), 'this test must run without open_basedir to mean anything');
        $this->assertTrue(IsolatedPool::inside($root, fileowner($root) + 30086));
    }

    public function testAnInstanceWithoutTheMarkerIsNeverThePool(): void {
        $root = $this->instance(false);
        $this->assertFalse(IsolatedPool::inside($root, fileowner($root) + 30086));
    }

    public function testNoRootIsNotThePool(): void {
        $this->assertFalse(IsolatedPool::inside('', 12345));
    }

    /* ---- ClaudeBinary ---- */

    private function fakeHostBinary(string $version = 'v1'): string {
        $f = $this->root . "/host-claude-{$version}";
        file_put_contents($f, "#!/bin/sh\necho {$version}\n");
        chmod($f, 0755);
        return $f;
    }

    public function testLinkInstallsAHardLinkInsideTheInstance(): void {
        $root = $this->instance(true);
        $host = $this->fakeHostBinary();
        $r = ClaudeBinary::link($root, $host);

        $this->assertSame('hardlinked', $r['action']);
        $this->assertFalse(is_link("{$root}/bin/claude"), 'a real directory entry, not a pointer out of the tree');
        $this->assertSame(fileinode($host), fileinode("{$root}/bin/claude"));
        $this->assertSame('hardlink', ClaudeBinary::status($root)['state']);
    }

    public function testTheLinkSurvivesTheHostVersionBeingDeleted(): void {
        // What claude's self-update does to the file a second-hand symlink was pointing at.
        $root = $this->instance(true);
        $host = $this->fakeHostBinary();
        ClaudeBinary::link($root, $host);
        unlink($host);

        $this->assertFileExists("{$root}/bin/claude");
        $this->assertSame("v1\n", shell_exec(escapeshellarg("{$root}/bin/claude")));
    }

    public function testRelinkIsIdempotentAndMovesToANewVersion(): void {
        $root = $this->instance(true);
        $v1 = $this->fakeHostBinary('v1');
        ClaudeBinary::link($root, $v1);
        $this->assertSame('unchanged', ClaudeBinary::link($root, $v1)['action']);

        $v2 = $this->fakeHostBinary('v2');
        $this->assertSame('hardlinked', ClaudeBinary::link($root, $v2)['action']);
        $this->assertSame(fileinode($v2), fileinode("{$root}/bin/claude"));
        $this->assertSame([], glob("{$root}/bin/claude.new-*") ?: [], 'no temporary link left behind');
    }

    public function testLinkReplacesTheOldSymlink(): void {
        $root = $this->instance(true);
        mkdir("{$root}/bin");
        symlink('/home/nobody/.local/bin/claude', "{$root}/bin/claude");
        $this->assertSame('dangling', ClaudeBinary::status($root)['state']);

        ClaudeBinary::link($root, $this->fakeHostBinary());
        $this->assertSame('hardlink', ClaudeBinary::status($root)['state']);
    }

    /* ---- git must never pick the binary up ---- */

    private function gitInstance(): string {
        $root = $this->instance(true);
        $q = escapeshellarg($root);
        exec("git -C {$q} init -q -b main && git -C {$q} -c user.email=t@t -c user.name=t commit -q --allow-empty -m init");
        return $root;
    }

    public function testLinkingIgnoresTheBinaryBeforeItExists(): void {
        $root = $this->gitInstance();
        ClaudeBinary::link($root, $this->fakeHostBinary());

        exec('git -C ' . escapeshellarg($root) . ' status --porcelain --untracked-files=all', $status);
        $this->assertSame([], array_values(array_filter($status, fn(string $l) => str_contains($l, 'bin'))),
            'a ~230 MB hard link must not show up for `git add -A` to take');
        $this->assertStringContainsString('/bin/claude', file_get_contents("{$root}/.git/info/exclude"));

        ClaudeBinary::link($root, $this->fakeHostBinary('v2'));
        $this->assertSame(1, substr_count(file_get_contents("{$root}/.git/info/exclude"), "\n/bin/claude\n"), 'the rule is added once');
    }

    public function testATrackedBinaryIsRefusedWithTheFix(): void {
        $root = $this->gitInstance();
        mkdir("{$root}/bin");
        symlink('/home/nobody/.local/bin/claude', "{$root}/bin/claude");
        $q = escapeshellarg($root);
        exec("git -C {$q} add bin/claude && git -C {$q} -c user.email=t@t -c user.name=t commit -q -m 'oops, tracked it'");

        try {
            ClaudeBinary::link($root, $this->fakeHostBinary());
            $this->fail('link() should have refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('TRACKED by git', $e->getMessage());
            $this->assertStringContainsString('rm --cached bin/claude', $e->getMessage());
        }
        $this->assertTrue(is_link("{$root}/bin/claude"), 'nothing was changed');
    }

    public function testNoHostBinaryIsAnErrorThatNamesWhereItLooked(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no claude binary found');
        ClaudeBinary::link($this->instance(true), $this->root . '/does-not-exist');
    }

    public function testStatusOfAnInstanceWithNone(): void {
        $this->assertSame('missing', ClaudeBinary::status($this->instance(true))['state']);
    }
}
