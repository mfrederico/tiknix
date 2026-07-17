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
        if ($host === '') {
            $host = (string)(parse_url((string)\Flight::get('app.baseurl'), PHP_URL_HOST) ?: 'tiknix.com');
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
