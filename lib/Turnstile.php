<?php
/**
 * Turnstile — Cloudflare Turnstile bot-challenge verification.
 *
 * A low-friction, mostly-invisible challenge on the registration form: the widget produces a
 * token the client submits, and this verifies it server-side against Cloudflare. The point is
 * to stop automated signups abusing the free tier's build agent.
 *
 * Config (conf/config.ini):
 *   [turnstile]
 *   site_key   = "0x..."   ; public — rendered into the form
 *   secret_key = "0x..."   ; private — used only here, server-side
 *
 * Gated on config PRESENCE: with no keys, verification is a no-op (the feature is simply off,
 * the same way RateLimiter is off without APCu), so registration keeps working before the keys
 * are set. Once both keys are present it is enforced. A configured-but-failing check fails
 * CLOSED (rejects the signup) — the safe default for an anti-abuse gate; Turnstile is highly
 * available and the user can retry.
 *
 * Swap note: Friendly Captcha (or hCaptcha) is the same shape — change SITEVERIFY and the field
 * name and this class ports over.
 */

namespace app;

use \Flight as Flight;

class Turnstile
{
    private const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const WIDGET_JS  = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    /** The form field the Turnstile widget writes its token into. */
    public const FIELD = 'cf-turnstile-response';

    /**
     * Render the Turnstile widget for a form — the builder primitive. Drop it inside any
     * <form> and the widget injects the {@see FIELD} token the form submits; pair it with a
     * server-side {@see verify()} call in the handler. Returns '' when not configured, so a
     * template can call it unconditionally.
     *
     * $opts: callback / expired_callback / error_callback (JS function names),
     *        theme ('auto'|'light'|'dark'), size ('normal'|'flexible'|'compact'),
     *        action, class (extra CSS classes), no_script (skip the <script> tag when the
     *        page already loaded api.js once).
     */
    public static function widget(array $opts = []): string
    {
        $key = self::siteKey();
        if ($key === '') return '';
        $esc  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $attr = 'data-sitekey="' . $esc($key) . '"';
        foreach ([
            'callback'         => 'data-callback',
            'expired_callback' => 'data-expired-callback',
            'error_callback'   => 'data-error-callback',
            'theme'            => 'data-theme',
            'size'             => 'data-size',
            'action'           => 'data-action',
        ] as $o => $a) {
            if (!empty($opts[$o])) $attr .= ' ' . $a . '="' . $esc($opts[$o]) . '"';
        }
        $cls  = 'cf-turnstile' . (!empty($opts['class']) ? ' ' . $esc($opts['class']) : '');
        $html = '<div class="' . $cls . '" ' . $attr . '></div>';
        if (empty($opts['no_script'])) {
            $html .= '<script src="' . self::WIDGET_JS . '" async defer></script>';
        }
        return $html;
    }

    public static function siteKey(): string   { return trim((string) (Flight::get('turnstile.site_key')   ?? '')); }
    private static function secretKey(): string { return trim((string) (Flight::get('turnstile.secret_key') ?? '')); }

    /** Enforced only when BOTH keys are configured. */
    public static function enabled(): bool
    {
        return self::siteKey() !== '' && self::secretKey() !== '';
    }

    /**
     * Verify a token from the widget. Returns true when the challenge passed — or when the
     * feature is not configured (no-op). Returns false for a missing/invalid token, or when the
     * verification call itself fails (fail closed).
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (!self::enabled()) return true;                 // not configured → feature off
        $token = trim((string) $token);
        if ($token === '') return false;

        $ch = curl_init(self::SITEVERIFY);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query(array_filter([
                'secret'   => self::secretKey(),
                'response' => $token,
                'remoteip' => $ip,
            ], fn($v) => $v !== null && $v !== '')),
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // NB: no curl_close() — it is deprecated and THROWS in the 8.5 web handler (bare 404).

        if ($body === false || $code !== 200) {
            Flight::get('log')?->error('Turnstile siteverify failed (rejecting)', ['http' => $code, 'err' => $err]);
            return false;                                  // fail closed
        }
        $data = json_decode((string) $body, true);
        return is_array($data) && !empty($data['success']);
    }
}
