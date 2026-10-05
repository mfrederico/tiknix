<?php
/**
 * AgentMcp::config — what every agent of an app is given: its own server unless the project
 * switched it off, plus every server in .mcp.json. A broken .mcp.json is a fault, not "none".
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\AgentMcp;

class AgentMcpTest extends TestCase {
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir() . '/tiknix-agentmcp-' . bin2hex(random_bytes(4)); mkdir($this->root . '/.aibuilder', 0700, true); }

    public function testOwnServerByDefaultStartedInTheRunsDirectory(): void {
        $c = AgentMcp::config($this->root, '/srv/app/.aibuilder/wt/t1');
        $this->assertSame(['tiknix'], array_keys($c['mcpServers']));
        $this->assertStringContainsString("cd '/srv/app/.aibuilder/wt/t1'", $c['mcpServers']['tiknix']['args'][1]);
    }

    public function testTheProjectsServersAreAddedAndItsOwnIsOursToDefine(): void {
        file_put_contents($this->root . '/.mcp.json', json_encode(['mcpServers' => [
            'maps' => ['type' => 'http', 'url' => 'https://maps.example/mcp', 'headers' => ['Authorization' => 'Bearer k'], 'description' => 'page note'],
            'tiknix' => ['type' => 'http', 'url' => 'https://elsewhere/mcp'],
        ]]));
        $c = AgentMcp::config($this->root, $this->root);
        $this->assertSame(['tiknix', 'maps'], array_keys($c['mcpServers']));
        $this->assertArrayHasKey('command', $c['mcpServers']['tiknix'], "an entry named tiknix in the file does not replace the app's own");
        $this->assertSame(['type' => 'http', 'url' => 'https://maps.example/mcp', 'headers' => ['Authorization' => 'Bearer k']], $c['mcpServers']['maps']);
    }

    public function testSwitchedOffLeavesOnlyTheProjectsServers(): void {
        touch($this->root . '/' . AgentMcp::OFF_FILE);
        $this->assertTrue(AgentMcp::tiknixOff($this->root));
        $this->assertSame([], AgentMcp::config($this->root, $this->root)['mcpServers']);
        AgentMcp::write($this->root . '/out.json', $this->root, $this->root);
        $this->assertSame('{"mcpServers":{}}', file_get_contents($this->root . '/out.json'), 'an object, not a list');
    }

    public function testABrokenFileIsAFaultNotNoServers(): void {
        file_put_contents($this->root . '/.mcp.json', '{not json');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        AgentMcp::config($this->root, $this->root);
    }

    /**
     * The app's own server must outlive a quiet client. Its stdin is a socket, PHP times a socket
     * read out after default_socket_timeout (60 s), and the read loop took that for the end of
     * input: the server left a minute into any pause (measured 2026-10-05 — exit at 60 s before,
     * alive at 70 s after). A slow test would prove it again; this guards the line that fixes it.
     */
    public function testTheStdioServerDoesNotTimeOutItsOwnStdin(): void {
        $src = (string) file_get_contents(\app\Paths::runtime() . '/mcptools/mcp-fastmcp.php');
        $run = strpos($src, '$mcp->run(');
        $this->assertNotFalse($run);
        $before = substr($src, 0, $run);
        $this->assertStringContainsString("ini_set('default_socket_timeout', '-1')", $before);
        $this->assertMatchesRegularExpression('/stream_set_timeout\(STDIN,\s*[^)]+\)/', $before, 'set on STDIN itself: the stream is already open when the ini changes');
    }
}
