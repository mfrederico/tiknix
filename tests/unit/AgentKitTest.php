<?php
/**
 * AgentKit — the AI agents page's MCP servers and skills, in the app's own tree: what is written,
 * who authored the commit, and what a name must look like before it reaches a command line.
 * (The first marketplace-source pattern used its own delimiter inside itself and refused every
 * marketplace — failing closed, but failing.)
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\AgentKit;
use app\AgentMcp;

class AgentKitTest extends TestCase {
    private string $root;
    private object $member;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-agentkit-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->git('init -q');
        $this->member = (object) ['id' => 7, 'username' => 'dana', 'email' => 'dana@example.test'];
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->root)); }

    private function git(string $args): string {
        exec('env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C ' . escapeshellarg($this->root) . ' ' . $args . ' 2>&1', $o);
        return trim(implode("\n", $o));
    }

    public function testMarketplaceSources(): void {
        foreach (['anthropics/claude-plugins-official', 'my-org/plugins.repo', 'https://example.com/market.json', 'https://github.com/a/b.git', 'https://example.com'] as $ok) {
            $this->assertSame(1, preg_match(AgentKit::SOURCE_RE, $ok), $ok);
        }
        foreach (['https://x.example/$(id)', 'a/b; rm -rf /', 'http://plain.example/x', '../etc', 'a/b/c', "a/b\n", 'git@github.com:a/b.git', ''] as $bad) {
            $this->assertSame(0, preg_match(AgentKit::SOURCE_RE, $bad), var_export($bad, true));
        }
    }

    public function testPluginIdsAndSkillNames(): void {
        $this->assertSame(1, preg_match(AgentKit::PLUGIN_RE, 'frontend-design@anthropic-plugin-directory'));
        foreach (['frontend-design', 'a@b; ls', 'a@b@c', "a@b\n", '@b'] as $bad) $this->assertSame(0, preg_match(AgentKit::PLUGIN_RE, $bad), var_export($bad, true));
        $this->assertSame(1, preg_match(AgentKit::NAME_RE, 'release-notes'));
        foreach (['Release', '../x', 'a', 'a b', "ok\n", '-x'] as $bad) $this->assertSame(0, preg_match(AgentKit::NAME_RE, $bad), var_export($bad, true));
    }

    public function testAServerIsAddedChangedAndRemovedAndAgentsGetIt(): void {
        $kit = new AgentKit($this->root);
        $kit->saveServer('maps', ['type' => 'http', 'url' => 'https://maps.example/mcp', 'headers' => ['Authorization' => 'Bearer k'], 'description' => 'Maps'], false, $this->member);
        $this->assertSame(['maps'], array_keys($kit->servers()));
        $this->assertSame(['tiknix', 'maps'], array_keys(AgentMcp::config($this->root, $this->root)['mcpServers']));
        $this->assertStringContainsString('dana <dana@example.test>', $this->git('log -1 --format="%an <%ae> %s"'), 'committed as the member');

        // a change that leaves the headers blank keeps the stored ones
        $kit->saveServer('maps', ['type' => 'http', 'url' => 'https://maps.example/v2', 'headers' => null], true, $this->member);
        $this->assertSame(['Authorization' => 'Bearer k'], $kit->servers()['maps']['headers']);
        $this->assertSame('https://maps.example/v2', $kit->servers()['maps']['url']);

        $kit->removeServer('maps', $this->member);
        $this->assertSame([], $kit->servers());
        $this->assertSame("{\n    \"mcpServers\": {}\n}\n", file_get_contents($this->root . '/.mcp.json'), 'an object, not a list');
    }

    public function testAnIgnoredMcpFileIsWrittenAndLeftUncommitted(): void {
        file_put_contents($this->root . '/.gitignore', ".mcp.json\n");
        (new AgentKit($this->root))->saveServer('local', ['type' => 'stdio', 'command' => 'node', 'args' => ['server.js']], false, $this->member);
        $this->assertFileExists($this->root . '/.mcp.json');
        $this->assertStringNotContainsString('.mcp.json', $this->git('ls-files'));
    }

    public function testRefusals(): void {
        $kit = new AgentKit($this->root);
        foreach ([
            ['tiknix',  ['type' => 'http', 'url' => 'https://x.example'], false],   // the app's own
            ['Bad Name', ['type' => 'http', 'url' => 'https://x.example'], false],
            ['ok',      ['type' => 'http', 'url' => 'x.example'], false],
            ['ok',      ['type' => 'stdio', 'command' => ''], false],
            ['ok',      ['type' => 'ftp'], false],
            ['absent',  ['type' => 'http', 'url' => 'https://x.example'], true],     // a change to nothing
        ] as [$name, $entry, $mustExist]) {
            try { $kit->saveServer($name, $entry, $mustExist, $this->member); $this->fail("accepted {$name} " . json_encode($entry)); }
            catch (\InvalidArgumentException | \RuntimeException $e) { $this->addToAssertionCount(1); }
        }
        $this->assertFileDoesNotExist($this->root . '/.mcp.json');
    }

    public function testABrokenMcpFileIsAFaultNotAnEmptyList(): void {
        file_put_contents($this->root . '/.mcp.json', '{not json');
        $this->expectException(\RuntimeException::class);
        (new AgentKit($this->root))->servers();
    }

    public function testTheOwnServerLeavesOnlyOnTheTypedPhraseAndComesBack(): void {
        $kit = new AgentKit($this->root);
        try { $kit->setOwn(false, $this->member, 'yes'); $this->fail('removed without the phrase'); } catch (\InvalidArgumentException $e) {}
        $this->assertFalse($kit->ownOff());
        $kit->setOwn(false, $this->member, AgentKit::REMOVE_CONFIRM);
        $this->assertTrue($kit->ownOff());
        $this->assertSame([], AgentMcp::config($this->root, $this->root)['mcpServers']);
        $kit->setOwn(true, $this->member);
        $this->assertFalse($kit->ownOff());
    }

    public function testASkillIsSavedReadBackAndRemoved(): void {
        $kit = new AgentKit($this->root);
        $kit->saveSkill('release-notes', "Use when writing\nrelease notes: plainly.", "1. Read the log.\r\n2. Write.", $this->member);
        $txt = file_get_contents($this->root . '/.claude/skills/release-notes/SKILL.md');
        $this->assertStringStartsWith("---\nname: release-notes\ndescription: \"Use when writing release notes: plainly.\"\n---\n\n1. Read the log.\n2. Write.\n", $txt);
        $this->assertStringContainsString('Skill release-notes saved', $this->git('log -1 --format=%s'));
        $s = $kit->skills();
        $this->assertSame(['release-notes', 'Use when writing release notes: plainly.', false], [$s[0]['name'], $s[0]['description'], $s[0]['managed']]);
        $one = $kit->skill('release-notes');
        $this->assertSame("1. Read the log.\n2. Write.", trim($one['body']));
        $kit->removeSkill('release-notes', $this->member);
        $this->assertSame([], $kit->skills());
        $this->assertDirectoryDoesNotExist($this->root . '/.claude/skills/release-notes');
        $this->assertSame('', $this->git('status --porcelain'), 'the removal is committed too');
    }

    public function testASkillNeedsANameADescriptionAndABodyAndAnAuthor(): void {
        $kit = new AgentKit($this->root);
        foreach ([['../x', 'd', 'b'], ['ok-name', '', 'b'], ['ok-name', 'd', '  ']] as [$n, $d, $b]) {
            try { $kit->saveSkill($n, $d, $b, $this->member); $this->fail("accepted {$n}"); } catch (\InvalidArgumentException $e) { $this->addToAssertionCount(1); }
        }
        $this->expectException(\RuntimeException::class);   // staged, but nobody to author it: said, not committed as someone else
        $kit->saveSkill('ok-name', 'd', 'b', (object) ['id' => 9, 'username' => '', 'email' => '']);
    }
}
