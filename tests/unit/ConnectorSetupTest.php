<?php
/**
 * A connector's setup checklist: the steps it declares are checked, each knows when it is done, the next opens only
 * after the one before, a plugin may change them, and what the platform keeps on a connection survives a new save.
 */

namespace tests\unit;

use app\ConnectionStore;
use app\ConnectorSetup;
use PHPUnit\Framework\TestCase;

class ConnectorSetupTest extends TestCase {

    private const STEPS = [
        ['id' => 'key', 'kind' => 'key', 'title' => 'Add your key', 'fields' => ['domain']],
        ['id' => 'from', 'kind' => 'fields', 'title' => 'Who mail is from', 'fields' => ['from_email']],
        ['id' => 'events', 'kind' => 'secret', 'title' => 'Events', 'copy' => [['label' => 'Webhook address', 'value' => '{webhook}']]],
        ['id' => 'replies', 'kind' => 'copy', 'title' => 'Replies', 'optional' => true, 'copy' => [['label' => 'Match', 'value' => 'reply-.*@{field.inbound_domain|field.domain}']]],
        ['id' => 'share', 'kind' => 'share', 'with' => 'messaging', 'title' => 'Share'],
        ['id' => 'later', 'kind' => 'copy', 'title' => 'Only with a plugin', 'when' => 'concept:nosuchplugin'],
        ['id' => 'test', 'kind' => 'test', 'title' => 'Test'],
    ];

    protected function tearDown(): void { ConnectorSetup::reset(); }

    public function testWithoutStepsAConnectorIsItsKeyAndItsTest(): void {
        $steps = ConnectorSetup::declared('quo', ['label' => 'Quo', 'auth_type' => 'api_key', 'category' => 'Messaging', 'test' => ['action' => '/connections/smstest', 'label' => 'Send a test text']]);
        $this->assertSame(['key', 'test'], array_column($steps, 'id'));
        $this->assertSame('Add your Quo key', $steps[0]['title']);
        $pay = ConnectorSetup::declared('stripe', ['label' => 'Stripe', 'auth_type' => 'api_key', 'category' => 'Payments']);
        $this->assertSame(['key', 'webhook'], array_column($pay, 'id'), 'a payments provider calls back');
        $this->assertTrue($pay[1]['optional'], 'a webhook is not every app\'s');
        $this->assertSame(['key'], array_column(ConnectorSetup::declared('shopify', ['label' => 'Shopify']), 'id'));
        $this->assertSame('Sign in to Shopify', ConnectorSetup::declared('shopify', ['label' => 'Shopify'])[0]['title']);
    }

    public function testStepsWrittenWrongAreRefusedByName(): void {
        foreach ([
            [[['id' => 'key', 'kind' => 'nope', 'title' => 'x']], "kind 'nope'"],
            [[['id' => 'Key', 'kind' => 'key', 'title' => 'x']], 'needs an id'],
            [[['id' => 'key', 'kind' => 'key', 'title' => 'x'], ['id' => 'key', 'kind' => 'test', 'title' => 'y']], "two setup steps are called 'key'"],
            [[['id' => 'from', 'kind' => 'fields', 'title' => 'x']], 'the first setup step is the key'],
            [[['id' => 'key', 'kind' => 'key', 'title' => 'x'], ['id' => 's', 'kind' => 'share', 'with' => 'elsewhere', 'title' => 'y']], "shares with 'elsewhere'"],
        ] as [$setup, $says]) {
            try { ConnectorSetup::declared('x', ['setup' => $setup]); $this->fail("accepted: {$says}"); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString($says, $e->getMessage()); }
        }
    }

    public function testNotConnectedOnlyTheKeyIsOpen(): void {
        $c = ConnectorSetup::checklist('mailgun', ['setup' => self::STEPS], null, 'app.example.com');
        $this->assertSame(['key', 'from', 'events', 'replies', 'share', 'test'], array_column($c['steps'], 'id'), 'a plugin\'s step is there only while the plugin is on');
        $this->assertSame(['now', 'later', 'later', 'later', 'later', 'later'], array_column($c['steps'], 'state'));
        $this->assertSame(0, $c['done']);
        $this->assertSame(6, $c['total']);
        $this->assertFalse($c['complete']);
    }

    public function testEachStepKnowsWhenItIsDoneAndTheNextOneOpens(): void {
        $live = ['id' => 4, 'fields' => ['domain' => 'mail.example.com', 'inbound_domain' => ''], 'webhookSet' => false, 'setup' => ['from' => '2026-10-27 10:00:00'], 'shared' => []];
        $c = ConnectorSetup::checklist('mailgun', ['setup' => self::STEPS], $live, 'app.example.com');
        $this->assertSame(['done', 'done', 'now', 'later', 'later', 'later'], array_column($c['steps'], 'state'));
        $this->assertSame('events', $c['current']);
        $this->assertMatchesRegularExpression('#^https?://app\.example\.com/webhook/mailgun$#', $c['steps'][2]['copy'][0]['value'], '{webhook} is this app\'s own address');
        $this->assertSame('reply-.*@mail.example.com', $c['steps'][3]['copy'][0]['value'], 'the first of {field.a|field.b} that is set');

        $live['webhookSet'] = true;
        $live['setup']['replies'] = 'skipped';
        $live['shared'] = ['messaging' => ['site' => 'other.example.com', 'at' => '2026-10-27']];
        $c = ConnectorSetup::checklist('mailgun', ['setup' => self::STEPS], $live, 'app.example.com');
        $this->assertSame(['done', 'done', 'done', 'done', 'now', 'later'], array_column($c['steps'], 'state'), 'shared for another site is not shared for this one');
        $this->assertTrue($c['steps'][3]['skipped']);

        $live['shared']['messaging']['site'] = 'APP.example.com';
        $live['setup']['test'] = '2026-10-27 11:00:00';
        $c = ConnectorSetup::checklist('mailgun', ['setup' => self::STEPS], $live, 'app.example.com');
        $this->assertTrue($c['complete']);
        $this->assertSame(6, $c['done']);
        $this->assertNull($c['current']);
    }

    public function testAStepMayBeDoneByWhatTheConnectionHas(): void {
        $steps = [['id' => 'key', 'kind' => 'key', 'title' => 'Key'], ['id' => 'protect', 'kind' => 'fields', 'title' => 'Protect', 'done' => 'field:signed'],
                  ['id' => 'access', 'kind' => 'go', 'title' => 'Access', 'go' => ['label' => 'Set it up', 'url' => '/connections/storage']]];
        $c = ConnectorSetup::checklist('bunnystream', ['setup' => $steps], ['id' => 1, 'fields' => ['signed' => '1'], 'setup' => []], 'app.example.com');
        $this->assertSame(['done', 'done', 'now'], array_column($c['steps'], 'state'));
        $c = ConnectorSetup::checklist('bunnystream', ['setup' => $steps], ['id' => 1, 'fields' => ['signed' => ''], 'setup' => []], 'app.example.com');
        $this->assertSame('protect', $c['current']);
        $this->expectExceptionMessage("goes to 'https://elsewhere.example'");
        ConnectorSetup::declared('x', ['setup' => [$steps[0], ['id' => 'go', 'kind' => 'go', 'title' => 'Away', 'go' => ['url' => 'https://elsewhere.example']]]]);
    }

    public function testEveryConnectorThisAppHasDeclaresStepsThatHold(): void {
        foreach (\app\services\connectors\ConnectorRegistry::all() as $c) {
            $steps = ConnectorSetup::declared($c->key(), $c->meta());
            $this->assertSame('key', $steps[0]['kind'], $c->key());
        }
    }

    public function testAPluginMayChangeTheSteps(): void {
        ConnectorSetup::extend('mailgun', function (array $steps, array $ctx) {
            foreach ($steps as $i => $s) if ($s['id'] === 'events') { $steps[$i]['copy'] = [['label' => 'Service', 'value' => 'https://svc.example/hook/' . $ctx['host']]]; $steps[$i]['problem'] = 'not yet'; }
            return $steps;
        });
        $c = ConnectorSetup::checklist('mailgun', ['setup' => self::STEPS], null, 'app.example.com');
        $this->assertSame('https://svc.example/hook/app.example.com', $c['steps'][2]['copy'][0]['value']);
        $this->assertSame('not yet', $c['steps'][2]['problem']);
        ConnectorSetup::extend('mailgun', fn() => null);
        $this->expectException(\UnexpectedValueException::class);
        ConnectorSetup::checklist('mailgun', ['setup' => self::STEPS], null, 'app.example.com');
    }

    public function testAValueHoldsOnlyWhatItKnows(): void {
        $this->expectExceptionMessage('{secret} is not something a value can hold');
        ConnectorSetup::fill('x {secret}', 'mailgun', null, 'app.example.com');
    }

    public function testWhatThePlatformKeepsSurvivesANewSave(): void {
        $old = json_encode(['fields' => ['domain' => 'a.example'], 'shared_with' => ['messaging' => ['site' => 'app.example.com']], 'setup' => ['from' => 'x']]);
        $kept = json_decode(ConnectionStore::keepOwnMeta($old, json_encode(['fields' => ['domain' => 'b.example']])), true);
        $this->assertSame('b.example', $kept['fields']['domain'], 'the connector\'s own details are the new ones');
        $this->assertSame('app.example.com', $kept['shared_with']['messaging']['site']);
        $this->assertSame(['from' => 'x'], $kept['setup']);
        $named = json_decode(ConnectionStore::keepOwnMeta($old, '{"setup":{}}'), true);
        $this->assertSame([], $named['setup'], 'a new copy that names one wins');
        $this->assertArrayHasKey('shared_with', $named, 'and the one it does not name is kept');
    }

    public function testTheMailgunManifestIsPublishable(): void {
        $file = \app\Paths::root() . '/connectors/mailgun.json';
        $m = json_decode((string) file_get_contents($file), true);
        if (!isset($m['setup'])) $this->markTestSkipped('this install has mailgun ' . ($m['version'] ?? '?') . ', before setup steps');
        $this->assertSame([], \app\services\connectors\ConnectorRegistry::lint($m, $file));
    }
}
