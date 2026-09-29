<?php
/**
 * OAuthStateService — tamper-proof OAuth `state` envelopes.
 *
 * The `state` carries the trust-critical claims of an OAuth handshake (which
 * member, which instance, which store, which environment) THROUGH the provider
 * round-trip. On callback we trust ONLY what the signature proves — never the raw
 * query params — so a token minted for store X can never be bound to a different
 * instance or member.
 *
 * Signed with an HMAC subkey derived from the control-plane app_key
 * (EncryptionService::deriveKey), so it is unforgeable without the master key.
 * This is deliberately NOT a domain-derived key (which anyone could recompute).
 */

namespace app;

class OAuthStateService {

    private const CONTEXT = 'oauth-state-v1';
    private const TTL     = 600; // 10 minutes

    /** Sign a claims array into a compact `payload.sig` token. */
    public static function issue(array $claims): string {
        $claims['iat']   = time();
        $claims['exp']   = time() + self::TTL;
        $claims['nonce'] = bin2hex(random_bytes(8));
        $payload = self::b64url((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig     = self::b64url(hash_hmac('sha256', $payload, self::key(), true));
        return $payload . '.' . $sig;
    }

    /** Verify a state token; returns the claims array, or null if invalid/expired. */
    public static function verify(string $state): ?array {
        $parts = explode('.', $state);
        if (count($parts) !== 2) return null;
        [$payload, $sig] = $parts;
        $expected = self::b64url(hash_hmac('sha256', $payload, self::key(), true));
        if (!hash_equals($expected, $sig)) return null;
        $json   = self::b64urlDecode($payload);
        $claims = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($claims)) return null;
        if (empty($claims['exp']) || time() > (int)$claims['exp']) return null;
        return $claims;
    }

    private static function key(): string {
        return EncryptionService::deriveKey(self::CONTEXT);
    }

    private static function b64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s) {
        return base64_decode(strtr($s, '-_', '+/'));
    }
}
