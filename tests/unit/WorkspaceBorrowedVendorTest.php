<?php
/**
 * A task workspace for a project that ships no packages of its own (no composer.json; its
 * vendor/ is borrowed, as a sidecar borrows core's) — found dogfooding start.tiknix:
 *
 *   vendor   the worktree links the PROJECT's vendor path (the one the jail binds), and no
 *            composer dump-autoload runs (there is no composer.json to run it against)
 *   config   the worktree, which git gave no conf/config.ini, gets the LIVE project's,
 *            rewritten to the workspace's own address — never the example file
 *
 * Throwaway directories under the system temp dir.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\WorkspaceManager;

class WorkspaceBorrowedVendorTest extends ConceptsTestCase {

    private string $project;
    private string $coreVendor;
    private string $ws;

    protected function setUp(): void {
        parent::setUp();
        $this->coreVendor = $this->root . '/core/vendor';
        $this->project = $this->root . '/side.tiknix';
        $this->ws = $this->project . '/.aibuilder/wt/solo-1';
        mkdir($this->coreVendor . '/somepkg', 0700, true);
        file_put_contents($this->coreVendor . '/autoload.php', "<?php\n");
        mkdir($this->project . '/conf', 0700, true);
        symlink($this->coreVendor, $this->project . '/vendor');
        file_put_contents($this->project . '/conf/config.ini', "[app]\nname = \"Side\"\nbaseurl = \"https://side.example.com\"\n\n[database]\ntype = sqlite\npath = data/side.db\n");
        mkdir($this->ws . '/conf', 0700, true);
        file_put_contents($this->ws . '/conf/config.example.ini', "[app]\nbaseurl = \"REPLACE\"\n");
    }

    public function testVendorIsLinkedToTheProjectsPathNotRebuilt(): void {
        $wm = new WorkspaceManager(null, $this->project);
        $wm->setupVendor($this->ws);
        $this->assertTrue(is_link($this->ws . '/vendor'));
        $this->assertSame($this->project . '/vendor', readlink($this->ws . '/vendor'), 'the project path the jail binds, not the resolved core path');
        $this->assertFileExists($this->ws . '/vendor/somepkg');
        $this->assertFileDoesNotExist($this->ws . '/vendor/composer/installed.json', 'no autoload map was generated');
        $wm->setupVendor($this->ws);   // idempotent on a rerun
        $this->assertTrue(is_link($this->ws . '/vendor'));
    }

    public function testConfigComesFromTheLiveProjectRewrittenForTheWorkspace(): void {
        $wm = new WorkspaceManager(null, $this->project);
        $wm->updateConfig($this->ws, 'https://preview-side-abc.example.com', 'example.com');
        $cfg = parse_ini_file($this->ws . '/conf/config.ini', true);
        $this->assertSame('Side', $cfg['app']['name'], "the live project's config, not the example");
        $this->assertSame('https://preview-side-abc.example.com', $cfg['app']['baseurl']);
    }
}
