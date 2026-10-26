<?php
namespace app\concepts\messaging;

/**
 * Events — what happens to a message, as this app's own event. The service tells the app (one signed POST per
 * event to /msghook/event, controls/Msghook.php) and the app fires it through AppEvents, so a pipeline starts on it:
 *
 *     "trigger": { "event": "message.replied" }        message.delivered | opened | clicked | replied | unsubscribed | bounced | complained
 *
 * with {context.member_id}, {context.campaign_id}, {context.campaign_code}, {context.subject}, {context.send},
 * {context.kind}, {context.at}, {context.meta.*} (a click's link number, a reply's from and subject).
 *
 * The signature is HMAC-SHA256 over "<t>.<body>" with the site's hook key — the secret in the site's Mailgun webhook
 * address, read once from GET sites/<host> and kept in a setting; a signature that does not match is checked once
 * more against a freshly read key (the key may have been rotated), then refused.
 */
class Events {

    public const SETTING = 'messaging_hook_key:';
    public const FRESH = 600;
    public const NAME_RE = '/^message\.(delivered|opened|clicked|replied|unsubscribed|bounced|complained)$/D';

    /** Why the signed body is refused, or null when it is the service's and fresh. */
    public static function verify(string $raw, string $signature, string $key, ?int $now = null): ?string {
        if ($key === '') return 'no hook key for this site';
        if (!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', trim($signature), $m)) return 'no signature (X-Messaging-Signature: t=<time>,v1=<hmac>)';
        if (abs(($now ?? time()) - (int) $m[1]) > self::FRESH) return 'stale signature';
        if (!hash_equals(hash_hmac('sha256', $m[1] . '.' . $raw, $key), $m[2])) return 'signature does not match';
        return null;
    }

    /** This site's hook key: the setting, else read from the service (and kept). $refresh reads it again. */
    public static function hookKey(bool $refresh = false): string {
        $host = Client::site();
        $key = $refresh ? '' : trim((string) \Flight::getSetting(self::SETTING . $host, 0));
        if ($key !== '') return $key;
        $url = (string) (Client::get('sites/' . rawurlencode($host))['site']['webhook_url'] ?? '');
        $key = trim((string) basename(parse_url($url, PHP_URL_PATH) ?: ''));
        if ($key === '') throw new \RuntimeException("the service gave no webhook address for {$host}: its hook key cannot be read");
        \Flight::setSetting(self::SETTING . $host, $key, 0);
        return $key;
    }

    /** The context a pipeline gets, from what the service posted. @throws \InvalidArgumentException */
    public static function context(array $p): array {
        $event = (string) ($p['event'] ?? '');
        if (!preg_match(self::NAME_RE, $event)) throw new \InvalidArgumentException("event: '{$event}' is not one of the message events");
        $c = is_array($p['campaign'] ?? null) ? $p['campaign'] : [];
        if ((string) ($p['member_id'] ?? '') === '') throw new \InvalidArgumentException('member_id: missing');
        return [
            'member_id' => (int) $p['member_id'], 'campaign_id' => (int) ($c['id'] ?? 0), 'campaign_code' => (string) ($c['code'] ?? ''),
            'subject' => (string) ($c['subject'] ?? ''), 'send' => (int) ($p['send'] ?? 0), 'kind' => (string) ($p['kind'] ?? ''),
            'at' => (string) ($p['at'] ?? ''), 'meta' => is_array($p['meta'] ?? null) ? $p['meta'] : [],
        ];
    }

    /** Fire the event: every pipeline whose trigger names it starts. @return array<int,array{slug:string,run_id:?int,error:?string}> */
    public static function fire(array $p): array {
        $context = self::context($p);
        if (!class_exists('\\app\\AppEvents')) throw new \RuntimeException('this runtime has no AppEvents (alpha.229 or newer): message events cannot start a pipeline');
        return \app\AppEvents::fire((string) $p['event'], $context);
    }
}
