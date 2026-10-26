<?php
namespace app\concepts\messaging\tests;

require_once __DIR__ . '/ClientCase.php';

use app\concepts\messaging\Providers;
use app\concepts\messaging\Sync;

class SyncTest extends ClientCase {

    public function testMembersBecomeContacts(): void {
        Providers::register(['phone' => FakePhone::class]);
        $ghost = \app\Bean::dispense('member'); $ghost->username = 'public-user-entity'; $ghost->email = 'public@localhost'; $ghost->level = 101; $ghost->status = 'system'; \app\Bean::store($ghost);
        $r = Sync::contacts();
        $puts = $this->sent('PUT', 'contacts');
        $this->assertCount(1, $puts);
        $rows = $puts[0]['contacts'];
        $this->assertCount(3, $rows, 'the platform\'s own public-visitor row is not a person');
        $ann = $rows[0]; $bo = $rows[1]; $cy = $rows[2];
        $this->assertSame((string) $this->ids['ann'], $ann['eid']);
        $this->assertSame(['ann@example.test', 'Ann', 'active', '', false], [$ann['email'], $ann['first_name'], $ann['status'], $ann['phone'], $ann['phone_verified']]);
        $this->assertSame(['+15551234567', true], [$bo['phone'], $bo['phone_verified']], 'the phone provider\'s proved number travels');
        $this->assertSame('', $cy['last_seen_at'], 'never signed in');
        $z = \app\Bean::load('member', $this->ids['cy']); $z->createdAt = '0'; \app\Bean::store($z);
        $this->assertSame('', Sync::contactRow($z)['joined_at'], "an old row's '0' is no date");
        $this->assertNotSame('', $cy['joined_at']);
        $this->assertSame(['created' => 0, 'updated' => 0], $r, 'the fake answered no counts');
    }

    public function testCategoriesAndAudiencesAreMirrored(): void {
        Providers::register(['audiences' => FakeAudiences::class, 'categories' => [['news', 'News', 'What is new', true, true, 'email,text'], ['alerts', 'Alerts', 'Urgent', true, false]]]);
        $ghost = \app\Bean::dispense('member'); $ghost->username = 'public-user-entity'; $ghost->email = 'public@localhost'; $ghost->level = 101; $ghost->status = 'system'; \app\Bean::store($ghost);
        FakeAudiences::$members['group:1'][] = (int) $ghost->id;   // the welcome community holds the public visitor too
        $this->assertSame(2, Sync::categories());
        $cats = $this->sent('PUT', 'categories')[0]['categories'];
        $this->assertSame(['code' => 'news', 'label' => 'News', 'description' => 'What is new', 'default_on' => true, 'can_opt_out' => true, 'channels' => ['email', 'text']], $cats[0]);
        $this->assertSame(['email', 'text'], $cats[1]['channels'], 'channels left out means both');
        $this->assertSame(1, Sync::audiences());
        $a = $this->sent('PUT', 'audiences')[0]['audiences'][0];
        $this->assertSame('group:1', $a['key']);
        $this->assertSame([(string) $this->ids['ann'], (string) $this->ids['bo']], $a['members']);
        $this->assertSame((string) $this->ids['ann'], $a['owner_eid']);
        $this->assertSame(['group' => ['name' => 'Walkers']], $a['vars']);
        $this->assertSame('https://example.test/group/1', $a['link']);
        $this->assertSame([(string) $this->ids['bo'] => false], $a['allowed'], 'the app\'s switch seeds a new membership; Ann has none, so none is sent for her');
    }

    public function testSystemMessagesAreMadeOncePaused(): void {
        Providers::register([
            'triggers' => ['no_friends_days' => FakeTrigger::class],
            'system' => [
                ['kind' => 'digest', 'category' => 'news', 'subject' => '{headline}', 'body_email' => '<p>{content_html}</p>', 'cadence' => 'every_n_days', 'cadence_days' => 7],
                ['kind' => 'trigger', 'trigger_code' => 'no_friends_days', 'trigger_days' => 7, 'category' => 'reminders', 'subject' => 'Hi', 'body_email' => '<p>x</p>'],
                ['kind' => 'trigger', 'trigger_code' => 'inactive_days', 'trigger_days' => 30, 'category' => 'news', 'subject' => 'Hey', 'body_email' => '<p>y</p>'],
                ['kind' => 'trigger', 'trigger_code' => 'pipeline', 'code' => 'thanks', 'category' => 'news', 'subject' => 'Thanks {total}', 'body_email' => '<p>z</p>'],
            ],
        ]);
        self::$answers['GET campaigns'] = function ($body, $path) {
            if (str_contains($path, 'kind=trigger')) return [200, ['campaigns' => [['id' => 9, 'trigger_code' => 'inactive_days']]]];
            return [200, ['campaigns' => []]];
        };
        $n = 0;
        self::$answers['POST campaigns'] = function ($body) use (&$n) { $n++; return [201, ['campaign' => ['id' => 100 + $n] + $body]]; };
        $lines = Sync::system();
        $this->assertSame(['digest: made #101, paused — read it on /messaging and switch it on', 'nudge no_friends_days: made #102, paused — read it on /messaging and switch it on', 'nudge inactive_days: kept #9', 'nudge pipeline: made #103, paused — read it on /messaging and switch it on'], $lines);
        $posts = $this->sent('POST', 'campaigns');
        $this->assertCount(3, $posts);
        $this->assertSame(['pipeline', 'thanks'], [$posts[2]['trigger_code'], $posts[2]['code']], 'a Custom message carries its code; the service makes it pipeline-fed');
        $this->assertArrayNotHasKey('content_from_app', $posts[2]);
        $this->assertSame('paused', $posts[0]['status']);
        $this->assertSame((string) $this->ids['ann'], $posts[0]['sender']['eid'], 'the first admin is the sender');
        $this->assertTrue($posts[1]['content_from_app'], "the app's own trigger is app-fed");
        $this->assertArrayNotHasKey('content_from_app', $posts[0], 'the digest is app-fed by the service\'s own rule');
    }

    public function testACustomSystemMessageNeedsACode(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a code');
        Providers::register(['system' => [['kind' => 'trigger', 'trigger_code' => 'pipeline', 'category' => 'news', 'subject' => 's', 'body_email' => 'b']]]);
    }

    public function testRegisterRefusesABadContract(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("names trigger 'nope'");
        Providers::register(['system' => [['kind' => 'trigger', 'trigger_code' => 'nope', 'trigger_days' => 3, 'category' => 'news', 'subject' => 's', 'body_email' => 'b']]]);
    }

    public function testRunDoesEverythingInOrder(): void {
        Providers::register(['audiences' => FakeAudiences::class, 'categories' => [['news', 'News', '', true, true]]]);
        self::$answers['PUT contacts'] = ['created' => 3, 'updated' => 0];
        $lines = Sync::run();
        $this->assertSame(['categories: 1 kept in line', 'contacts: 3 new, 0 brought up to date', 'audiences: 1 mirrored'], $lines);
        $this->assertSame(['PUT categories', 'PUT contacts', 'PUT audiences'], $this->paths());
    }
}
