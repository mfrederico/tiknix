<?php
/**
 * ProjectSkills' name checks: what reaches a shell in the project's container is a name that
 * matches, or nothing. (The first source pattern used its own delimiter inside itself and
 * refused every marketplace — failing closed, but failing.)
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\ProjectSkills;

class ProjectSkillsTest extends TestCase {
    public function testMarketplaceSources(): void {
        foreach (['anthropics/claude-plugins-official', 'my-org/plugins.repo', 'https://example.com/market.json', 'https://github.com/a/b.git', 'https://example.com'] as $ok) {
            $this->assertSame(1, preg_match(ProjectSkills::SOURCE_RE, $ok), $ok);
        }
        foreach (['https://x.example/$(id)', 'a/b; rm -rf /', 'http://plain.example/x', '../etc', 'a/b/c', "a/b\n", 'git@github.com:a/b.git', ''] as $bad) {
            $this->assertSame(0, preg_match(ProjectSkills::SOURCE_RE, $bad), var_export($bad, true));
        }
    }
    public function testPluginIdsAndSkillNames(): void {
        $this->assertSame(1, preg_match(ProjectSkills::PLUGIN_RE, 'frontend-design@anthropic-plugin-directory'));
        foreach (['frontend-design', 'a@b; ls', 'a@b@c', "a@b\n", '@b'] as $bad) $this->assertSame(0, preg_match(ProjectSkills::PLUGIN_RE, $bad), var_export($bad, true));
        $this->assertSame(1, preg_match(ProjectSkills::NAME_RE, 'release-notes'));
        foreach (['Release', '../x', 'a', 'a b', "ok\n", '-x'] as $bad) $this->assertSame(0, preg_match(ProjectSkills::NAME_RE, $bad), var_export($bad, true));
    }
}
