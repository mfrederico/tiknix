<?php
/**
 * Setup — what the messaging plugin changes in Mailgun's setup steps (app\ConnectorSetup) while it is on.
 *
 *   - "Let Messaging send for this site" moves up, right after the key and the from-address: the service makes the
 *     site when the connection is first shared, and the addresses below are the site's.
 *   - "Hear what happens to your mail": the address Mailgun calls is the SERVICE's (it keeps the ledger of who
 *     opened, clicked, bounced), not this app's /webhook/mailgun.
 *   - "Get replies back": the route forwards to both — this app's own inbox, and the service's (a reply to a
 *     message it sent).
 *
 * The service's address is asked once per site and kept (setting messaging_hook_url:<host>). Before the share it
 * does not exist: those steps say so instead of showing an address that would not work.
 * Registered by Providers::register().
 */

namespace app\concepts\messaging;

class Setup {

    public const SETTING = 'messaging_hook_url:';

    private static bool $on = false;

    public static function register(): void {
        if (self::$on) return;
        \app\ConnectorSetup::extend('mailgun', [self::class, 'mailgun']);
        self::$on = true;
    }

    /** @param array $steps Mailgun's steps @param array $ctx key, live, host */
    public static function mailgun(array $steps, array $ctx): array {
        $byId = [];
        foreach ($steps as $s) $byId[$s['id']] = $s;
        if (!isset($byId['share'])) return $steps;   // its step is declared with when: concept:messaging

        $host = (string) $ctx['host'];
        $shared = is_array($ctx['live']['shared']['messaging'] ?? null) && strtolower((string) ($ctx['live']['shared']['messaging']['site'] ?? '')) === strtolower($host);
        $url = ''; $problem = '';
        if ($shared) {
            try { $url = self::hookUrl($host); }
            catch (\Throwable $e) {
                \Flight::get('log')->error('messaging: the service gave no webhook address for ' . $host . ': ' . $e->getMessage());
                $problem = 'The Messaging service did not give this site\'s address: ' . $e->getMessage();
            }
        } else {
            $problem = 'Share it with Messaging first (the step above). Then the address to paste appears here.';
        }
        foreach (['events', 'replies'] as $id) {
            if (!isset($byId[$id])) continue;
            if ($problem !== '') { $byId[$id]['copy'] = []; $byId[$id]['problem'] = $problem; continue; }
            if ($id === 'events') $byId[$id]['copy'] = [['label' => 'Webhook address', 'value' => $url]];
            else $byId[$id]['copy'] = [
                ['label' => 'Match', 'value' => 'match_recipient("reply-.*@{field.inbound_domain|field.domain}")'],
                ['label' => 'Forward to (this app)', 'value' => '{webhook}'],
                ['label' => 'And forward to (Messaging)', 'value' => $url],
            ];
        }
        if (isset($byId['events']) && $problem === '') $byId['events']['words'] = "In Mailgun, open Webhooks and add this address for delivered, opened, clicked, bounced and complained. Then paste Mailgun's webhook signing key here — Messaging gets it too.";
        if (isset($byId['replies']) && $problem === '') $byId['replies']['words'] = 'In Mailgun, open Receiving and add a route. When mail matches this, forward it to both addresses.';

        $order = [];
        foreach ($steps as $s) {
            if ($s['id'] === 'share') continue;
            $order[] = $byId[$s['id']];
            if ($s['id'] === 'from' || ($s['id'] === 'key' && !isset($byId['from']))) $order[] = $byId['share'];
        }
        return $order;
    }

    /** The service's address for this site's Mailgun calls, asked once and kept. @throws ServiceException|\RuntimeException */
    public static function hookUrl(string $host): string {
        $kept = trim((string) \Flight::getSetting(self::SETTING . $host, 0));
        if ($kept !== '') return $kept;
        $url = (string) (Client::get('sites/' . rawurlencode($host))['site']['webhook_url'] ?? '');
        if (!preg_match('#^https://#', $url)) throw new \RuntimeException("the service has no webhook address for {$host}");
        \Flight::setSetting(self::SETTING . $host, $url, 0);
        return $url;
    }
}
