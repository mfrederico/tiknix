<?php
/**
 * Tiknix's own agents never build a project: with AgentTask::$platformOnly set (the control
 * plane sets it, lib/controlplane.php) every builder verb refuses before anything else.
 */

namespace tests\unit;

use app\AgentTask;
use PHPUnit\Framework\TestCase;

class PlatformAgentsTest extends TestCase {

    private ?string $was;

    protected function setUp(): void { $this->was = AgentTask::$platformOnly; AgentTask::$platformOnly = 'platform tooling only'; }
    protected function tearDown(): void { AgentTask::$platformOnly = $this->was; }

    public function testEveryBuilderVerbRefuses(): void {
        $root = sys_get_temp_dir() . '/no-such-app-' . bin2hex(random_bytes(4));
        foreach ([
            'task'  => AgentTask::start($root, 't1', 'build something'),
            'plan'  => AgentTask::plan($root, 'p1', 'plan something', 1),
            'audit' => AgentTask::audit($root, 'a1', 'audit something', 'http://127.0.0.1:1'),
        ] as $verb => $r) {
            $this->assertFalse($r['ok'], $verb);
            $this->assertSame('refused', $r['status'], $verb);
            $this->assertSame('platform tooling only', $r['error'], $verb);
        }
        $m = AgentTask::merge($root, 't1');
        $this->assertFalse($m['ok']);
        $this->assertSame('platform tooling only', $m['error']);
        $this->assertSame('platform tooling only', AgentTask::terminal($root, '', false, 1));
        $this->assertDirectoryDoesNotExist($root, 'nothing was created on the way to the refusal');
    }

    public function testTheControlPlaneSetsIt(): void {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/controlplane.php');
        $this->assertStringContainsString('\app\AgentTask::$platformOnly =', $src);
    }
}
