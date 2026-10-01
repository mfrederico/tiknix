<?php
/**
 * The builder terminal is per PERSON and agent: the token names both, and the session name the
 * control plane ends (Aibuilder::restart) is the one the app's bridge creates.
 */

namespace tests\unit;

use app\AppToken;
use PHPUnit\Framework\TestCase;

class AppTokenTerminalTest extends TestCase {

    public function testEachMemberHasTheirOwnSessionPerAgent(): void {
        $this->assertSame('aib-default-m1', AppToken::terminalSession(1, ''));
        $this->assertSame('aib-zai-m1', AppToken::terminalSession(1, 'zai'));
        $this->assertNotSame(AppToken::terminalSession(1, ''), AppToken::terminalSession(12, ''), 'a coworker on the same agent has their own');
    }

    public function testATokenWithoutAMemberIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        AppToken::terminal((object) ['slug' => 'x', 'terminalKey' => str_repeat('k', 64)], 0, false, '');
    }

    public function testAnAgentNameThatIsNotOneIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not an agent name');
        AppToken::terminal((object) ['slug' => 'x', 'terminalKey' => str_repeat('k', 64)], 1, false, 'a b; rm');
    }
}
