<?php
/**
 * Turnstile — Cloudflare Turnstile bot-challenge verification.
 *
 * A low-friction, mostly-invisible challenge on the registration form: the widget produces a
 * token the client submits, and this verifies it server-side against Cloudflare. The point is
 * to stop automated signups abusing the free tier's build agent.
 *
 * Keys, in precedence order (this install's own, whichever is set):
 *   1. A "turnstile" Connection in this install's own store (data/connections.db) — the
 *      managed source, set from the Connections page. Site key travels in metadata (public);
 *      secret key is encrypted with this install's key, like every other connection.
 *   2. conf/config.ini [turnstile] site_key / secret_key — the bootstrap seed/fallback, used
 *      only when no connection is stored. This is how a fresh install can carry keys before the
 *      UI is touched, and how core was configured before it was dogfooded onto the connection.
 *
 * Gated on key PRESENCE: with no keys from EITHER source, verification is a no-op (the feature
 * is simply off, the same way RateLimiter is off without APCu), so registration keeps working
 * before the keys are set. Once both keys are present it is enforced. A configured-but-failing
 * check fails CLOSED (rejects the signup) — the safe default for an anti-abuse gate; Turnstile
 * is highly available and the user can retry.
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

    public static function siteKey(): string
    {
        $c = self::connection();
        if (($c['site_key'] ?? '') !== '') return $c['site_key'];
        return trim((string) (Flight::get('turnstile.site_key') ?? ''));
    }

    private static function secretKey(): string
    {
        $c = self::connection();
        if (($c['secret_key'] ?? '') !== '') return $c['secret_key'];
        return trim((string) (Flight::get('turnstile.secret_key') ?? ''));
    }

    /** A stored secret this install can no longer decrypt (rotated app_key, corrupt row). */
    public static function secretBroken(): bool
    {
        return (bool) (self::connection()['secret_broken'] ?? false);
    }

    /**
     * Enforced when BOTH keys are configured (from a connection or config) — and ALSO when
     * a secret is stored but unreadable: that used to read as "not configured", which
     * switched the bot gate OFF on every form while the Connections page still showed it
     * as set. Enabled-but-broken means verify() refuses; the fix is on the Security card.
     */
    public static function enabled(): bool
    {
        if (self::secretBroken()) return true;
        return self::siteKey() !== '' && self::secretKey() !== '';
    }

    // --- this install's Turnstile "Connection" -----------------------------------
    //
    // Turnstile is consumed by THIS install's own web tier (the register page renders the
    // widget with the site key; doregister() verifies with the secret key), so its keys live
    // in this install's own connection store — never core's, never another instance's. Core
    // reading its own connection is exactly the dogfood: the same path every instance uses.

    /** Per-request memo: null = not looked up yet; [] = none stored; else the keys. */
    private static ?array $memo = null;

    /** This install's stored Turnstile keys, or [] when none is stored. */
    private static function connection(): array
    {
        if (self::$memo !== null) return self::$memo;
        $res = ConnectionStore::withOwnDb(function () {
            $c = Bean::findOne('connections', "connector_type = 'turnstile' AND enabled = 1 ORDER BY id DESC");
            if (!$c || !$c->id) return [];
            $meta = json_decode((string) ($c->metadataJson ?: '{}'), true) ?: [];
            $secret = ConnectionStore::ownToken($c);   // decrypts with this install's key; '' + ERROR log when it cannot
            // Stored-but-unreadable is a FAULT, kept apart from "no secret stored": the
            // former must fail closed (verify() refuses), the latter is the feature off.
            $stored = (string) ($c->accessToken ?? '') !== '' && (string) ($c->authType ?? '') !== ConnectionStore::AUTH_SEALED;
            return [
                'site_key'      => (string) ($meta['site_key'] ?? ''),
                'secret_key'    => $secret,
                'secret_broken' => $stored && $secret === '',
            ];
        }, []);
        return self::$memo = (is_array($res) ? $res : []);
    }

    /**
     * What the Connections UI needs to render the Security card, without ever handing the
     * secret to a view. `source` says which of the two key sources is live so the card can
     * tell "managed here" from "still on the config seed".
     */
    public static function state(): array
    {
        $c        = self::connection();
        $fromConn = ($c['site_key'] ?? '') !== '' && ($c['secret_key'] ?? '') !== '';
        $cfgSite  = trim((string) (Flight::get('turnstile.site_key')   ?? ''));
        $cfgSec   = trim((string) (Flight::get('turnstile.secret_key') ?? ''));
        $fromCfg  = $cfgSite !== '' && $cfgSec !== '';
        $site     = self::siteKey();
        return [
            'configured'  => self::enabled(),
            'broken'      => (bool) ($c['secret_broken'] ?? false),   // stored, unreadable: verification refuses
            'source'      => $fromConn ? 'connection' : ($fromCfg ? 'config' : 'none'),
            'site_masked' => $site === '' ? '' : (substr($site, 0, 6) . '…' . substr($site, -4)),
        ];
    }

    /**
     * Store this install's Turnstile keys as a connection. Validates the shape of both keys
     * and PROVES the secret against Cloudflare before persisting anything — a bad secret is
     * caught here rather than silently failing every signup later. Returns the connection id.
     */
    public static function save(string $siteKey, string $secretKey): int
    {
        $siteKey   = trim($siteKey);
        $secretKey = trim($secretKey);
        if (!preg_match('/^0x[A-Za-z0-9_-]{6,}$/', $siteKey)) {
            throw new \Exception('That does not look like a Turnstile site key (it should start with "0x").');
        }
        if (!preg_match('/^0x[A-Za-z0-9_-]{6,}$/', $secretKey)) {
            throw new \Exception('That does not look like a Turnstile secret key (it should start with "0x").');
        }
        self::probeSecret($secretKey);   // throws on a bad secret or an unreachable Cloudflare

        $id = ConnectionStore::put('turnstile', 'production', [
            'external_eid'  => substr($siteKey, 0, 24),
            'external_name' => 'Cloudflare Turnstile',
            'external_url'  => 'https://dash.cloudflare.com/?to=/:account/turnstile',
            'token_type'    => 'secret',
            'auth_type'     => 'api_key',
            'access_token'  => $secretKey,                 // encrypted at rest by put()
            'metadata'      => ['site_key' => $siteKey],   // public by design
        ]);
        self::$memo = null;   // the new keys take effect on THIS request
        return (int) $id;
    }

    /** Remove this install's stored Turnstile keys. Returns true when something was removed. */
    public static function forget(): bool
    {
        $gone = (bool) ConnectionStore::withOwnDb(function () {
            $any = false;
            foreach (Bean::find('connections', "connector_type = 'turnstile'") as $c) {
                if ($c->id) { Bean::trash($c); $any = true; }
            }
            return $any;
        }, false);
        self::$memo = null;
        return $gone;
    }

    /**
     * Prove a SECRET key against Cloudflare without a real widget token. siteverify with a
     * dummy response returns `invalid-input-secret` for a bad key, but a token-shaped error
     * (invalid-input-response, timeout-or-duplicate) when the secret itself is accepted — so
     * this can validate the secret alone. Throws on a bad key or if Cloudflare is unreachable.
     */
    public static function probeSecret(string $secret): void
    {
        $ch = curl_init(self::SITEVERIFY);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => 'tiknix-probe']),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // NB: no curl_close() — deprecated and THROWS in the 8.5 web handler.
        if ($body === false || $code !== 200) {
            throw new \Exception('Could not reach Cloudflare to validate the secret key — please try again.');
        }
        $data = json_decode((string) $body, true);
        $errs = (array) (is_array($data) ? ($data['error-codes'] ?? []) : []);
        if (in_array('invalid-input-secret', $errs, true) || in_array('missing-input-secret', $errs, true)) {
            throw new \Exception('Cloudflare rejected that secret key — check you pasted the Turnstile SECRET (not the site key).');
        }
    }

    /**
     * Verify a token from the widget. Returns true when the challenge passed — or when the
     * feature is not configured (no-op). Returns false for a missing/invalid token, or when the
     * verification call itself fails (fail closed).
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (!self::enabled()) return true;                 // not configured → feature off
        if (self::secretBroken()) {
            Flight::get('log')?->error('ERROR Turnstile: a secret is stored for this install but cannot be decrypted (rotated [security] app_key?). Verification REFUSED until it is re-saved on Connections → Security.');
            return false;
        }
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
