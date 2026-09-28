<?php
/**
 * Pins MicrosoftConnector's interface before it moves into core beside Shopify,
 * Stripe and QuickBooks (see MICROSOFT-CONNECTOR.md). The connector's private
 * call() forwards to AbstractConnector::http(), which is already `protected` —
 * so a test subclass overrides THAT to stub every Graph request. No change to
 * services/connectors/MicrosoftConnector.php was needed.
 */

namespace tests\unit;

use app\services\connectors\MicrosoftConnector;
use PHPUnit\Framework\TestCase;

/** Records every outbound request and answers from a queue instead of cURL. */
class TestableMicrosoftConnector extends MicrosoftConnector {

    /** @var array<int, array{method:string,url:string,opts:array}> */
    public array $requests = [];

    /** @var array<int, array{0:int,1:string,2:string}> queued [status, rawBody, transportError] */
    public array $responses = [];

    protected function http(string $method, string $url, array $opts = []): array {
        $this->requests[] = ['method' => $method, 'url' => $url, 'opts' => $opts];
        if (empty($this->responses)) {
            throw new \Exception('MicrosoftConnectorCharacterizationTest: no stubbed HTTP response queued for '
                . $method . ' ' . $url);
        }
        return array_shift($this->responses);
    }

    public function queueJson(int $status, array $body): void {
        $this->responses[] = [$status, json_encode($body), ''];
    }

    public function queueEmpty(int $status): void {
        $this->responses[] = [$status, '', ''];
    }
}

class MicrosoftConnectorCharacterizationTest extends TestCase {

    private function connector(): TestableMicrosoftConnector {
        return new TestableMicrosoftConnector();
    }

    // ---- identity -------------------------------------------------------------

    public function testKeyIsMicrosoft(): void {
        $this->assertSame('microsoft', $this->connector()->key());
    }

    public function testRequiresOwnAppIsTrue(): void {
        $this->assertTrue($this->connector()->requiresOwnApp());
    }

    // ---- scopes -----------------------------------------------------------------

    public function testDefaultScopes(): void {
        $this->assertSame(
            'offline_access User.Read Mail.Send Mail.ReadWrite Calendars.ReadWrite',
            $this->connector()->defaultScopes()
        );
    }

    // ---- authorizeUrl -----------------------------------------------------------

    public function testAuthorizeUrlShape(): void {
        $c = $this->connector();
        $url = $c->authorizeUrl([
            'app'          => ['client_id' => 'client-123', 'client_secret' => 'shh'],
            'redirect_uri' => 'https://example.test/connections/callback/microsoft',
            'state'        => 'signed-state-abc',
        ]);

        $this->assertStringStartsWith(
            'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url
        );

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('client-123', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('query', $query['response_mode']);
        $this->assertSame('https://example.test/connections/callback/microsoft', $query['redirect_uri']);
        $this->assertSame('signed-state-abc', $query['state']);
        $this->assertSame(
            'offline_access User.Read Mail.Send Mail.ReadWrite Calendars.ReadWrite', $query['scope']
        );
    }

    public function testAuthorizeUrlWithoutOwnAppThrows(): void {
        $c = $this->connector();
        $this->expectExceptionMessage("own app");
        $c->authorizeUrl([
            'redirect_uri' => 'https://example.test/connections/callback/microsoft',
            'state'        => 'x',
        ]);
    }

    // ---- brokerTools() shape ------------------------------------------------------

    private function toolsByName(): array {
        $byName = [];
        foreach ($this->connector()->brokerTools() as $t) $byName[$t['name']] = $t;
        return $byName;
    }

    public function testBrokerToolNamesExact(): void {
        $this->assertSame(
            ['send_mail', 'list_messages', 'get_message', 'list_events', 'create_event', 'update_event'],
            array_keys($this->toolsByName())
        );
    }

    public function testSendMailRequiredKeys(): void {
        $this->assertSame(['to', 'subject', 'body_html'], $this->toolsByName()['send_mail']['inputSchema']['required']);
    }

    public function testListMessagesHasNoRequiredKeys(): void {
        $this->assertArrayNotHasKey('required', $this->toolsByName()['list_messages']['inputSchema']);
    }

    public function testGetMessageRequiredKeys(): void {
        $this->assertSame(['id'], $this->toolsByName()['get_message']['inputSchema']['required']);
    }

    public function testListEventsRequiredKeys(): void {
        $this->assertSame(['start', 'end'], $this->toolsByName()['list_events']['inputSchema']['required']);
    }

    public function testCreateEventRequiredKeys(): void {
        $this->assertSame(['subject', 'start', 'end'], $this->toolsByName()['create_event']['inputSchema']['required']);
    }

    public function testUpdateEventRequiredKeys(): void {
        $this->assertSame(['id'], $this->toolsByName()['update_event']['inputSchema']['required']);
    }

    // ---- callBrokerTool: send_mail ------------------------------------------------

    public function testSendMailBuildsDraftThenSendRequest(): void {
        $c = $this->connector();
        $c->queueJson(201, ['id' => 'draft-1', 'conversationId' => 'conv-1']);
        $c->queueEmpty(202);

        $result = $c->callBrokerTool('send_mail', null, 'tok', [
            'to'        => 'lead@example.com',
            'to_name'   => 'Lead Person',
            'cc'        => 'cc1@example.com, not-an-email, cc2@example.com',
            'subject'   => 'Hello',
            'body_html' => '<p>Hi</p>',
        ]);

        $this->assertCount(2, $c->requests);

        $draftReq = $c->requests[0];
        $this->assertSame('POST', $draftReq['method']);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/messages', $draftReq['url']);
        $draftBody = json_decode((string) $draftReq['opts']['body'], true);
        $this->assertSame('Hello', $draftBody['subject']);
        $this->assertSame('<p>Hi</p>', $draftBody['body']['content']);
        $this->assertSame('HTML', $draftBody['body']['contentType']);
        $this->assertSame('lead@example.com', $draftBody['toRecipients'][0]['emailAddress']['address']);
        $this->assertSame('Lead Person', $draftBody['toRecipients'][0]['emailAddress']['name']);
        $this->assertSame(
            ['cc1@example.com', 'cc2@example.com'],
            array_column(array_column($draftBody['ccRecipients'], 'emailAddress'), 'address')
        );

        $sendReq = $c->requests[1];
        $this->assertSame('POST', $sendReq['method']);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/messages/draft-1/send', $sendReq['url']);

        $this->assertTrue($result['ok']);
        $this->assertSame('draft-1', $result['body']['message_id']);
        $this->assertSame('conv-1', $result['body']['conversation_id']);
    }

    public function testSendMailThreadedReplyUsesCreateReply(): void {
        $c = $this->connector();
        $c->queueJson(201, ['id' => 'reply-draft-1', 'conversationId' => 'conv-9']);
        $c->queueEmpty(202);

        $result = $c->callBrokerTool('send_mail', null, 'tok', [
            'to'                  => 'lead@example.com',
            'subject'             => 'Re: Hello',
            'body_html'           => '<p>Following up</p>',
            'in_reply_to_message' => 'orig-msg-1',
        ]);

        $this->assertCount(2, $c->requests);

        $replyReq = $c->requests[0];
        $this->assertSame('POST', $replyReq['method']);
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/messages/orig-msg-1/createReply', $replyReq['url']
        );
        $replyBody = json_decode((string) $replyReq['opts']['body'], true);
        $this->assertSame('<p>Following up</p>', $replyBody['comment']);

        $sendReq = $c->requests[1];
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/messages/reply-draft-1/send', $sendReq['url']
        );

        $this->assertSame('orig-msg-1', $result['body']['in_reply_to']);
        $this->assertSame('reply-draft-1', $result['body']['message_id']);
        $this->assertSame('conv-9', $result['body']['conversation_id']);
    }

    public function testSendMailInvalidRecipientThrows(): void {
        $c = $this->connector();
        $this->expectExceptionMessage('valid recipient address');
        $c->callBrokerTool('send_mail', null, 'tok', [
            'to' => 'not-an-email', 'subject' => 'x', 'body_html' => 'x',
        ]);
        $this->assertSame([], $c->requests);
    }

    // ---- callBrokerTool: list_messages / get_message ------------------------------

    public function testListMessagesBuildsInboxQuery(): void {
        $c = $this->connector();
        $c->queueJson(200, ['value' => []]);

        $c->callBrokerTool('list_messages', null, 'tok', ['since' => '2026-01-01T00:00:00Z', 'top' => 10]);

        $req = $c->requests[0];
        $this->assertSame('GET', $req['method']);
        $this->assertStringStartsWith('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?', $req['url']);
        $query = [];
        parse_str((string) parse_url($req['url'], PHP_URL_QUERY), $query);
        $this->assertSame('10', $query['$top']);
        $this->assertSame('receivedDateTime desc', $query['$orderby']);
        $this->assertSame('receivedDateTime ge 2026-01-01T00:00:00Z', $query['$filter']);
    }

    public function testGetMessageBuildsMessageUrl(): void {
        $c = $this->connector();
        $c->queueJson(200, ['id' => 'msg-1']);

        $c->callBrokerTool('get_message', null, 'tok', ['id' => 'msg-1']);

        $req = $c->requests[0];
        $this->assertSame('GET', $req['method']);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/messages/msg-1', $req['url']);
    }

    public function testGetMessageMissingIdThrows(): void {
        $c = $this->connector();
        $this->expectExceptionMessage('message id is required');
        $c->callBrokerTool('get_message', null, 'tok', []);
    }

    // ---- callBrokerTool: calendar tools --------------------------------------------

    public function testListEventsBuildsCalendarViewQuery(): void {
        $c = $this->connector();
        $c->queueJson(200, ['value' => []]);

        $c->callBrokerTool('list_events', null, 'tok', [
            'start' => '2026-01-01T00:00:00Z', 'end' => '2026-01-02T00:00:00Z',
        ]);

        $req = $c->requests[0];
        $this->assertSame('GET', $req['method']);
        $this->assertStringStartsWith('https://graph.microsoft.com/v1.0/me/calendarView?', $req['url']);
        $query = [];
        parse_str((string) parse_url($req['url'], PHP_URL_QUERY), $query);
        $this->assertSame('2026-01-01T00:00:00Z', $query['startDateTime']);
        $this->assertSame('2026-01-02T00:00:00Z', $query['endDateTime']);
    }

    public function testCreateEventBuildsPayload(): void {
        $c = $this->connector();
        $c->queueJson(201, ['id' => 'evt-1']);

        $c->callBrokerTool('create_event', null, 'tok', [
            'subject'        => 'Intro call',
            'start'          => '2026-02-01T15:00:00Z',
            'end'            => '2026-02-01T15:30:00Z',
            'body_html'      => '<p>Zoom link</p>',
            'attendee_email' => 'guest@example.com',
            'attendee_name'  => 'Guest',
        ]);

        $req = $c->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/events', $req['url']);
        $body = json_decode((string) $req['opts']['body'], true);
        $this->assertSame('Intro call', $body['subject']);
        $this->assertSame('2026-02-01T15:00:00Z', $body['start']['dateTime']);
        $this->assertSame('UTC', $body['start']['timeZone']);
        $this->assertSame('guest@example.com', $body['attendees'][0]['emailAddress']['address']);
        $this->assertSame('required', $body['attendees'][0]['type']);
    }

    public function testCreateEventMissingFieldsThrows(): void {
        $c = $this->connector();
        $this->expectExceptionMessage('subject, start and end are required');
        $c->callBrokerTool('create_event', null, 'tok', ['subject' => 'x']);
    }

    public function testUpdateEventBuildsPartialPayload(): void {
        $c = $this->connector();
        $c->queueJson(200, ['id' => 'evt-1']);

        $c->callBrokerTool('update_event', null, 'tok', [
            'id'      => 'evt-1',
            'subject' => 'New title',
        ]);

        $req = $c->requests[0];
        $this->assertSame('PATCH', $req['method']);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/events/evt-1', $req['url']);
        $body = json_decode((string) $req['opts']['body'], true);
        $this->assertSame(['subject' => 'New title'], $body);
    }

    public function testUpdateEventNothingToUpdateThrows(): void {
        $c = $this->connector();
        $this->expectExceptionMessage('Nothing to update');
        $c->callBrokerTool('update_event', null, 'tok', ['id' => 'evt-1']);
    }

    public function testUpdateEventMissingIdThrows(): void {
        $c = $this->connector();
        $this->expectExceptionMessage('event id is required');
        $c->callBrokerTool('update_event', null, 'tok', []);
    }

    // ---- unknown tool -------------------------------------------------------------

    public function testUnknownToolThrowsLoudly(): void {
        $c = $this->connector();
        $this->expectExceptionMessage("Unknown microsoft tool 'frobnicate'.");
        $c->callBrokerTool('frobnicate', null, 'tok', []);
        $this->assertSame([], $c->requests);
    }
}
