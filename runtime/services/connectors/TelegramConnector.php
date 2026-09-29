<?php
/**
 * Telegram — a bot as a two-way messaging connection.
 *
 * Registered automatically: ConnectorRegistry scans this directory, so this file
 * existing is what makes Telegram appear on /connections and /integrations. There
 * is no list to add it to.
 *
 * Auth is api_key, not OAuth. Telegram has no OAuth app for bots — you create a
 * bot with @BotFather and it hands you a token, and that token IS the credential.
 * So isConfigured() is always true: unlike Shopify or Stripe there is nothing for
 * the operator of THIS install to register first, which means a customer can
 * connect Telegram on a fresh instance with nothing pre-arranged.
 *
 * How the pieces map onto what Phase 0 built:
 *
 *   connections.access_token    the bot token
 *   connections.external_eid    the bot's numeric id      (what the far end calls it)
 *   connections.webhook_secret  the X-Telegram-Bot-Api-Secret-Token we require
 *   thread.external_ref         the chat id
 *   message.provider_id         the Telegram message id   (webhook-retry dedupe)
 *   externalidentity.external_eid  the sender's Telegram user id
 *
 * The bot token is a bearer credential that sits in the URL path of every API
 * call, so it is never logged here — errors report Telegram's description, not
 * the request.
 */

namespace app\services\connectors;

class TelegramConnector extends AbstractConnector {

    private const API = 'https://api.telegram.org/bot';

    public function key(): string {
        return 'telegram';
    }

    public function meta(): array {
        return [
            'label'     => 'Telegram',
            'auth_type' => 'api_key',
            'blurb'     => 'Connect a Telegram bot so messages from a chat or group arrive in '
                         . 'your inbox, and replies go back out. People messaging the bot need '
                         . 'no account here.',
            'category'  => 'Messaging',
            'icon'      => 'telegram',
            'color'     => 'info',
            'features'  => ['Two-way messages', 'Groups', 'No account needed'],
        ];
    }

    /**
     * Nothing for the install operator to configure — the bot token is the whole
     * credential. AbstractConnector's default demands an OAuth client_id/secret in
     * conf/telegram.ini, which would leave the card permanently greyed out.
     */
    public function isConfigured(): bool {
        return true;
    }

    // ---- connecting ------------------------------------------------------------------

    /**
     * Telegram bots have no OAuth flow at all — there is no consent screen to send
     * anybody to, and no code to exchange. Both are required by the interface, so
     * they refuse loudly rather than returning something empty that would surface
     * as a blank redirect nobody could diagnose.
     */
    public function authorizeUrl(array $ctx): string {
        throw new \Exception('Telegram connects with a bot token, not OAuth. '
                           . 'Create a bot with @BotFather and paste its token.');
    }

    public function exchangeCode(array $ctx): array {
        throw new \Exception('Telegram connects with a bot token, not OAuth.');
    }

    /**
     * Validate a bot token by asking Telegram who it belongs to.
     *
     * getMe is the cheapest authenticated call there is, so a bad token is
     * rejected while the person is still looking at the form rather than at the
     * first message that fails to arrive.
     *
     * @return array the payload Connections::upsertConnection stores
     */
    public function validateApiKey(string $key, array $opts = []): array {
        $token = trim($key);
        if ($token === '') {
            throw new \Exception('Paste the bot token @BotFather gave you.');
        }
        // Shape check before spending a round trip: "<digits>:<35-ish chars>".
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new \Exception('That does not look like a Telegram bot token. '
                               . 'It should look like 123456789:AA... — @BotFather can resend it.');
        }

        $me = $this->call($token, 'getMe');

        return [
            'access_token'  => $token,
            'token_type'    => 'bot',
            'scopes'        => '',
            'external_eid'  => (string) ($me['id'] ?? ''),
            'external_name' => (string) ($me['username'] ?? $me['first_name'] ?? 'Telegram bot'),
            'external_url'  => !empty($me['username']) ? 'https://t.me/' . $me['username'] : '',
        ];
    }

    // ---- webhook plumbing ------------------------------------------------------------

    /**
     * Point Telegram at this install and require a secret on every delivery.
     *
     * The secret comes back as X-Telegram-Bot-Api-Secret-Token on each POST, which
     * is what the receiving end checks. Without it the webhook URL is a public
     * endpoint anybody can post invented messages to — the URL contains a
     * connection id, not a secret.
     */
    public function setWebhook(string $token, string $url, string $secret): array {
        return $this->call($token, 'setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => json_encode(['message', 'edited_message']),
            // A backlog of updates from before the connection existed is not
            // history anybody asked to import.
            'drop_pending_updates' => 'true',
        ]);
    }

    public function deleteWebhook(string $token): array {
        return $this->call($token, 'deleteWebhook', ['drop_pending_updates' => 'true']);
    }

    /** What Telegram currently thinks it is delivering to — for the UI to show. */
    public function webhookInfo(string $token): array {
        return $this->call($token, 'getWebhookInfo');
    }

    // ---- messages --------------------------------------------------------------------

    /**
     * Send a message to a chat.
     *
     * HTML parse mode, because in-app messages are stored as HTML — but Telegram
     * accepts only a small tag subset and rejects the whole message on anything
     * else, so the caller passes text that has already been reduced. See
     * htmlToTelegram().
     */
    public function sendMessage(string $token, string $chatId, string $html, string $replyToMessageId = ''): array {
        $params = [
            'chat_id'    => $chatId,
            'text'       => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ];
        if ($replyToMessageId !== '') {
            $params['reply_parameters'] = json_encode([
                'message_id' => (int) $replyToMessageId,
                // A reply to a message somebody deleted should still send.
                'allow_sending_without_reply' => true,
            ]);
        }
        return $this->call($token, 'sendMessage', $params);
    }

    /**
     * Reduce stored HTML to the subset Telegram accepts.
     *
     * Telegram rejects an entire message for one unknown tag, so this strips to
     * the allowed set rather than hoping. Block tags become newlines first, or a
     * multi-paragraph reply arrives as one run-on line.
     */
    public static function htmlToTelegram(string $html): string {
        // Drop script/style CONTENT, not just their tags: strip_tags() keeps inner
        // text, so a stray <script>alert(1)</script> would arrive as the literal
        // words "alert(1)". Harmless in Telegram, which renders text, but it is
        // noise nobody sent.
        $s = preg_replace('~<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>~is', '', $html);
        $s = preg_replace('~<\s*br\s*/?\s*>~i', "\n", (string) $s);
        $s = preg_replace('~</\s*(p|div|li|h[1-6])\s*>~i', "\n", (string) $s);
        $s = preg_replace('~<\s*li[^>]*>~i', '• ', (string) $s);
        $s = strip_tags((string) $s, '<b><strong><i><em><u><s><code><pre><a>');
        $s = preg_replace("~\n{3,}~", "\n\n", (string) $s);
        return trim((string) $s);
    }

    /**
     * Normalise an inbound update into the shape the webhook works with, or null
     * if it is something we do not handle.
     *
     * Returns null for anything without a text body — stickers, joins, polls. They
     * are real events, but storing them as empty messages would fill an inbox with
     * blanks, and pretending to relay them would be worse.
     */
    public static function parseUpdate(array $update): ?array {
        $msg = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($msg)) return null;

        $text = (string) ($msg['text'] ?? $msg['caption'] ?? '');
        if (trim($text) === '') return null;

        $from = $msg['from'] ?? [];
        $chat = $msg['chat'] ?? [];
        if (empty($from['id']) || empty($chat['id'])) return null;

        $name = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));

        return [
            'chat_id'      => (string) $chat['id'],
            // Groups have a title; a one-to-one chat is named by the person.
            'chat_title'   => (string) ($chat['title'] ?? ($name !== '' ? $name : 'Telegram')),
            'chat_type'    => (string) ($chat['type'] ?? 'private'),
            'message_id'   => (string) ($msg['message_id'] ?? ''),
            'text'         => $text,
            'edited'       => isset($update['edited_message']),
            'from_eid'     => (string) $from['id'],
            'from_handle'  => (string) ($from['username'] ?? ''),
            'from_name'    => $name,
            'is_bot'       => !empty($from['is_bot']),
        ];
    }

    // ---- transport -------------------------------------------------------------------

    /**
     * One Telegram API call.
     *
     * Telegram answers 200 with {"ok":false} as readily as it answers 4xx, so the
     * body decides, not the status. Failures raise with Telegram's own description
     * because that text is the actually useful part ("chat not found", "bot was
     * blocked by the user") and inventing a friendlier one loses it.
     */
    private function call(string $token, string $method, array $params = []): array {
        [$status, $body] = $this->http(
            $params ? 'POST' : 'GET',
            self::API . $token . '/' . $method,
            [
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body'    => $params ? http_build_query($params) : null,
                'timeout' => 15,
            ]
        );

        $json = json_decode($body ?: '', true);
        if (!is_array($json)) {
            throw new \Exception('Telegram sent no usable answer to ' . $method
                               . ' (HTTP ' . $status . ').');
        }
        if (empty($json['ok'])) {
            throw new \Exception('Telegram refused ' . $method . ': '
                               . ($json['description'] ?? 'no reason given'));
        }

        $result = $json['result'] ?? true;
        return is_array($result) ? $result : ['result' => $result];
    }
}
