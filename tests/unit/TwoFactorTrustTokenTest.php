<?php
/**
 * 2FA "trusted device" tokens are signed with a key derived from [security] app_key —
 * and with nothing else. They used to be signed with a literal from the source whenever
 * app.secret was unset, which was every install: a token anyone could mint. Pinned here:
 * round-trip works with a key, a token minted under the old literal is rejected, and a
 * missing app_key refuses rather than signing with something.
 */

namespace tests\unit;

use app\TwoFactorAuth;
use PHPUnit\Framework\TestCase;

class TwoFactorTrustTokenTest extends TestCase {

    private const KEY = 'a3f1c9d2e4b6a8f0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4';

    protected function tearDown(): void {
        \Flight::set('security.app_key', null);
    }

    public function testATokenRoundTripsUnderTheInstallKey(): void {
        \Flight::set('security.app_key', self::KEY);
        $token = TwoFactorAuth::generateTrustToken(42);
        $this->assertSame(42, TwoFactorAuth::validateTrustToken($token));
    }

    public function testATokenSignedWithTheOldSourceLiteralIsRejected(): void {
        \Flight::set('security.app_key', self::KEY);
        $payload = '42:' . (time() + 3600);
        $forged = base64_encode($payload) . '.' . hash_hmac('sha256', $payload, 'tiknix-2fa-trust-default-key');
        $this->assertNull(TwoFactorAuth::validateTrustToken($forged), 'the literal that used to sign every token must sign nothing now');
    }

    public function testATokenFromAnotherInstallIsRejected(): void {
        \Flight::set('security.app_key', self::KEY);
        $token = TwoFactorAuth::generateTrustToken(7);
        \Flight::set('security.app_key', strrev(self::KEY));
        $this->assertNull(TwoFactorAuth::validateTrustToken($token));
    }

    public function testNoAppKeyRefusesToSign(): void {
        \Flight::set('security.app_key', '');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[security] app_key');
        TwoFactorAuth::generateTrustToken(1);
    }
}
