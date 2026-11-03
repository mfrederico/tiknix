<?php
/**
 * Passwordless sign-in (lib/SignInLink.php): a link works once, for 15 minutes, only a hash is kept, asking is limited in the
 * database, an unknown address gets nothing (and is not told so), and a passwordless account is made only for a free address.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\SignInLink;

class SignInLinkTest extends ConceptsTestCase {

    private const DB = 'signin-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        if (!defined('LEVELS')) define('LEVELS', ['ROOT' => 1, 'ADMIN' => 50, 'MEMBER' => 100, 'PUBLIC' => 101]);
        $this->log = \Flight::get('log');
        \Flight::set('log', new \Monolog\Logger('test', [new \Monolog\Handler\NullHandler()]));
        \Flight::map('siteName', fn() => 'Tiknix');
    }

    private $log;

    protected function tearDown(): void { \Flight::set('log', $this->log); Bean::selectDatabase('default'); parent::tearDown(); }

    private function member(string $email, string $status = 'active'): int {
        $m = Bean::dispense('member'); $m->email = $email; $m->username = strstr($email, '@', true); $m->password = 'x'; $m->level = 100; $m->status = $status;
        return (int) Bean::store($m);
    }

    /** A link as request() makes one, with a token we know (request() mails it; a test cannot read the mail). */
    private function link(int $memberId, string $token, int $ageSeconds = 0, string $to = ''): void {
        $r = Bean::dispense('signinlink');
        $r->emailHash = hash('sha256', 'x'); $r->ipHash = hash('sha256', '1.2.3.4'); $r->memberRef = $memberId; $r->tokenHash = hash('sha256', $token);
        $r->landing = $to; $r->createdAt = date('Y-m-d H:i:s', time() - $ageSeconds); $r->expiresAt = date('Y-m-d H:i:s', time() - $ageSeconds + SignInLink::TTL); $r->usedAt = ''; $r->joinEmail = '';
        Bean::store($r);
    }

    public function testALinkSignsInOnceAndOnlyItsHashIsKept(): void {
        $id = $this->member('ann@example.com');
        $token = str_repeat('a', 64);
        $this->link($id, $token, 0, '/handoff/claim/abc');
        $this->assertNotNull(SignInLink::peek($token), 'opening it (or a mail scanner opening it) changes nothing');
        $this->assertNotNull(SignInLink::peek($token));
        $r = SignInLink::consume($token);
        $this->assertSame($id, (int) $r['member']->id);
        $this->assertSame('/handoff/claim/abc', $r['to']);
        $this->assertNull(SignInLink::consume($token), 'the second press finds it used');
        $this->assertNull(SignInLink::peek($token));
        $this->assertSame(0, (int) Bean::count('signinlink', 'token_hash = ?', [$token]), 'the token itself is never stored');
    }

    public function testAnOldLinkOrAClosedAccountDoesNotSignIn(): void {
        $this->link($this->member('old@example.com'), str_repeat('b', 64), SignInLink::TTL + 5);
        $this->assertNull(SignInLink::peek(str_repeat('b', 64)));
        $this->assertNull(SignInLink::consume(str_repeat('b', 64)));
        $this->link($this->member('gone@example.com', 'closed'), str_repeat('c', 64));
        $this->assertNull(SignInLink::consume(str_repeat('c', 64)));
        $this->assertNull(SignInLink::consume('not-a-token'));
    }

    public function testAnUnknownAddressGetsNoLinkButIsCounted(): void {
        SignInLink::request('nobody@example.com', '9.9.9.9');
        $this->assertSame(1, (int) Bean::count('signinlink'));
        $this->assertSame(0, (int) Bean::count('signinlink', "token_hash <> ''"), 'nothing to send, nothing minted');
    }

    public function testOneNetworkAskingTooOftenIsStopped(): void {
        for ($i = 0; $i < SignInLink::PER_NETWORK; $i++) SignInLink::request("n{$i}@example.com", '7.7.7.7');
        try { SignInLink::request('n99@example.com', '7.7.7.7'); $this->fail('an eleventh request from one network went through'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('Try again in an hour', $e->getMessage()); }
        SignInLink::request('n99@example.com', '8.8.8.8');   // another network is not held back
        $this->addToAssertionCount(1);
    }

    public function testAPasswordlessAccountIsMadeOnlyForAFreeAddress(): void {
        $m = SignInLink::createAccount('  New.Person@Example.com ');
        $this->assertSame('new.person@example.com', (string) $m->email);
        $this->assertSame('', (string) $m->password, 'no password: it signs in with emailed links');
        $this->assertSame('active', (string) $m->status);
        $this->assertNotSame('', (string) $m->username);
        try { SignInLink::createAccount('new.person@example.com'); $this->fail('a second account for one address'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already has an account', $e->getMessage()); }
    }

    public function testACreateYourAccountLinkMakesTheAccountOnlyWhenPressed(): void {
        $r = Bean::dispense('signinlink');
        $r->emailHash = hash('sha256', 'x'); $r->ipHash = hash('sha256', 'ip'); $r->memberRef = 0; $r->tokenHash = hash('sha256', str_repeat('d', 64));
        $r->landing = '/yourai?next=%2Fhandoff%2Fclaim%2Fabc'; $r->createdAt = date('Y-m-d H:i:s'); $r->expiresAt = date('Y-m-d H:i:s', time() + 900); $r->usedAt = ''; $r->joinEmail = 'fresh@example.com';
        Bean::store($r);
        $this->assertNotNull(SignInLink::peek(str_repeat('d', 64)), 'a join link is a link');
        $this->assertSame(0, (int) Bean::count('member', 'email = ?', ['fresh@example.com']), 'opening it makes nothing (a mail scanner may be opening it)');
        $used = SignInLink::consume(str_repeat('d', 64));
        $this->assertTrue($used['joined']);
        $this->assertSame('fresh@example.com', (string) $used['member']->email);
        $this->assertSame('', (string) $used['member']->password);
        $this->assertSame('/yourai?next=%2Fhandoff%2Fclaim%2Fabc', $used['to']);
        $this->assertSame('', (string) Bean::findOne('signinlink', 'token_hash = ?', [hash('sha256', str_repeat('d', 64))])->joinEmail, 'the address does not stay on the used row');
        $this->assertNull(SignInLink::consume(str_repeat('d', 64)), 'once');
    }

    public function testACreateYourAccountRequestForAnAddressWithAnAccountIsASignInLink(): void {
        $this->member('has@example.com');
        try { SignInLink::requestJoin('has@example.com', '5.5.5.5'); } catch (\Throwable $e) { /* sending the sign-in mail, which a test cannot */ }
        $this->assertSame(0, (int) Bean::count('signinlink', "join_email <> ''"), 'no account is made for an address that has one');
    }

    public function testOnlyAPathOfThisSiteIsALanding(): void {
        $this->assertSame('/handoff/claim/abc', SignInLink::safePath('/handoff/claim/abc'));
        foreach (['https://evil.example/', '//evil.example', 'javascript:alert(1)', '/a b'] as $bad) $this->assertSame('', SignInLink::safePath($bad), $bad);
    }
}
