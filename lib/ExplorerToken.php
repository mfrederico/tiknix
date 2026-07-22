<?php
/**
 * ExplorerToken — signed single-use handoff token for the Architecture Explorer SSO.
 *
 * Core (tiknix.com) mints a short-lived token proving {member_id, level, email,
 * feature grant}; the Explorer sidecar (explorer.tiknix.com) verifies it and
 * establishes its own session. The two are SEPARATE apps with different app_keys,
 * so the signature uses a DEDICATED shared secret (`conf/config.ini [explorer]
 * sso_secret`, mirrored into both configs) — the same "secret in each consumer's
 * config" pattern the AI Builder bridges use (`controls/Aibuilder::mintToken`,
 * envelope mechanics from `lib/OAuthStateService`).
 *
 * Envelope: b64url(json(claims)) . "." . hex(HMAC-SHA256(payload, secret)).
 * Claims always carry iat/exp/nonce/aud; `aud` separation means an explorer-bound
 * token can never be replayed against a core endpoint and vice versa. The nonce is
 * burned single-use by the verifier (replay → reject) — see Sso::consume.
 *
 * This file lands in CORE so the sidecar clone inherits it verbatim.
 */

namespace app;

class ExplorerToken {

    /** Default handoff lifetime (seconds) — deliberately tiny; it's a redirect hop. */
    public const TTL = 120;

    /**
     * Mint a signed token. $claims should carry at least member_id + level; iat,
     * exp, nonce, aud are added here. Returns "payload.sig".
     */
    public static function mint(array $claims, string $secret, string $aud, int $ttl = self::TTL): string {
        $claims['aud']   = $aud;
        $claims['iat']   = time();
        $claims['exp']   = time() + max(1, $ttl);
        $claims['nonce'] = bin2hex(random_bytes(16));
        $payload = self::b64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify a token against $secret and the expected audience. Returns the claims
     * array on success, or null on any failure (bad shape, bad sig, expired, wrong
     * aud). Constant-time signature compare. Does NOT check the nonce — the caller
     * burns it single-use so replay protection stays stateful at the boundary.
     */
    public static function verify(string $token, string $secret, string $expectedAud): ?array {
        if ($secret === '') return null;
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $sig)) return null;
        $json   = self::b64urlDecode($payload);
        $claims = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($claims)) return null;
        if (empty($claims['exp']) || time() > (int) $claims['exp']) return null;
        if (($claims['aud'] ?? null) !== $expectedAud) return null;
        if (empty($claims['nonce']) || empty($claims['member_id'])) return null;
        return $claims;
    }

    private static function b64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s) {
        return base64_decode(strtr($s, '-_', '+/'));
    }
}
