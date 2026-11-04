<?php
/**
 * Passkeys (runtime lib/Passkeys.php) without a browser: a passkey answer is refused without its own challenge, after it
 * runs out, for another step or another account, from another address, and for a passkey this site does not know; a
 * member removes only their own. The full ceremony (a real device) is proven in a browser with a virtual authenticator.
 */

namespace tests\unit;

use app\Bean;
use app\Passkeys;
use PHPUnit\Framework\TestCase;

class PasskeysTest extends TestCase {

    private const DB = 'passkeys-test';
    private ?string $prev = null;
    private $baseurl;

    protected function setUp(): void {
        $this->prev = Bean::currentDatabaseKey();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        $this->baseurl = \Flight::get('app.baseurl');
        \Flight::set('app.baseurl', 'https://shop.example.com');
        \Flight::map('siteName', fn() => 'Shop');
        $this->log = \Flight::get('log');
        \Flight::set('log', new \Monolog\Logger('test', [new \Monolog\Handler\NullHandler()]));
        $_SESSION = [];
    }

    private $log;

    protected function tearDown(): void {
        \Flight::set('app.baseurl', $this->baseurl);
        \Flight::set('log', $this->log);
        $_SESSION = [];
        if ($this->prev !== null && Bean::hasDatabase($this->prev)) Bean::selectDatabase($this->prev);
    }

    private function passkey(int $memberId, string $credId): object {
        $p = Bean::dispense('passkey');
        $p->memberId = $memberId; $p->credentialEid = Passkeys::b64e($credId); $p->publicKey = 'x'; $p->signCount = 0;
        $p->userHandle = Passkeys::b64e('handle' . $memberId); $p->name = 'Key'; $p->aaguid = ''; $p->createdAt = date('Y-m-d H:i:s'); $p->lastUsedAt = '';
        Bean::store($p);
        return $p;
    }

    private function client(string $type, string $origin): string {
        return base64_encode(json_encode(['type' => $type, 'origin' => $origin, 'challenge' => 'x']));
    }

    public function testTheRelyingPartyIsThisSitesOwnHost(): void {
        $this->assertSame('shop.example.com', Passkeys::rpId());
        $this->assertSame('abc', Passkeys::b64d(Passkeys::b64e('abc')));
        $this->assertSame('', Passkeys::b64d('***'), 'not base64: nothing, never a guess');
    }

    public function testAnAnswerNeedsItsOwnFreshChallenge(): void {
        try { Passkeys::verify(['id' => Passkeys::b64e('c1')], 0); $this->fail('no challenge'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('No passkey request is waiting', $e->getMessage()); }

        Passkeys::signInOptions(0);
        $_SESSION['passkey_ceremony']['at'] = time() - Passkeys::CHALLENGE_TTL - 1;
        try { Passkeys::verify(['id' => Passkeys::b64e('c1')], 0); $this->fail('stale'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('ran out of time', $e->getMessage()); }

        Passkeys::signInOptions(0);
        try { Passkeys::verify(['id' => Passkeys::b64e('c1')], 7); $this->fail('a sign-in challenge used for a second step'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('No passkey request is waiting', $e->getMessage()); }
        $this->assertArrayNotHasKey('passkey_ceremony', $_SESSION, 'a challenge is used once, even when refused');
    }

    public function testUnknownForeignOrWrongAddressPasskeysAreRefused(): void {
        $this->passkey(7, 'mine');
        $this->passkey(8, 'theirs');
        Passkeys::signInOptions(0);
        try { Passkeys::verify(['id' => Passkeys::b64e('nobody')], 0); $this->fail('unknown'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('Unknown passkey', $e->getMessage()); }

        Passkeys::signInOptions(7);
        try { Passkeys::verify(['id' => Passkeys::b64e('theirs')], 7); $this->fail('another account at the second step'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('belongs to another account', $e->getMessage()); }

        Passkeys::signInOptions(7);
        try { Passkeys::verify(['id' => Passkeys::b64e('mine'), 'clientDataJSON' => $this->client('webauthn.get', 'https://evilshop.example.com')], 7); $this->fail('look-alike host'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('another address', $e->getMessage()); }

        Passkeys::signInOptions(7);
        try { Passkeys::verify(['id' => Passkeys::b64e('mine'), 'clientDataJSON' => $this->client('webauthn.create', 'https://shop.example.com')], 7); $this->fail('wrong step'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('another step', $e->getMessage()); }
    }

    public function testASecondStepNeedsThatAccountsOwnPasskey(): void {
        $this->expectExceptionMessage('This account has no passkey.');
        Passkeys::signInOptions(9);
    }

    public function testAMemberRemovesOnlyTheirOwn(): void {
        $p = $this->passkey(7, 'mine');
        $this->assertFalse(Passkeys::remove(8, (int) $p->id));
        $this->assertTrue(Passkeys::has(7));
        $this->assertTrue(Passkeys::remove(7, (int) $p->id));
        $this->assertFalse(Passkeys::has(7));
    }
}
