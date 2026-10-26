<?php
/**
 * Message events from the service: the signature over "<t>.<body>" with the site's hook key, what is refused, and the
 * context a pipeline gets. The POST itself (controls/Msghook.php) is one call of these two.
 */

namespace app\concepts\messaging\tests;

require_once __DIR__ . '/ClientCase.php';
require_once dirname(__DIR__) . '/lib/Events.php';

use app\concepts\messaging\Events;

class EventsTest extends ClientCase {

    private const KEY = 'hook-key-for-tests';

    private function signed(array $body, string $key = self::KEY, ?int $t = null): array {
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES);
        $t = $t ?? time();
        return [$raw, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, $key)];
    }

    private function payload(string $event = 'message.replied'): array {
        return ['event' => $event, 'at' => '2026-10-26T15:00:00+00:00', 'site' => 'app.test', 'member_id' => '42',
                'campaign' => ['id' => 7, 'code' => 'no_friends', 'subject' => 'Meeting people'], 'send' => 99, 'kind' => 'reply',
                'meta' => ['from' => 'ann@test.example', 'subject' => 'Re: Meeting people']];
    }

    public function testTheServicesSignatureIsAcceptedAndEverythingElseRefused(): void {
        [$raw, $sig] = $this->signed($this->payload());
        $this->assertNull(Events::verify($raw, $sig, self::KEY));
        $this->assertSame('signature does not match', Events::verify($raw, $sig, 'another-key'));
        $this->assertSame('signature does not match', Events::verify($raw . ' ', $sig, self::KEY), 'a changed body');
        $this->assertStringContainsString('no signature', Events::verify($raw, '', self::KEY));
        $this->assertSame('no hook key for this site', Events::verify($raw, $sig, ''));
        [$raw2, $old] = $this->signed($this->payload(), self::KEY, time() - 3600);
        $this->assertSame('stale signature', Events::verify($raw2, $old, self::KEY));
    }

    public function testTheContextAPipelineGets(): void {
        $c = Events::context($this->payload());
        $this->assertSame(42, $c['member_id']);
        $this->assertSame('no_friends', $c['campaign_code']);
        $this->assertSame(7, $c['campaign_id']);
        $this->assertSame('Meeting people', $c['subject']);
        $this->assertSame('reply', $c['kind']);
        $this->assertSame('ann@test.example', $c['meta']['from']);
        try { Events::context($this->payload('ticket.validated')); $this->fail('not a message event'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not one of the message events', $e->getMessage()); }
        $p = $this->payload(); unset($p['member_id']);
        try { Events::context($p); $this->fail('no member'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('member_id', $e->getMessage()); }
    }

    public function testTheHookKeyIsReadFromTheSiteOnceAndKept(): void {
        $host = \app\concepts\messaging\Client::site();
        self::$answers['GET sites/' . rawurlencode($host)] = ['ok' => true, 'site' => ['host' => $host, 'webhook_url' => 'https://messaging.test/hook/mailgun/3/' . self::KEY]];
        $this->assertSame(self::KEY, Events::hookKey(true));
        $this->assertSame(self::KEY, Events::hookKey(), 'the setting answers; the service is not asked again');
        $this->assertCount(1, array_filter(self::$calls, fn($c) => $c[0] === 'GET' && $c[1] === 'sites/' . rawurlencode($host)));
    }

    public function testWhatOneMemberDidWithAMessage(): void {
        self::$answers['GET campaigns?code=welcome_1'] = ['ok' => true, 'campaigns' => [['id' => 7, 'code' => 'welcome_1', 'subject' => 'Welcome']]];
        self::$answers['GET campaigns/7/recipients?contact=42'] = ['ok' => true, 'recipients' => [['contact' => '42', 'sent_at' => '2026-10-20 09:00:00', 'opened' => true, 'clicks' => 0, 'replies' => 0]]];
        $r = \app\concepts\messaging\Campaigns::recipient('welcome_1', 42);
        $this->assertTrue($r['opened']); $this->assertSame(7, $r['campaign']);
        self::$answers['GET campaigns/7/recipients?contact=43'] = ['ok' => true, 'recipients' => []];
        $this->assertNull(\app\concepts\messaging\Campaigns::recipient('welcome_1', 43), 'never sent to them');
    }
}
