<?php
/**
 * app\GitSync: a repository is read as owner/name whatever form it is pasted in, and a file that
 * must never leave or enter an app through git (a database, a real config, a key) is caught —
 * an example config is not. (The two-way sync itself was proven against a stand-in repository on
 * warehouse-builder, 2026-10-20: whole history out, fast-forward in, a conflict refused with the
 * app untouched, settled on the other side and brought in, both sides moved and merged.)
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\GitSync;

class GitSyncTest extends TestCase {
    public function testARepositoryIsReadAsOwnerAndName(): void {
        foreach (['mfrederico/inresonance', 'https://github.com/mfrederico/inresonance', 'https://github.com/mfrederico/inresonance.git', 'git@github.com:mfrederico/inresonance.git', 'mfrederico/inresonance/'] as $in) {
            $this->assertSame(['mfrederico/inresonance', 'main'], GitSync::repo(['repo' => $in]), $in);
        }
        $this->assertSame(['a/b', 'trunk'], GitSync::repo(['repo' => 'a/b', 'branch' => 'trunk']));
        foreach (['inresonance', 'a/b/c', 'a b/c', ''] as $bad) {
            try { GitSync::repo(['repo' => $bad]); $this->fail("'{$bad}' was taken as a repository"); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('owner/name', $e->getMessage()); }
        }
        $this->expectException(\InvalidArgumentException::class);
        GitSync::repo(['repo' => 'a/b', 'branch' => '../main']);
    }

    public function testFilesThatMustNeverMoveAreCaught(): void {
        foreach (['conf/config.ini', 'conf/broker.ini', 'secure/connections.key', 'database/tiknix.db', 'data/workbench.db', '.env', 'conf/quickbooks.ini', 'certs/site.pem', 'id.key'] as $f) {
            $this->assertMatchesRegularExpression(GitSync::NEVER, $f, $f);
        }
        foreach (['conf/config.example.ini', 'conf/aibuilder.example.ini', 'database/.gitkeep', 'data/.gitkeep', 'controls/Cafe.php', 'docs/keys.md', 'views/secure/index.php'] as $f) {
            $this->assertDoesNotMatchRegularExpression(GitSync::NEVER, $f, $f);
        }
    }
}
