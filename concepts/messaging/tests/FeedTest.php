<?php
namespace app\concepts\messaging\tests;

require_once __DIR__ . '/ClientCase.php';

use app\concepts\messaging\Campaigns;
use app\concepts\messaging\Client;
use app\concepts\messaging\Digest;
use app\concepts\messaging\Providers;
use app\concepts\messaging\Reach;
use app\concepts\messaging\ServiceException;

class FeedTest extends ClientCase {

    private function campaigns(): array {
        return [
            ['id' => 1, 'kind' => 'digest', 'trigger_code' => '', 'trigger_days' => 0, 'content_from_app' => true, 'status' => 'active', 'subject' => '{headline}'],
            ['id' => 2, 'kind' => 'trigger', 'trigger_code' => 'no_friends_days', 'trigger_days' => 7, 'content_from_app' => true, 'status' => 'active', 'subject' => 'Hi'],
            ['id' => 3, 'kind' => 'trigger', 'trigger_code' => 'inactive_days', 'trigger_days' => 30, 'content_from_app' => false, 'status' => 'active', 'subject' => 'Hey'],
            ['id' => 4, 'kind' => 'trigger', 'trigger_code' => 'pipeline', 'trigger_days' => 0, 'content_from_app' => true, 'status' => 'active', 'subject' => 'Thanks'],
        ];
    }

    public function testTheDigestAndAppNudgesAreFedWithWhatTheAppSays(): void {
        Providers::register(['digest' => FakeDigest::class, 'triggers' => ['no_friends_days' => FakeTrigger::class]]);
        $ann = $this->ids['ann']; $bo = $this->ids['bo'];
        self::$answers['GET campaigns'] = ['campaigns' => $this->campaigns()];
        self::$answers['GET campaigns/1/due'] = ['due' => [['contact' => (string) $ann, 'email' => 'ann@example.test', 'first_name' => 'Ann', 'since' => '2026-10-19 09:00:00'], ['contact' => (string) $bo, 'email' => 'bo@example.test', 'first_name' => 'Bo', 'since' => '2026-10-19 09:00:00']]];
        self::$answers['GET campaigns/2/due'] = ['due' => [['contact' => (string) $ann, 'email' => 'ann@example.test', 'first_name' => 'Ann', 'since' => ''], ['contact' => (string) $bo, 'email' => 'bo@example.test', 'first_name' => 'Bo', 'since' => '']]];
        self::$answers['POST campaigns/1/deliveries'] = ['result' => ['recipients' => 1, 'sent' => 1, 'failed' => 0, 'not_sent' => 0, 'skipped' => []]];
        self::$answers['POST campaigns/2/deliveries'] = ['result' => ['recipients' => 1, 'sent' => 1, 'failed' => 0, 'not_sent' => 0, 'skipped' => ['x: not due']]];
        FakeDigest::$content[$ann] = ['headline' => '3 new posts', 'html' => '<ul><li>one</li></ul>', 'text' => '- one'];   // Bo has nothing new
        FakeTrigger::$qualify = [$bo];
        $lines = Digest::feed();
        $this->assertSame(['campaign #1 fed: {"recipients":1,"sent":1,"failed":0,"not_sent":0}', 'campaign #2 fed: {"recipients":1,"sent":1,"failed":0,"not_sent":0}', '  skipped: x: not due'], $lines);
        $d1 = $this->sent('POST', 'campaigns/1/deliveries')[0]['deliveries'];
        $this->assertSame([['contact' => (string) $ann, 'headline' => '3 new posts', 'content_html' => '<ul><li>one</li></ul>', 'content_text' => '- one']], $d1, 'only the one with news');
        $this->assertSame([[$ann, '2026-10-19 09:00:00'], [$bo, '2026-10-19 09:00:00']], FakeDigest::$asked, 'since is the service\'s');
        $d2 = $this->sent('POST', 'campaigns/2/deliveries')[0]['deliveries'];
        $this->assertSame([['contact' => (string) $bo, 'headline' => "Make a friend, #$bo", 'content_html' => '<p>go</p>', 'content_text' => 'go']], $d2, 'only the one who qualifies');
        $this->assertSame([], $this->sent('GET', 'campaigns/3/due'), 'the service evaluates inactive_days itself: not asked');
        $this->assertSame([], $this->sent('GET', 'campaigns/4/due'), 'a Custom one is its pipeline\'s to send: not asked');
    }

    public function testNobodyDueSendsNothing(): void {
        Providers::register(['digest' => FakeDigest::class]);
        self::$answers['GET campaigns'] = ['campaigns' => [$this->campaigns()[0]]];
        self::$answers['GET campaigns/1/due'] = ['due' => []];
        $this->assertSame(['campaign #1 (digest): nobody due'], Digest::feed());
        $this->assertSame([], $this->sent('POST', 'campaigns/1/deliveries'));
    }

    public function testADigestWithoutAProviderIsAFaultNotSilence(): void {
        Providers::register([]);
        self::$answers['GET campaigns'] = ['campaigns' => [$this->campaigns()[0]]];
        self::$answers['GET campaigns/1/due'] = ['due' => [['contact' => '1', 'email' => 'a@b.c', 'first_name' => 'A', 'since' => '']]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registered no digest provider');
        Digest::feed();
    }

    public function testReachAsksTheServiceAndMirrorsAStranger(): void {
        $ann = $this->ids['ann'];
        $asked = 0;
        self::$answers["GET contacts/{$ann}/reach"] = function () use (&$asked, $ann) { $asked++; return $asked === 1 ? [404, ['error' => "no contact '{$ann}'"]] : [200, ['allowed' => false, 'why' => 'they switched news off']]; };
        $this->assertFalse(Reach::allows($ann, 'news', 'email', 'group:1'));
        $this->assertSame(['GET contacts/' . $ann . '/reach', 'PUT contacts', 'GET contacts/' . $ann . '/reach'], $this->paths(), 'a 404 mirrors the member once and asks again');
        $this->assertStringEndsWith('scope=group%3A1', self::$calls[0][1]);
        $this->assertTrue(Reach::allows($ann, 'transactional', 'email'), 'transactional never asks');
        $this->assertSame('they switched news off', Reach::why($ann, 'news', 'email'));
    }

    public function testActedCreditsOrLogs(): void {
        $bo = $this->ids['bo'];
        self::$answers['POST events'] = ['credited' => true, 'send' => 77];
        $this->assertSame(77, Campaigns::acted($bo, 'post', ['post' => 5]));
        $this->assertSame(['contact' => (string) $bo, 'kind' => 'post', 'meta' => ['post' => 5]], $this->sent('POST', 'events')[0]);
        self::$answers['POST events'] = fn() => [503, ['error' => 'down']];
        $this->assertNull(Campaigns::acted($bo, 'post'));
        $this->assertStringContainsString('ERROR Campaigns::acted', \Flight::get('log')->errors[0]);
        self::$answers['POST events'] = fn() => [404, ['error' => 'no contact']];
        $this->assertNull(Campaigns::acted($bo, 'post'), 'a stranger has no click to credit; not an error');
    }

    public function testARefusalCarriesTheServicesWords(): void {
        self::$answers['POST campaigns'] = fn() => [422, ['error' => 'subject: this message cannot fill {nickname}']];
        try { Campaigns::create(['subject' => 'Hi {nickname}']); $this->fail('expected a refusal'); }
        catch (ServiceException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('subject', Client::field($e));
            $this->assertSame('this message cannot fill {nickname}', Client::said($e));
        }
    }
}
