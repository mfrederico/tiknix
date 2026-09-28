<?php
/**
 * MicrosoftConnector — Microsoft 365 / Outlook mailbox (Graph API).
 *
 * A connected mailbox is ALWAYS reached through the customer's own Azure AD app
 * registration, never a shared tiknix app — same reasoning as ShopifyConnector:
 * sending cold outreach email as someone's real mailbox is their credential, their
 * consent grant, their callback on their own domain. conf/microsoft.ini has no
 * [oauth] client_id/client_secret fallback for that reason; requiresOwnApp()
 * refuses to complete the flow without one rather than silently inventing a
 * shared identity nobody asked for.
 *
 * ACCESS TOKENS EXPIRE IN ~1 HOUR (like QuickBooks, unlike Shopify/Stripe).
 * refreshToken() is mandatory — a connection with no refresh path would work for
 * an hour and then fail silently mid-campaign.
 *
 * The `common` tenant endpoint is used so both work/school (Microsoft 365) and
 * personal (outlook.com/hotmail) mailboxes can connect — Graph's Mail.Send /
 * Mail.ReadWrite scopes work the same way against either.
 *
 * Requires the customer to register an app at portal.azure.com (Entra ID > App
 * registrations), add this instance's /connections/callback/microsoft as a
 * Web redirect URI, and grant delegated Graph permissions Mail.Send,
 * Mail.ReadWrite, User.Read, offline_access.
 */

namespace app\services\connectors;

use app\ConnectionStore;

class MicrosoftConnector extends AbstractConnector {

    private const AUTH_BASE  = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const TOKEN_URL  = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    public function key(): string { return 'microsoft'; }

    public function meta(): array {
        return [
            'label'     => 'Microsoft 365 / Outlook',
            'auth_type' => 'oauth',
            'blurb'     => 'Connect a Microsoft mailbox to send and receive outreach email as yourself.',
            'category'  => 'Email',
            'icon'      => 'envelope-at',
            'color'     => 'primary',
            'features'  => ['Send mail', 'Read replies'],
        ];
    }

    /**
     * A mailbox is always reached through the customer's own Azure AD app — see
     * the class docblock. No shared conf/microsoft.ini fallback.
     */
    public function requiresOwnApp(): bool { return true; }

    /** Graph scopes are dotted words (Mail.Send) or bare (offline_access) — space separated. */
    protected function scopeSeparator(): string { return ' '; }
    protected function scopePattern(): string { return '/^[A-Za-z][A-Za-z0-9_.]*$/'; }

    public function defaultScopes(): string {
        return (string) ($this->oauth()['scopes'] ?? 'offline_access User.Read Mail.Send Mail.ReadWrite Calendars.ReadWrite');
    }

    public function authorizeUrl(array $ctx): string {
        $o = $this->appFor($ctx);
        return self::AUTH_BASE . '?' . http_build_query([
            'client_id'     => (string) ($o['client_id'] ?? ''),
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope'         => $this->scopesFor($ctx),
            'redirect_uri'  => (string) ($ctx['redirect_uri'] ?? ''),
            'state'         => (string) ($ctx['state'] ?? ''),
        ]);
    }

    public function exchangeCode(array $ctx): array {
        $params = $ctx['params'] ?? [];

        if (!empty($params['error'])) {
            throw new \Exception('Microsoft authorization failed: '
                . (string) ($params['error_description'] ?? $params['error']));
        }
        $code = trim((string) ($params['code'] ?? ''));
        if ($code === '') throw new \Exception('Microsoft returned no authorization code.');

        $o = $this->appFor($ctx);
        $tok = $this->tokenRequest($o, [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => (string) ($ctx['redirect_uri'] ?? ''),
            'scope'        => $this->scopesFor($ctx),
        ]);

        // The mailbox address IS the identity here — fetched fresh rather than trusted
        // from the client, same reason QuickBooks re-fetches the company name.
        $me = $this->graphMe($tok['access_token']);
        $mailbox = trim((string) ($me['mail'] ?? $me['userPrincipalName'] ?? ''));
        if ($mailbox === '') {
            throw new \Exception('Could not determine the connected mailbox address from Microsoft Graph.');
        }

        return [
            'access_token'  => $tok['access_token'],
            'refresh_token' => $tok['refresh_token'],
            'expires_at'    => $tok['expires_at'],
            'token_type'    => 'Bearer',
            'scopes'        => $this->scopesFor($ctx),
            'external_eid'  => $mailbox,
            'external_name' => trim((string) ($me['displayName'] ?? '')) ?: $mailbox,
            'external_url'  => 'https://outlook.office.com/mail/',
            'metadata'      => [],
        ];
    }

    /**
     * Renew an expiring access token — authenticates AS THE APP THAT ISSUED IT, so
     * the customer's own app credentials must be on the connection (requiresOwnApp).
     */
    public function refreshToken($conn, string $token): ?array {
        $refresh = ConnectionStore::ownSecret($conn, 'refreshToken');
        if ($refresh === '') {
            throw new \Exception('This Microsoft connection has no refresh token — reconnect it.');
        }
        $app = ConnectionStore::ownApp($conn);
        if (!$app) {
            throw new \Exception('This Microsoft connection has no stored app credentials, '
                . 'so its token cannot be refreshed — the mailbox needs reconnecting.');
        }

        $tok = $this->tokenRequest($app, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'scope'         => (string) ($conn->scopes ?? $this->defaultScopes()),
        ]);

        return [
            'access_token'  => $tok['access_token'],
            'refresh_token' => $tok['refresh_token'],
            'expires_at'    => $tok['expires_at'],
        ];
    }

    // ---- broker tools ---------------------------------------------------------

    public function brokerTools(): array {
        return [
            [
                'name'        => 'send_mail',
                'description' => 'Send an email from the connected mailbox, optionally as a reply on an existing message.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'to'                  => ['type' => 'string', 'description' => 'Recipient email address.'],
                        'to_name'             => ['type' => 'string', 'description' => 'Optional recipient display name.'],
                        'cc'                  => ['type' => 'string', 'description' => 'Optional comma-separated cc email address(es).'],
                        'subject'             => ['type' => 'string', 'description' => 'Subject line.'],
                        'body_html'           => ['type' => 'string', 'description' => 'HTML message body.'],
                        'in_reply_to_message' => ['type' => 'string', 'description' => 'Optional Graph message id to reply to, keeping the conversation threaded.'],
                    ],
                    'required'   => ['to', 'subject', 'body_html'],
                ],
            ],
            [
                'name'        => 'list_messages',
                'description' => 'List recent messages in the mailbox inbox, with sender, subject, body and conversationId — used to poll for replies.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'since'    => ['type' => 'string', 'description' => 'ISO-8601 timestamp — only messages received after this.'],
                        'top'      => ['type' => 'integer', 'description' => 'Max messages to return, default 25.'],
                    ],
                ],
            ],
            [
                'name'        => 'get_message',
                'description' => 'Fetch one message by Graph message id.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'The Graph message id.'],
                    ],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'list_events',
                'description' => 'List calendar events in a date range — for the in-app calendar view. Requires Calendars.ReadWrite scope (a connection made before that scope existed needs to be reconnected).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'start' => ['type' => 'string', 'description' => 'ISO-8601 range start.'],
                        'end'   => ['type' => 'string', 'description' => 'ISO-8601 range end.'],
                    ],
                    'required'   => ['start', 'end'],
                ],
            ],
            [
                'name'        => 'create_event',
                'description' => 'Create a calendar event (a booked meeting), optionally inviting an attendee. Requires Calendars.ReadWrite scope.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'subject'          => ['type' => 'string', 'description' => 'Event title.'],
                        'start'            => ['type' => 'string', 'description' => 'ISO-8601 start time.'],
                        'end'              => ['type' => 'string', 'description' => 'ISO-8601 end time.'],
                        'body_html'        => ['type' => 'string', 'description' => 'Optional event description (e.g. a Zoom link).'],
                        'attendee_email'   => ['type' => 'string', 'description' => 'Optional attendee to invite.'],
                        'attendee_name'    => ['type' => 'string', 'description' => 'Optional attendee display name.'],
                    ],
                    'required'   => ['subject', 'start', 'end'],
                ],
            ],
            [
                'name'        => 'update_event',
                'description' => 'Modify an existing calendar event (subject/time/body) — a partial update, only the fields given are changed. Requires Calendars.ReadWrite scope.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'        => ['type' => 'string', 'description' => 'The Graph event id.'],
                        'subject'   => ['type' => 'string', 'description' => 'Optional new title.'],
                        'start'     => ['type' => 'string', 'description' => 'Optional new ISO-8601 start time.'],
                        'end'       => ['type' => 'string', 'description' => 'Optional new ISO-8601 end time.'],
                        'body_html' => ['type' => 'string', 'description' => 'Optional new description.'],
                    ],
                    'required'   => ['id'],
                ],
            ],
        ];
    }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        if ($tool === 'send_mail') {
            $to      = trim((string) ($args['to'] ?? ''));
            $subject = (string) ($args['subject'] ?? '');
            $html    = (string) ($args['body_html'] ?? '');
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('A valid recipient address is required.');
            }

            $replyTo = trim((string) ($args['in_reply_to_message'] ?? ''));
            if ($replyTo !== '') {
                // Graph threads a reply by construction (subject/references handled
                // server-side) — createReply then send keeps it on the same conversation.
                $draft = $this->call('POST', self::GRAPH_BASE . '/me/messages/' . rawurlencode($replyTo) . '/createReply', $token, json_encode([
                    'comment' => $html,
                ], JSON_UNESCAPED_SLASHES));
                $draftId = (string) ($draft['body']['id'] ?? '');
                if ($draftId === '') throw new \Exception('Microsoft Graph did not return a reply draft to send.');
                $conversationId = (string) ($draft['body']['conversationId'] ?? '');
                $this->call('POST', self::GRAPH_BASE . '/me/messages/' . rawurlencode($draftId) . '/send', $token, '');
                return ['status' => 202, 'ok' => true, 'body' => [
                    'sent' => true, 'in_reply_to' => $replyTo,
                    'message_id' => $draftId, 'conversation_id' => $conversationId,
                ]];
            }

            // Create-then-send (NOT the single-shot /me/sendMail) deliberately: sendMail
            // returns 202 with no body at all, so a fresh send would have no message id
            // or conversationId to record — and without a conversationId, an inbound
            // reply (t11's poll pipeline) has nothing reliable to match it back to this
            // thread with. Creating the draft first costs one extra call and gets us the
            // id we need before it ever leaves the mailbox.
            $ccAddresses = array_filter(array_map('trim', explode(',', (string) ($args['cc'] ?? ''))), fn($a) => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL));
            $payload = [
                'subject'      => $subject,
                'body'         => ['contentType' => 'HTML', 'content' => $html],
                'toRecipients' => [[
                    'emailAddress' => array_filter([
                        'address' => $to,
                        'name'    => trim((string) ($args['to_name'] ?? '')) ?: null,
                    ]),
                ]],
            ];
            if ($ccAddresses) {
                $payload['ccRecipients'] = array_map(fn($a) => ['emailAddress' => ['address' => $a]], array_values($ccAddresses));
            }
            $draft = $this->call('POST', self::GRAPH_BASE . '/me/messages', $token, json_encode($payload, JSON_UNESCAPED_SLASHES));
            $draftId = (string) ($draft['body']['id'] ?? '');
            if ($draftId === '') throw new \Exception('Microsoft Graph did not return a draft to send.');
            $conversationId = (string) ($draft['body']['conversationId'] ?? '');
            $this->call('POST', self::GRAPH_BASE . '/me/messages/' . rawurlencode($draftId) . '/send', $token, '');
            return ['status' => 202, 'ok' => true, 'body' => [
                'sent' => true, 'message_id' => $draftId, 'conversation_id' => $conversationId,
            ]];
        }

        if ($tool === 'list_messages') {
            $top   = max(1, min(100, (int) ($args['top'] ?? 25)));
            $query = [
                '$top'     => $top,
                '$orderby' => 'receivedDateTime desc',
                '$select'  => 'id,conversationId,subject,from,toRecipients,receivedDateTime,body',
            ];
            $since = trim((string) ($args['since'] ?? ''));
            if ($since !== '') $query['$filter'] = "receivedDateTime ge {$since}";
            $url = self::GRAPH_BASE . '/me/mailFolders/inbox/messages?' . http_build_query($query);
            return $this->call('GET', $url, $token, null);
        }

        if ($tool === 'get_message') {
            $id = trim((string) ($args['id'] ?? ''));
            if ($id === '') throw new \Exception('A message id is required.');
            return $this->call('GET', self::GRAPH_BASE . '/me/messages/' . rawurlencode($id), $token, null);
        }

        if ($tool === 'list_events') {
            $start = trim((string) ($args['start'] ?? ''));
            $end   = trim((string) ($args['end'] ?? ''));
            if ($start === '' || $end === '') throw new \Exception('A start and end are required.');
            $query = [
                'startDateTime' => $start,
                'endDateTime'   => $end,
                '$orderby'      => 'start/dateTime',
                '$top'          => 100,
                '$select'       => 'id,subject,start,end,organizer,attendees,isOnlineMeeting,onlineMeeting,bodyPreview,isCancelled',
            ];
            $url = self::GRAPH_BASE . '/me/calendarView?' . http_build_query($query);
            return $this->call('GET', $url, $token, null);
        }

        if ($tool === 'create_event') {
            $subject = (string) ($args['subject'] ?? '');
            $start   = trim((string) ($args['start'] ?? ''));
            $end     = trim((string) ($args['end'] ?? ''));
            if ($subject === '' || $start === '' || $end === '') {
                throw new \Exception('subject, start and end are required.');
            }
            $payload = [
                'subject' => $subject,
                'start'   => ['dateTime' => $start, 'timeZone' => 'UTC'],
                'end'     => ['dateTime' => $end, 'timeZone' => 'UTC'],
            ];
            $bodyHtml = trim((string) ($args['body_html'] ?? ''));
            if ($bodyHtml !== '') $payload['body'] = ['contentType' => 'HTML', 'content' => $bodyHtml];
            $attendeeEmail = trim((string) ($args['attendee_email'] ?? ''));
            if ($attendeeEmail !== '' && filter_var($attendeeEmail, FILTER_VALIDATE_EMAIL)) {
                $payload['attendees'] = [[
                    'emailAddress' => array_filter([
                        'address' => $attendeeEmail,
                        'name'    => trim((string) ($args['attendee_name'] ?? '')) ?: null,
                    ]),
                    'type' => 'required',
                ]];
            }
            return $this->call('POST', self::GRAPH_BASE . '/me/events', $token, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        if ($tool === 'update_event') {
            $id = trim((string) ($args['id'] ?? ''));
            if ($id === '') throw new \Exception('An event id is required.');
            $payload = [];
            $subject = trim((string) ($args['subject'] ?? ''));
            if ($subject !== '') $payload['subject'] = $subject;
            $start = trim((string) ($args['start'] ?? ''));
            if ($start !== '') $payload['start'] = ['dateTime' => $start, 'timeZone' => 'UTC'];
            $end = trim((string) ($args['end'] ?? ''));
            if ($end !== '') $payload['end'] = ['dateTime' => $end, 'timeZone' => 'UTC'];
            $bodyHtml = trim((string) ($args['body_html'] ?? ''));
            if ($bodyHtml !== '') $payload['body'] = ['contentType' => 'HTML', 'content' => $bodyHtml];
            if (!$payload) throw new \Exception('Nothing to update.');
            return $this->call('PATCH', self::GRAPH_BASE . '/me/events/' . rawurlencode($id), $token, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        throw new \Exception("Unknown microsoft tool '{$tool}'.");
    }

    // ---- internals --------------------------------------------------------

    /** One authenticated Graph call, with its error shape unwrapped. */
    private function call(string $method, string $url, string $token, ?string $body): array {
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json';

        [$status, $raw, $err] = $this->http($method, $url, ['headers' => $headers, 'body' => $body, 'timeout' => 30]);
        if ($err !== '') throw new \Exception('Microsoft Graph request failed: ' . $err);

        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $status);
            throw new \Exception('Microsoft Graph: ' . $msg);
        }
        return ['status' => $status, 'ok' => true, 'body' => $decoded !== null ? $decoded : $raw];
    }

    /** The signed-in mailbox's profile — mail address, UPN, display name. */
    private function graphMe(string $token): array {
        $r = $this->call('GET', self::GRAPH_BASE . '/me', $token, null);
        return is_array($r['body'] ?? null) ? $r['body'] : [];
    }

    /** POST to Microsoft's token endpoint; normalizes both grant types' replies. */
    private function tokenRequest(array $app, array $form): array {
        $id     = (string) ($app['client_id'] ?? '');
        $secret = (string) ($app['client_secret'] ?? '');
        if ($id === '' || $secret === '') {
            throw new \Exception('Microsoft is not configured for this connection — an Azure AD app client id and secret are required.');
        }

        [$status, $raw, $err] = $this->http('POST', self::TOKEN_URL, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            'body'    => http_build_query($form + ['client_id' => $id, 'client_secret' => $secret]),
            'timeout' => 20,
        ]);
        if ($err !== '') throw new \Exception('Could not reach Microsoft: ' . $err);

        $j = json_decode($raw, true) ?: [];
        if ($status < 200 || $status >= 300) {
            throw new \Exception('Microsoft rejected the token request: '
                . (string) ($j['error_description'] ?? $j['error'] ?? ('HTTP ' . $status)));
        }

        $access = (string) ($j['access_token'] ?? '');
        if ($access === '') throw new \Exception('Microsoft returned no access token.');

        return [
            'access_token'  => $access,
            'refresh_token' => (string) ($j['refresh_token'] ?? ''),
            'expires_at'    => time() + (int) ($j['expires_in'] ?? 3600),
        ];
    }
}
