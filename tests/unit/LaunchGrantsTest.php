<?php
/**
 * The sign-in hand-off carries Tiknix's grants for the person (AppToken::launch → the runtime's
 * LaunchToken): only the flags Tiknix is the authority for survive, and a token that says
 * nothing about grants is not read as "none".
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\AppToken;
use app\LaunchToken;

class LaunchGrantsTest extends TestCase {
    private function claims(string $token): array {
        return json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
    }

    public function testTheTokenNamesTheGrants(): void {
        $inst = (object) ['slug' => 'demo-abc123', 'terminalKey' => str_repeat('k', 64)];
        $this->assertSame(['mcp'], $this->claims(AppToken::launch($inst, 'A@x.test', '/agents?tab=mcp', 100, ['mcp']))['grants']);
        $this->assertSame([], $this->claims(AppToken::launch($inst, 'a@x.test', '/dashboard', 100))['grants'], 'no grant is said as none, so the app takes one back');
    }

    public function testOnlyTiknixOwnFlagsAreGrantable(): void {
        $this->assertSame(['mcp'], LaunchToken::GRANTS, 'adding a flag here lets a Tiknix sign-in switch it on in every app — decide it, then change this test');
    }
}
