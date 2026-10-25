<?php
/**
 * app\Host judges "did this request come through capricorn" by the TCP peer (TIKNIX_PEER_ADDR,
 * nginx's $realip_remote_addr), not by REMOTE_ADDR: with real_ip on, REMOTE_ADDR is the visitor.
 * Judging by REMOTE_ADDR made the runtime distrust capricorn's X-Forwarded-Host and -Proto for
 * every outside visitor — serenity's sites answered 404 (2026-10-25).
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\Host;

class HostPeerTest extends TestCase {
    private array $server;

    protected function setUp(): void {
        $this->server = $_SERVER;
        unset($_SERVER['HTTPS'], $_SERVER['REQUEST_SCHEME']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'serenity-denver.tiknix.com';
        $_SERVER['HTTP_HOST'] = '10.10.10.114';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';   // an outside visitor, as real_ip reports it
        unset($_SERVER['TIKNIX_PEER_ADDR'], $_SERVER['TIKNIX_TRUSTED_PROXY']);
        putenv('TIKNIX_TRUSTED_PROXY=173.231.12.84');
    }
    protected function tearDown(): void { $_SERVER = $this->server; putenv('TIKNIX_TRUSTED_PROXY'); }

    public function testTheForwardedHeadersAreBelievedWhenTheTrustedProxyIsThePeer(): void {
        $_SERVER['TIKNIX_PEER_ADDR'] = '173.231.12.84';
        $this->assertTrue(Host::https());
        $this->assertSame('serenity-denver.tiknix.com', Host::visitorHost());
    }

    public function testNotWhenTheVisitorConnectedDirectly(): void {
        // no peer passed and REMOTE_ADDR is a public address: nothing in front of us to believe
        $this->assertFalse(Host::https());
        $this->assertSame('10.10.10.114', Host::visitorHost());
    }

    public function testWithoutTheParamTheOldJudgementByRemoteAddrStillHolds(): void {
        $_SERVER['REMOTE_ADDR'] = '173.231.12.84';   // nginx without real_ip: capricorn IS REMOTE_ADDR
        $this->assertTrue(Host::https());
        $this->assertSame('serenity-denver.tiknix.com', Host::visitorHost());
    }
}
