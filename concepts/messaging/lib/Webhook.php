<?php
namespace app\concepts\messaging;

use app\Bean;
use app\Mailer;

/**
 * What Mailgun tells us about campaign mail: delivery events and replies to reply-{token}@ the reply domain (setting messaging_reply_domain).
 *
 * The runtime's controls/Webhook.php already owns POST /webhook/mailgun (for the Communications threads) and cannot be
 * extended or given a second route, so the app's lib/app.php calls intercept() from its start hook, before routing: a request that is about a msgsend
 * (or carries the `key` parameter) is answered here; every other request goes on to the runtime's own handler untouched.
 *
 * It authenticates itself, in both ways, and a request that fails either is a 403:
 *   - Mailgun's signature: HMAC-SHA256 of timestamp + token with the Mailgun connection's signing key (Mailer::settings());
 *   - the `key` query parameter, equal to the setting `mailgun_webhook_key` (the owner types it on the admin settings page, the
 *     same value as in the Mailgun route's URL; it is in no file).
 */
class Webhook {

    // nav: none — Mailgun calls this

    public const SETTING = 'mailgun_webhook_key';
    public const FRESH = 300;

    /** Called before routing. Answers and exits when the request is ours. */
    public static function intercept(): void {
        $path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if ($path !== '/webhook/mailgun' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        $raw = (string) file_get_contents('php://input');
        $in = stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false ? self::fromJson($raw) : self::fromForm($_POST);
        $key = (string) ($_GET['key'] ?? '');
        if (!self::isOurs($in, $key !== '')) return;
        try {
            $secret = trim((string) \Flight::getSetting(self::SETTING, 0));
            $signing = Mailer::settings()['signing_key'];
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('ERROR Webhook: ' . $e->getMessage());
            \Flight::jsonHalt(['accepted' => false, 'reason' => 'mail is not connected here'], 503);
            return;
        }
        [$code, $body] = self::process($in, $key, $secret, $signing);
        if ($code === null) return;   // verified, but about nothing of ours: the runtime's handler takes it
        \Flight::jsonHalt($body, $code);
    }

    /**
     * @param array $in   normalised request (fromJson / fromForm)
     * @return array{0:?int,1:array} status and body; status null = verified, nothing of ours in it
     */
    public static function process(array $in, string $key, string $settingKey, string $signingKey): array {
        $log = \Flight::get('log');
        if ($settingKey === '') {
            $log?->error('ERROR Webhook: the setting ' . self::SETTING . ' is empty — enter it on the admin settings page, the same value as in the Mailgun route\'s URL (?key=…). Refusing everything.');
            return [503, ['accepted' => false, 'reason' => self::SETTING . ' is not set']];
        }
        if ($signingKey === '') {
            $log?->error('ERROR Webhook: the Mailgun connection has no signing key — set it under Connections → Mailgun. Refusing everything.');
            return [503, ['accepted' => false, 'reason' => 'no signing key on the Mailgun connection']];
        }
        if ($key === '' || !hash_equals($settingKey, $key)) {
            $log?->warning('Webhook: wrong or missing key parameter');
            return [403, ['accepted' => false, 'reason' => 'bad key']];
        }
        $expected = hash_hmac('sha256', $in['timestamp'] . $in['token'], $signingKey);
        if ($in['signature'] === '' || !hash_equals($expected, $in['signature'])) {
            $log?->warning('Webhook: signature does not match');
            return [403, ['accepted' => false, 'reason' => 'bad signature']];
        }
        if (abs(time() - (int) $in['timestamp']) > self::FRESH) {
            $log?->warning('Webhook: stale timestamp');
            return [403, ['accepted' => false, 'reason' => 'stale']];
        }
        $send = self::sendOf($in);
        if (!$send) return [null, []];
        return $in['inbound'] ? self::reply($send, $in) : self::event($send, $in);
    }

    /** Is this request about campaign mail (so the runtime's handler must not see it first)? */
    public static function isOurs(array $in, bool $hasKey): bool {
        return $hasKey || self::sendOf($in) !== null;
    }

    private static function fromJson(string $raw): array {
        $p = json_decode($raw, true);
        if (!is_array($p)) return self::blank();
        $d = is_array($p['event-data'] ?? null) ? $p['event-data'] : [];
        $s = is_array($p['signature'] ?? null) ? $p['signature'] : [];
        return ['timestamp' => (string) ($s['timestamp'] ?? ''), 'token' => (string) ($s['token'] ?? ''), 'signature' => (string) ($s['signature'] ?? ''),
            'event' => strtolower((string) ($d['event'] ?? '')), 'severity' => strtolower((string) ($d['severity'] ?? '')),
            'eid' => trim((string) ($d['message']['headers']['message-id'] ?? ''), '<> '), 'recipient' => strtolower((string) ($d['recipient'] ?? '')),
            'inbound' => false, 'from' => '', 'subject' => '', 'text' => '', 'message_id' => ''];
    }

    private static function fromForm(array $f): array {
        $recipient = strtolower(trim((string) ($f['recipient'] ?? '')));
        $inbound = $recipient !== '' && (isset($f['body-plain']) || isset($f['stripped-text']));
        return ['timestamp' => (string) ($f['timestamp'] ?? ''), 'token' => (string) ($f['token'] ?? ''), 'signature' => (string) ($f['signature'] ?? ''),
            'event' => strtolower((string) ($f['event'] ?? '')), 'severity' => strtolower((string) ($f['severity'] ?? '')),
            'eid' => trim((string) ($f['Message-Id'] ?? $f['message-id'] ?? ''), '<> '), 'recipient' => $recipient,
            'inbound' => $inbound, 'from' => trim((string) ($f['from'] ?? $f['sender'] ?? '')), 'subject' => trim((string) ($f['subject'] ?? '')),
            'text' => trim((string) (($f['stripped-text'] ?? '') !== '' ? $f['stripped-text'] : ($f['body-plain'] ?? ''))),
            'message_id' => trim((string) ($f['Message-Id'] ?? $f['message-id'] ?? ''), '<> ')];
    }

    private static function blank(): array {
        return ['timestamp' => '', 'token' => '', 'signature' => '', 'event' => '', 'severity' => '', 'eid' => '', 'recipient' => '',
            'inbound' => false, 'from' => '', 'subject' => '', 'text' => '', 'message_id' => ''];
    }

    /** The send this request is about: a reply's token, else an event's message id. */
    private static function sendOf(array $in) {
        if (preg_match('/reply-([a-f0-9]{32})@/i', $in['recipient'], $m)) return \Model_Msgsend::byToken(strtolower($m[1]));
        if ($in['eid'] !== '') return Bean::findOne('msgsend', 'provider_eid = ?', [$in['eid']]);
        return null;
    }

    private static function event($send, array $in): array {
        $now = date('Y-m-d H:i:s');
        $email = $in['recipient'] !== '' ? $in['recipient'] : strtolower((string) Bean::getCell('SELECT email FROM member WHERE id = ?', [(int) $send->memberId]));
        $hard = in_array($in['event'], ['bounced', 'permanent_fail', 'rejected'], true) || ($in['event'] === 'failed' && $in['severity'] !== 'temporary');
        if ($in['event'] === 'delivered') {
            if (!$send->deliveredAt) { $send->deliveredAt = $now; Bean::store($send); }
        } elseif ($hard) {
            if (!$send->bouncedAt) { $send->bouncedAt = $now; Bean::store($send); }
            \Model_Msgsuppression::add($email, 'bounced');
        } elseif ($in['event'] === 'complained') {
            if (!$send->complainedAt) { $send->complainedAt = $now; Bean::store($send); }
            \Model_Msgsuppression::add($email, 'complained');
        } elseif ($in['event'] === 'unsubscribed') {
            \Model_Msgsuppression::add($email, 'unsubscribed_all');
        } else {
            return [200, ['accepted' => true, 'ignored' => $in['event']]];
        }
        return [200, ['accepted' => true, 'send' => (int) $send->id, 'event' => $in['event']]];
    }

    /** A reply: recorded on the send, and emailed to the campaign's sender so it lands in their own inbox. */
    private static function reply($send, array $in): array {
        // a retry (after a failed forward) must not record the reply twice, but must forward it again
        $dupe = $in['message_id'] !== '' && Bean::count('msgevent', "msgsend_id = ? AND kind = 'reply' AND meta LIKE ?", [(int) $send->id, '%' . json_encode($in['message_id']) . '%']) > 0;
        if (!$dupe) \Model_Msgevent::record($send, 'reply', ['from' => $in['from'], 'subject' => $in['subject'], 'message_id' => $in['message_id']]);
        $campaign = Bean::load('msgcampaign', (int) $send->msgcampaignId);
        $owner = Bean::load('member', (int) $campaign->senderId);
        if (!$owner->id || (string) $owner->email === '') {
            \Flight::get('log')?->error("ERROR Webhook: campaign #{$campaign->id} has no sender with an email address — the reply from {$in['from']} was recorded but not forwarded");
            return [200, ['accepted' => true, 'forwarded' => false]];
        }
        $subject = Campaigns::render($campaign, (int) $send->memberId)['subject'];
        $html = '<p><strong>' . htmlspecialchars($in['from']) . '</strong> replied to “' . htmlspecialchars($subject) . '”:</p><blockquote>'
            . nl2br(htmlspecialchars($in['text'])) . '</blockquote>';
        $ok = Mailer::create()->to((string) $owner->email, trim((string) $owner->firstName))
            ->subject('Reply to “' . $subject . '” from ' . $in['from'])
            ->send($html, "{$in['from']} replied to \"{$subject}\":\n\n{$in['text']}");
        if (!$ok) return [500, ['accepted' => false, 'reason' => 'the reply was recorded but could not be forwarded']];   // Mailgun retries; the event is not doubled
        return [200, ['accepted' => true, 'forwarded' => true]];
    }
}
