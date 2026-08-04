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

    // ---- this install's own connections ------------------------------------------
    //
    // Core owns core's connectors; every instance owns its own. There is no
    // instance_id here because there is nothing to scope: the FILE is the scope,
    // and every install asks the same question — "what are my connections?" — with
    // no id to pass and none to get wrong. See CONNECTIONS_PER_INSTANCE.md.
    //
    // Additive for now. The readers still use forInstance() against core's table;
    // this is the storage they move onto.

    /** Named RedBean connection for this install's own connections file. */
    private const OWN_KEY = 'ownconn';

    /** Where the credentials live. Gitignored, like data/workbench.db. */
    public static function ownDbPath(): string {
        return dirname(__DIR__) . '/data/connections.db';
    }

    /**
     * This install's encryption key, minted on first use.
     *
     * Its own file rather than a line in config.ini, for two reasons. config.ini
     * holds things people paste into support threads, and this is the one value
     * that must never travel. And a separate file can be 0600 and owned narrowly
     * without changing the permissions of everything else.
     *
     * NOT the global [security] app_key: that key is core's, and the whole point
     * is that an instance can read its own connections without core.
     */
    public static function ownKey(): string {
        $file = dirname(__DIR__) . '/conf/connections.key';

        if (is_file($file)) {
            $k = trim((string) file_get_contents($file));
            if (strlen($k) === 64 && ctype_xdigit($k)) return $k;
            throw new \Exception('conf/connections.key is malformed. Move it aside and '
                               . 'reconnect this install\'s connectors to mint a new one.');
        }

        $key = EncryptionService::generateKey();

        // Written before it is used, and the write is checked: a key that failed to
        // persist would encrypt everything in this request and decrypt nothing ever
        // again.
        if (@file_put_contents($file, $key . "\n", LOCK_EX) === false) {
            throw new \Exception('Could not write conf/connections.key — connections cannot be stored.');
        }
        @chmod($file, 0600);

        \Flight::get('log')?->info('ConnectionStore: minted this install\'s connection key');
        return $key;
    }

    /**
     * Run against this install's own connections db, restoring the caller's
     * connection afterwards. Mirrors app\CoreDb::with.
     */
    public static function withOwnDb(callable $fn, $onError = null) {
        $db = self::ownDbPath();
        $dir = dirname($db);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $restore = \RedBeanPHP\R::hasDatabase('default') ? 'default' : null;
        try {
            if (!\RedBeanPHP\R::hasDatabase(self::OWN_KEY)) {
                \RedBeanPHP\R::addDatabase(self::OWN_KEY, 'sqlite:' . $db);
            }
            \RedBeanPHP\R::selectDatabase(self::OWN_KEY);
            \RedBeanPHP\R::freeze(false);   // fluid: the table appears on first store
            return $fn();
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('ConnectionStore: own-db operation failed',
                ['err' => $e->getMessage()]);
            return $onError;
        } finally {
            if ($restore) \RedBeanPHP\R::selectDatabase($restore);
        }
    }

    /**
     * This install's connection for a connector, or null.
     *
     * The successor to forInstance(). No instance id, no member id: everything in
     * the file belongs to this install already.
     */
    public static function for(string $type, ?string $env = null): ?\RedBeanPHP\OODBBean {
        if ($type === '') return null;

        return self::withOwnDb(function () use ($type, $env) {
            $where  = 'connector_type = ? AND enabled = 1';
            $params = [$type];
            if ($env !== null) { $where .= ' AND environment = ?'; $params[] = $env; }

            // Production first when unspecified, then newest — same rule as
            // forInstance, and for the same reason: an install with both a
            // production and a staging connection must not silently get staging.
            $rows = Bean::find('connections',
                $where . " ORDER BY CASE WHEN environment = 'production' THEN 0 ELSE 1 END, id DESC",
                $params);

            foreach ($rows as $c) {
                if ($c->id && empty($c->revokedAt)) return $c;
            }
            return null;
        }, null);
    }

    /** The usable credential for one of this install's own connections. */
    public static function ownToken(?\RedBeanPHP\OODBBean $conn): string {
        $raw = (string) ($conn->accessToken ?? '');
        if ($raw === '') return '';

        try {
            return EncryptionService::decryptWith($raw, self::ownKey());
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('ConnectionStore: could not decrypt an own connection', [
                'connection' => (int) ($conn->id ?? 0), 'err' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Store one of this install's own connections, encrypted with its own key.
     *
     * One row per (connector, environment, external account) — the same key as
     * upsert() minus member and instance, which mean nothing in a file that
     * belongs to one install.
     */
    public static function put(string $type, string $env, array $payload): int {
        $key = self::ownKey();   // minted (and its write checked) before anything is stored

        return (int) self::withOwnDb(function () use ($type, $env, $payload, $key) {
            $eid = (string) ($payload['external_eid'] ?? '');

            $conn = Bean::findOne('connections',
                'connector_type = ? AND environment = ? AND external_eid = ?', [$type, $env, $eid]);
            if (!$conn || !$conn->id) $conn = Bean::dispense('connections');

            $conn->connectorType = $type;
            $conn->environment   = $env;
            $conn->externalEid   = $eid;
            $conn->externalName  = (string) ($payload['external_name'] ?? '');
            $conn->externalUrl   = (string) ($payload['external_url'] ?? '');
            $conn->tokenType     = (string) ($payload['token_type'] ?? '');
            $conn->scopes        = (string) ($payload['scopes'] ?? '');
            $conn->authType      = (string) ($payload['auth_type'] ?? 'api_key');
            $conn->accessToken   = EncryptionService::encryptWith((string) ($payload['access_token'] ?? ''), $key);
            $conn->enabled       = 1;
            $conn->revokedAt     = null;
            $conn->updatedAt     = date('Y-m-d H:i:s');
            if (!$conn->createdAt) $conn->createdAt = $conn->updatedAt;

            return (int) Bean::store($conn);
        }, 0);
    }

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

        // revoked_at is NOT in the WHERE, and that is deliberate. RedBean's fluid
        // mode answers a query naming a column the table does not have by returning
        // NOTHING — not by raising — so on an instance whose connections table
        // predates revoked_at, a perfectly good connection silently disappears.
        // controls/Mcp.php carried a comment warning about exactly this; it was
        // right, and this helper had the bug it described. Query only the columns
        // every version of the table has, and judge revocation in PHP.
        $where  = 'instance_id = ? AND connector_type = ? AND enabled = 1';
        $params = [$instanceId, $type];

        if ($env !== null)      { $where .= ' AND environment = ?'; $params[] = $env; }
        if ($memberId !== null) { $where .= ' AND member_id = ?';   $params[] = $memberId; }

        // Production first when no environment was asked for, then newest.
        //
        // Ordering by id alone looked harmless and was not: an instance with both a
        // production and a staging connection gets the staging one, because staging
        // was created second. For the callers that DO name an environment this
        // changes nothing; for the ones that do not — "the connection for this
        // instance" — it is the difference between publishing to the real store and
        // publishing to a test one.
        $rows = Bean::find(
            'connections',
            $where . " ORDER BY CASE WHEN environment = 'production' THEN 0 ELSE 1 END, id DESC",
            $params
        );

        // First non-revoked, in that order. Reading the property rather than the
        // column means a table without it simply reports nothing revoked, which is
        // the correct answer for a schema that has no concept of revocation.
        foreach ($rows as $c) {
            if (!$c->id) continue;
            if (!empty($c->revokedAt)) continue;
            return $c;
        }
        return null;
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
