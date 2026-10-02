<?php
/**
 * A platform agent job is refused before anything is spent when it does not say who runs it,
 * what the answer looks like, or how much it may cost.
 */

namespace tests\unit;

use app\PlatformAgent;
use PHPUnit\Framework\TestCase;

class PlatformAgentTest extends TestCase {

    public function testAnUnknownAgentIsRefusedByName(): void {
        $r = PlatformAgent::run(['agent' => 'no-such-agent-here', 'prompt' => 'x', 'schema' => ['type' => 'object'], 'max_usd' => 1]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString("no AI agent named 'no-such-agent-here'", $r['error']);
        $this->assertSame(0.0, $r['cost_usd']);
    }

    public function testTheCommandGivesTheAgentNoBuiltInTools(): void {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/PlatformAgent.php');
        foreach (['--bare', '--tools ""', '--strict-mcp-config', '--no-session-persistence', '--max-budget-usd'] as $flag) {
            $this->assertStringContainsString($flag, $src, "the platform agent's command must carry {$flag}");
        }
        $this->assertStringNotContainsString('bypassPermissions', $src, 'a platform agent never runs with permissions bypassed');
    }
}
