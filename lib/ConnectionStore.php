<?php
/**
 * ConnectionStore — the SINGLE place a connection credential is written to core.
 *
 * Both the control-plane connect flow (Connections controller) and the instance-driven
 * broker connect flow (Brokerinfo controller) go through here, so credential custody
 * has exactly one implementation and can't drift between the two entry points. The raw
 * token is encrypted here and never stored in the clear; rows are keyed by
 * (member, instance, connector, environment, external account).
 */

namespace app;

use app\Bean;

class ConnectionStore {

    /**
     * The connection for an instance, or null.
     *
     * The counterpart to upsert(): if this is the single place a credential is
     * WRITTEN, reading it belongs here too. Seven callers currently hand-write this
     * WHERE clause. All of them are right — but the rule living in seven places is
     * seven chances to get it wrong, and that is not hypothetical: MondayImport was
     * written with an unscoped lookup and, running on core, returned whichever
     * connection of that type sorted first. One customer's board, another
     * customer's token, both calls succeeding.
     *
     * instance_id is NOT optional. An unscoped connection lookup is never what a
     * caller means on core, where the table holds every instance's rows.
     *
     * @param string|null $env     null matches any environment
     * @param int|null    $memberId  null matches any owner (an instance's connection
     *                               is the instance's, whoever attached it)
     */
    public static function forInstance(
        int $instanceId, string $type, ?string $env = null, ?int $memberId = null
    ): ?\RedBeanPHP\OODBBean {
        if ($instanceId <= 0 || $type === '') return null;

        $where  = 'instance_id = ? AND connector_type = ? AND enabled = 1 '
                . 'AND (revoked_at IS NULL OR revoked_at = 0)';
        $params = [$instanceId, $type];

        if ($env !== null)      { $where .= ' AND environment = ?'; $params[] = $env; }
        if ($memberId !== null) { $where .= ' AND member_id = ?';   $params[] = $memberId; }

        $c = Bean::findOne('connections', $where . ' ORDER BY id DESC', $params);
        return ($c && $c->id) ? $c : null;
    }

    /**
     * The usable credential for a connection.
     *
     * access_token is encrypted by upsert(), so every consumer has to decrypt —
     * and four of them each wrote their own version. Handing the raw column to an
     * API produces an auth error indistinguishable from a revoked token, which is
     * the kind of failure people debug in the wrong direction.
     *
     * Returns '' when the token cannot be decrypted, and says so in the log: an
     * empty credential fails closed at the API, whereas returning ciphertext would
     * send a secret-shaped string to a third party.
     */
    public static function token(?\RedBeanPHP\OODBBean $conn): string {
        $raw = (string) ($conn->accessToken ?? '');
        if ($raw === '') return '';

        try {
            $plain = (string) EncryptionService::decrypt($raw);
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('ConnectionStore: could not decrypt a connection token', [
                'connection' => (int) ($conn->id ?? 0),
                'connector'  => (string) ($conn->connectorType ?? ''),
                'err'        => $e->getMessage(),
            ]);
            return '';
        }

        // Rows written before encryption existed come back unchanged.
        return $plain !== '' ? $plain : $raw;
    }

    /**
     * Upsert an encrypted connection. One row per (member, instance, connector,
     * environment, external_eid) so a builder can hold distinct dev/prod accounts.
     * Returns the connection id.
     */
    public static function upsert(string $type, int $memberId, int $instanceId, string $env, array $payload, string $authType = 'oauth'): int {
        $eid = (string) ($payload['external_eid'] ?? '');

        $conn = Bean::findOne('connections',
            'member_id = ? AND instance_id = ? AND connector_type = ? AND environment = ? AND external_eid = ?',
            [$memberId, $instanceId, $type, $env, $eid]);
        if (!$conn || !$conn->id) $conn = Bean::dispense('connections');

        $now = date('Y-m-d H:i:s');
        $conn->connectorType  = $type;
        $conn->memberId       = $memberId;
        $conn->instanceId     = $instanceId;
        $conn->environment    = $env;
        $conn->authType       = $authType;
        $conn->accessToken    = EncryptionService::encrypt((string) ($payload['access_token'] ?? ''));
        $conn->tokenType      = (string) ($payload['token_type'] ?? 'Bearer');
        $conn->scopes         = (string) ($payload['scopes'] ?? '');
        $conn->externalEid    = $eid;
        $conn->externalName   = (string) ($payload['external_name'] ?? $eid);
        $conn->externalUrl    = (string) ($payload['external_url'] ?? '');
        $conn->connectionName = (string) ($payload['external_name'] ?? $eid) . ' (' . $env . ')';
        $conn->metadataJson   = json_encode($payload['metadata'] ?? []);
        $conn->enabled        = 1;
        $conn->shared         = 0;
        $conn->revokedAt      = null;
        $conn->exportedAt     = $conn->exportedAt ?? null;
        $conn->lastError      = null;
        $conn->lastUsedAt     = $now;
        if (!$conn->id) $conn->createdAt = $now;
        $conn->updatedAt      = $now;
        Bean::store($conn);
        return (int) $conn->id;
    }
}
