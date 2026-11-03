<?php
/**
 * QaAccounts (runtime lib/QaAccounts.php): an app says once how an after-build check account gets what its own pages need;
 * only check accounts are ever handed to it, and a failing setup throws.
 */

namespace tests\unit;

use app\QaAccounts;
use PHPUnit\Framework\TestCase;

class QaAccountsTest extends TestCase {

    protected function tearDown(): void { QaAccounts::reset(); }

    private function member(string $email, int $level = 50): object { return (object) ['id' => 7, 'email' => $email, 'level' => $level]; }

    public function testOnlyTheChecksOwnAddressesAreTestAccounts(): void {
        $this->assertTrue(QaAccounts::isTestAccount('qa-admin-12-0a1b2c3d@audit.example.com'));
        foreach (['ann@example.com', 'qa-admin-12-0a1b2c3d@audit.example.com.evil', 'qa-boss-12-0a1b2c3d@audit.example.com', 'qa-admin-x-0a1b2c3d@audit.example.com'] as $no)
            $this->assertFalse(QaAccounts::isTestAccount($no), $no);
    }

    public function testNothingRegisteredIsNothingToDo(): void {
        $this->assertFalse(QaAccounts::registered());
        $this->assertSame('', QaAccounts::run($this->member('qa-admin-1-0000abcd@audit.example.com')));
    }

    public function testTheAppsSetupRunsForATestAccountAndSaysWhatItDid(): void {
        $seen = [];
        QaAccounts::prepare(function (object $m, int $level) use (&$seen) { $seen[] = $level; return 'owner of organization "QA 7"'; },
                            function (object $m) use (&$seen) { $seen[] = 'removed'; });
        $this->assertSame('owner of organization "QA 7"', QaAccounts::run($this->member('qa-admin-1-0000abcd@audit.example.com')));
        QaAccounts::remove($this->member('qa-admin-1-0000abcd@audit.example.com'));
        QaAccounts::remove($this->member('real@example.com'));   // a real member is never handed over
        $this->assertSame([50, 'removed'], $seen);
    }

    public function testARealMemberIsRefusedAndAFailingSetupThrows(): void {
        QaAccounts::prepare(fn() => throw new \RuntimeException('no organization table'));
        try { QaAccounts::run($this->member('real@example.com')); $this->fail('a real member was set up'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not an after-build check account', $e->getMessage()); }
        $this->expectExceptionMessage('no organization table');
        QaAccounts::run($this->member('qa-member-1-0000abcd@audit.example.com', 100));
    }
}
