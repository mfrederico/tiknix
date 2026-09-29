<?php
/**
 * CreditAlert — when a pipeline agent step fails because the instance's AI-provider account is
 * out of credit ("Credit balance is too low", etc.), flag the instance (a setting the UI shows
 * as a banner) and email the instance's admins ONCE per day until it clears. The agent step
 * calls raise() on that specific failure and clear() after any successful agent run, so the
 * alert resolves itself once a working key is in place.
 *
 * All in-instance: the flag is a system setting, the email uses the instance's own mailgun.ini,
 * and admins are the instance's own admin members — so it reaches whoever runs this instance.
 */
namespace app;

use \Flight as Flight;

class CreditAlert {

    private const SETTING    = 'agent_credit_alert';         // the indicator flag (JSON, '' = clear)
    private const MAILMARK   = 'agent_credit_alert_mailed';  // last-email unix ts (dedupe)
    private const MAIL_EVERY = 86400;                        // at most one email/day while unresolved

    /** True when $text is a provider out-of-credit / billing failure (not a generic error). */
    public static function looksLikeCredit(string $text): bool {
        $t = strtolower($text);
        foreach ([
            'credit balance is too low', 'your credit balance', 'insufficient credit',
            'insufficient_quota', 'billing_hard_limit', 'payment required', 'purchase more credits',
        ] as $p) {
            if (strpos($t, $p) !== false) return true;
        }
        return false;
    }

    /** Flag the instance (idempotent) and email admins at most once per MAIL_EVERY. */
    public static function raise(string $engine, string $detail): void {
        try {
            $now = time();
            Flight::setSetting(self::SETTING, json_encode([
                'engine' => $engine,
                'detail' => mb_substr(trim($detail), 0, 300),
                'at'     => date('c', $now),
            ], JSON_UNESCAPED_SLASHES), 0);

            $last = (int) (Flight::getSetting(self::MAILMARK, 0) ?? 0);
            if ($now - $last < self::MAIL_EVERY) return;   // emailed recently — don't spam
            Flight::setSetting(self::MAILMARK, (string) $now, 0);
            self::emailAdmins($engine);
        } catch (\Throwable $e) {
            @error_log('CreditAlert::raise failed: ' . $e->getMessage());
        }
    }

    /** Clear the flag after a successful agent run (banner + email-dedupe both reset). */
    public static function clear(): void {
        try {
            if ((string) (Flight::getSetting(self::SETTING, 0) ?? '') !== '') {
                Flight::setSetting(self::SETTING, '', 0);
                Flight::setSetting(self::MAILMARK, '0', 0);
            }
        } catch (\Throwable $e) { /* best-effort */ }
    }

    /** The current alert payload (decoded) or null — for the UI banner. */
    public static function current(): ?array {
        try {
            $v = (string) (Flight::getSetting(self::SETTING, 0) ?? '');
            if ($v === '') return null;
            $d = json_decode($v, true);
            return is_array($d) ? $d : null;
        } catch (\Throwable $e) { return null; }
    }

    private static function emailAdmins(string $engine): void {
        try {
            $mailer = new Mailer();
            if (method_exists($mailer, 'isConfigured') && !$mailer->isConfigured()) {
                // isConfigured() may be private; the send() below no-ops+logs when unconfigured
            }
            $site  = Flight::siteName();
            $prov  = ($engine === 'claude' || $engine === 'zai') ? 'Anthropic' : ucfirst($engine);
            $html  = "<p>Your automated AI tasks on <strong>" . htmlspecialchars($site) . "</strong> are paused.</p>"
                   . "<p>The {$prov} account behind this site's API key has run out of credit, so AI steps "
                   . "(drafts, lead discovery, etc.) are failing with a billing error.</p>"
                   . "<p><strong>To resume:</strong> add credit to that {$prov} account, or replace the site's "
                   . "API key with one that has available credit. The tasks pick back up automatically once a "
                   . "working key is in place.</p>";

            $admins = Bean::find('member', 'level <= 50 ORDER BY level, id');
            foreach ($admins as $m) {
                $email = trim((string) ($m->email ?? ''));
                if ($email === '' || strpos($email, '@') === false) continue;
                (new Mailer())
                    ->to($email, (string) ($m->displayName ?? $m->username ?? ''))
                    ->subject("Action needed: {$site}'s AI credit is exhausted")
                    ->send($html);
            }
        } catch (\Throwable $e) {
            @error_log('CreditAlert::emailAdmins failed: ' . $e->getMessage());
        }
    }
}
