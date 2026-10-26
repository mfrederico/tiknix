<?php
/**
 * SelfCall — a request the app makes to itself is signed with its own key and told apart from anyone else's;
 * the http step signs a call to the app's own address and nothing else.
 */

namespace tests\unit;

use app\SelfCall;
use PHPUnit\Framework\TestCase;

class SelfCallTest extends TestCase {

    protected function setUp(): void {
        \Flight::set('security.app_key', str_repeat('ab', 32));
        \Flight::set('app.baseurl', 'https://cafe.example.test');
    }

    public function testTheAppsOwnSignedRequestPassesAndOthersAreRefused(): void {
        $url = 'https://cafe.example.test/msghook/hourly?x=1';
        $h = SelfCall::header('POST', $url, 1000);
        $this->assertStringStartsWith('X-Tiknix-Self: t=1000,v1=', $h);
        $sig = substr($h, strlen('X-Tiknix-Self: '));
        $this->assertTrue(SelfCall::check('POST', '/msghook/hourly?x=1', $sig, 1010));
        $this->assertFalse(SelfCall::check('GET', '/msghook/hourly?x=1', $sig, 1010), 'another method');
        $this->assertFalse(SelfCall::check('POST', '/msghook/event', $sig, 1010), 'another path');
        $this->assertFalse(SelfCall::check('POST', '/msghook/hourly?x=1', $sig, 1000 + SelfCall::FRESH + 1), 'stale');
        $this->assertSame('stale signature', SelfCall::reason());
        $this->assertFalse(SelfCall::check('POST', '/msghook/hourly?x=1', '', 1010));
        $this->assertStringContainsString('only this app', SelfCall::reason());
        \Flight::set('security.app_key', str_repeat('cd', 32));
        $this->assertFalse(SelfCall::check('POST', '/msghook/hourly?x=1', $sig, 1010), 'another key');
    }

    public function testOnlyTheAppsOwnAddressIsSelf(): void {
        $this->assertTrue(SelfCall::isSelf('https://cafe.example.test/msghook/hourly'));
        $this->assertTrue(SelfCall::isSelf('https://CAFE.example.test/'));
        $this->assertFalse(SelfCall::isSelf('https://api.mailgun.net/v3/x'));
        $this->assertFalse(SelfCall::isSelf('https://cafe.example.test.evil.com/'));
        $this->assertFalse(SelfCall::isSelf('not a url'));
    }

    public function testNoKeyIsAFaultNotAnEmptySignature(): void {
        \Flight::set('security.app_key', '');
        \Flight::set('config', []);
        $this->expectException(\RuntimeException::class);
        SelfCall::header('POST', 'https://cafe.example.test/x');
    }
}
