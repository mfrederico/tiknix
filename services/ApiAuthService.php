<?php
/**
 * ApiAuthService — stateless API-key auth for scaffolded API controllers.
 *
 * Mirrors the apikey-table auth in controls/Mcp.php: reads a Bearer token
 * (Authorization header, X-Api-Key fallback), validates the apikey row (active +
 * not expired), loads the owning member, and bumps usage stats. Scope checks are
 * best-effort — an empty/absent scopes list allows all; a non-empty list must
 * contain '*', '<bean>.*', '<bean>.<action>', or '<action>'.
 */

namespace app\services;

use app\Bean;

class ApiAuthService {

    /**
     * @return array{success:bool, member_id:?int, member?:object, key?:object, error:?string}
     */
    public static function authenticate(string $bean = '', string $action = 'read'): array {
        $token = self::extractToken();
        if ($token === '') {
            return ['success' => false, 'member_id' => null, 'error' => 'Missing API token'];
        }

        $key = Bean::findOne('apikey', 'token = ? AND is_active = 1', [$token]);
        if (!$key || !$key->id) {
            return ['success' => false, 'member_id' => null, 'error' => 'Invalid API token'];
        }
        if ($key->expiresAt && strtotime((string)$key->expiresAt) < time()) {
            return ['success' => false, 'member_id' => null, 'error' => 'API token expired'];
        }

        $member = Bean::load('member', (int)$key->memberId);
        if (!$member->id) {
            return ['success' => false, 'member_id' => null, 'error' => 'API token owner not found'];
        }

        $scopes = json_decode((string)$key->scopes, true) ?: [];
        if ($bean !== '' && $scopes && !self::allows($scopes, $bean, $action)) {
            return ['success' => false, 'member_id' => null, 'error' => "API token lacks scope {$bean}.{$action}"];
        }

        // Usage stats (best effort — don't fail auth if this throws).
        try {
            $key->lastUsedAt = date('Y-m-d H:i:s');
            $key->lastUsedIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $key->usageCount = ((int)($key->usageCount ?? 0)) + 1;
            Bean::store($key);
        } catch (\Throwable $e) { /* non-fatal */ }

        return ['success' => true, 'member_id' => (int)$member->id, 'member' => $member, 'key' => $key, 'error' => null];
    }

    private static function extractToken(): string {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $h, $m)) return trim($m[1]);
        if (!empty($_SERVER['HTTP_X_API_KEY'])) return trim((string)$_SERVER['HTTP_X_API_KEY']);
        return '';
    }

    private static function allows(array $scopes, string $bean, string $action): bool {
        foreach (['*', "{$bean}.*", "{$bean}.{$action}", $action] as $needle) {
            if (in_array($needle, $scopes, true)) return true;
        }
        return false;
    }
}
