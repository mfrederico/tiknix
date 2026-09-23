<?php
/**
 * BrokerService — mints and resolves the per-instance BROKER KEY.
 *
 * The broker key is a revocable, hash-stored capability that lets a builder
 * instance call the MCP gateway to reach ITS OWN connected stores. It decrypts
 * nothing, is scoped to a single instance, and is killed by one flag flip. The
 * raw key is shown exactly once (at mint); only its sha-256 hash lives in the DB.
 *
 * This is a capability, NOT a secret worth custody — losing it exposes, at worst,
 * rate-limited, audited API use of that one instance's stores until it is revoked.
 */

namespace app;

use app\Bean;

class BrokerService {

    /** The (single) broker apikey row for an instance, or null. */
    public static function forInstance(int $instanceId) {
        return Bean::findOne('apikey', "instance_id = ? AND key_class = 'broker'", [$instanceId]);
    }

    /**
     * The `apikey` bean for the broker token this request presented, or null.
     *
     * ONE implementation for every self-authenticating control-plane endpoint (Brokerinfo,
     * Publish, Concepthub). It was written out twice and had already drifted — one copy
     * stamped last-used, the other logged expiry — which is how an auth rule ends up
     * enforced in one door and not the next.
     *
     * Broker keys are stored as a sha-256 hash, never plaintext, so the lookup is by hash.
     * `is_active` means "not revoked"; an `expires_at` in the past is a separate hard stop.
     *
     * @param bool $touch record last_used_at / last_used_ip — for endpoints that ACT;
     *                    read-only lookups leave the row alone
     */
    public static function keyFromRequest(bool $touch = false) {
        $h = '';
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0) { $h = (string) $v; break; }
        }
        // REDIRECT_ is where some server setups put the header; it is the same header, not a second credential.
        if ($h === '') $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = stripos($h, 'bearer ') === 0 ? trim(substr($h, 7)) : '';
        if ($token === '') return null;

        $key = Bean::findOne('apikey', 'token_hash = ? AND key_class = ? AND is_active = 1',
            [EncryptionService::hashHex($token), 'broker']);
        if (!$key || !$key->id) return null;

        if ($key->expiresAt && strtotime((string) $key->expiresAt) < time()) {
            \Flight::get('log')?->warning('Broker auth failed: key expired', ['key_id' => $key->id]);
            return null;
        }
        if ($touch) {
            $key->lastUsedAt = date('Y-m-d H:i:s');
            $key->lastUsedIp = $_SERVER['REMOTE_ADDR'] ?? null;
            Bean::store($key);
        }
        return $key;
    }

    /**
     * Mint (or rotate) the instance's broker key. Returns the RAW token ONCE —
     * only its hash is persisted. The caller must enforce instance ownership.
     *
     * @param string[] $connectorKeys connectors this key may reach (advisory allowlist)
     * @return array{id:int, token:string}
     */
    public static function mint(int $instanceId, int $memberId, array $connectorKeys = []): array {
        $raw = 'brk_' . bin2hex(random_bytes(24));
        $key = self::forInstance($instanceId);
        if (!$key || !$key->id) $key = Bean::dispense('apikey');

        $now = date('Y-m-d H:i:s');
        $key->name           = 'broker:instance:' . $instanceId;
        $key->memberId       = $memberId;
        $key->instanceId     = $instanceId;
        $key->keyClass       = 'broker';
        $key->token          = null;                                 // never store the raw token
        $key->tokenHash      = EncryptionService::hashHex($raw);
        $key->scopes         = json_encode(['broker:*']);
        $key->allowedServers = json_encode(array_values($connectorKeys));
        $key->connectionIds  = json_encode([]);                      // empty = all this instance's connections
        $key->isActive       = 1;
        $key->expiresAt      = null;
        if (!$key->id) $key->createdAt = $now;
        $key->updatedAt      = $now;
        Bean::store($key);

        return ['id' => (int)$key->id, 'token' => $raw];
    }

    /** The control-plane MCP endpoint an instance calls to reach its stores. */
    public static function endpoint(): string {
        $host = strtolower(trim((string)(\Flight::get('app.control_plane_host') ?? '')));
        if ($host === '') $host = (string) app_host();
        if ($host === '') {
            // This address is written into an instance's broker.ini beside a CREDENTIAL;
            // inventing it hands someone a working key pointed at an install that is not
            // theirs (see Connections::requestHost's note on the sibling path).
            throw new \RuntimeException('Broker endpoint: set [app] control_plane_host (or [app] baseurl) in conf/config.ini; the control plane cannot guess its own address.');
        }
        return 'https://' . $host . '/mcp/message';
    }

    /** Write the instance's conf/broker.ini so its app can reach its stores. */
    public static function writeInstanceConfig(string $instanceDir, string $rawKey): bool {
        $confDir = rtrim($instanceDir, '/') . '/conf';
        if (!is_dir($confDir)) return false;
        $body = "; Auto-managed by tiknix — do not edit or commit. Lets this instance\n"
              . "; read its connected stores. Managed from the Connections page.\n\n"
              . "[broker]\n"
              . 'endpoint = "' . self::endpoint() . '"' . "\n"
              . 'key = "' . $rawKey . '"' . "\n";
        if (@file_put_contents($confDir . '/broker.ini', $body) === false) return false;
        @chmod($confDir . '/broker.ini', 0640);
        return true;
    }

    /**
     * Ensure the instance is wired to reach its stores: if conf/broker.ini already
     * holds a key that matches a live broker key, leave it; otherwise mint a fresh
     * key and write it. Idempotent — connecting a second store won't rotate the
     * first. Best-effort; caller should not fail the connect if this throws.
     */
    public static function ensureInstanceConfig(int $instanceId, int $memberId, string $instanceDir): void {
        $file = rtrim($instanceDir, '/') . '/conf/broker.ini';
        if (is_file($file)) {
            $ini = @parse_ini_file($file, true) ?: [];
            $fileKey = (string)($ini['broker']['key'] ?? '');
            if ($fileKey !== '') {
                $row = self::forInstance($instanceId);
                if ($row && $row->id && (int)$row->isActive === 1
                    && hash_equals((string)$row->tokenHash, EncryptionService::hashHex($fileKey))) {
                    return; // already wired to a live key
                }
            }
        }
        $res = self::mint($instanceId, $memberId, []);
        self::writeInstanceConfig($instanceDir, $res['token']);
    }

    /** Revoke (deactivate) the instance's broker key. */
    public static function revoke(int $instanceId): void {
        $key = self::forInstance($instanceId);
        if ($key && $key->id) {
            $key->isActive  = 0;
            $key->updatedAt = date('Y-m-d H:i:s');
            Bean::store($key);
        }
    }
}
