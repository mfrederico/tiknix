<?php
/** Mailgun's setup steps with Messaging on: sharing comes before the addresses, which are the service's once shared. */

namespace app\concepts\messaging\tests;

require_once __DIR__ . '/ClientCase.php';

use app\concepts\messaging\Setup;

class SetupTest extends ClientCase {

    private const STEPS = [
        ['id' => 'key', 'kind' => 'key', 'title' => 'Key'], ['id' => 'from', 'kind' => 'fields', 'title' => 'From'],
        ['id' => 'events', 'kind' => 'secret', 'title' => 'Events', 'copy' => [['label' => 'Webhook address', 'value' => '{webhook}']]],
        ['id' => 'replies', 'kind' => 'copy', 'title' => 'Replies'], ['id' => 'share', 'kind' => 'share', 'with' => 'messaging', 'title' => 'Share'],
        ['id' => 'test', 'kind' => 'test', 'title' => 'Test'],
    ];

    public function testBeforeTheShareTheAddressesWaitAndSharingMovesUp(): void {
        $out = Setup::mailgun(self::STEPS, ['key' => 'mailgun', 'host' => 'app.example.com', 'live' => ['shared' => []]]);
        $this->assertSame(['key', 'from', 'share', 'events', 'replies', 'test'], array_column($out, 'id'));
        $this->assertSame([], $out[3]['copy'], 'no address that would not work');
        $this->assertStringContainsString('Share it with Messaging first', $out[3]['problem']);
    }

    public function testOnceSharedTheServiceAddressIsWhereMailgunCalls(): void {
        \Flight::setSetting(Setup::SETTING . 'app.example.com', 'https://svc.example/hook/mailgun/1/abc', 0);
        $out = Setup::mailgun(self::STEPS, ['key' => 'mailgun', 'host' => 'app.example.com', 'live' => ['shared' => ['messaging' => ['site' => 'app.example.com']]]]);
        $byId = array_column($out, null, 'id');
        $this->assertSame('https://svc.example/hook/mailgun/1/abc', $byId['events']['copy'][0]['value']);
        $this->assertSame(['{webhook}', 'https://svc.example/hook/mailgun/1/abc'], array_column(array_slice($byId['replies']['copy'], 1), 'value'), 'replies go to this app and to the service');
        $this->assertArrayNotHasKey('problem', $byId['events']);
    }

    public function testWithoutItsStepNothingChanges(): void {
        $steps = array_values(array_filter(self::STEPS, fn($s) => $s['id'] !== 'share'));
        $this->assertSame($steps, Setup::mailgun($steps, ['key' => 'mailgun', 'host' => 'app.example.com', 'live' => null]));
    }
}
