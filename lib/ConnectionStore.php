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
    // Core's old shared table is gone; forInstance() and upsert() throw rather than
    // answer, so nothing can accidentally read a connector that did not travel.

    /**
     * A credential this class stores but does NOT encrypt: the publish drivers' SSH
     * keys, sealed by SshKey and unsealed by them at the point of use. Marked so
     * ownToken() leaves it alone instead of reporting a decrypt failure.
     */
    public const AUTH_SEALED = 'ssh_sealed';

    /** Named RedBean connection for this install's own connections file. */
    private const OWN_KEY = 'ownconn';

    /**
     * The install whose connections these calls act on.
     *
     * Empty means "the install this code is running in", which is the answer for
     * core and for an instance running its own code. A SIDECAR is the exception:
     * it acts on behalf of a project, so it must name that project's directory —
     * exactly as it already points RedBean at that project's workbench.db. Without
     * this, a sidecar would read its own connections and find none, which looks
     * identical to "the customer never connected anything".
     */
    private static string $root = '';

    /** Act on this install's directory (its root, containing conf/ and data/). */
    public static function useInstall(string $installRoot): void {
        self::$root = rtrim($installRoot, '/');
        // A different install means a different file and a different key.
        Bean::hasDatabase(self::OWN_KEY) && Bean::selectDatabase('default');
        self::$openedFor = '';
    }

    /** Back to the install this code lives in. */
    public static function useOwnInstall(): void {
        self::$root = '';
        self::$openedFor = '';
    }

    private static string $openedFor = '';

    private static function root(): string {
        return self::$root !== '' ? self::$root : dirname(__DIR__);
    }

    /** Where the credentials live. Gitignored, like data/workbench.db. */
    public static function ownDbPath(): string {
        return self::root() . '/data/connections.db';
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
        $dir  = self::root() . '/secure';
        $file = $dir . '/connections.key';

        // secure/, not conf/. conf/ is a grab-bag whose directory is world-readable,
        // and it could only be protected in git by an extension glob — conf/*.key
        // ignored a .key and would have let a .pem or a .json straight through. A
        // directory rule cannot be slipped past by naming a file differently.
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // 0700 whether we made it or found it: instances already have a secure/ for
        // uploads, and it is world-readable, which is not good enough for this.
        @chmod($dir, 0700);

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
    public static function withOwnDb(callable $fn, $onError = null, bool $create = false) {
        $db = self::ownDbPath();

        // A READ must not bring the file into existence. RedBean::addDatabase opens
        // SQLite, and SQLite creates on open, so merely asking an instance what it is
        // connected to left an empty database behind -- scheduler-17d481 and
        // quickticket-027513 both acquired one from a lookup that touched nothing.
        if (!$create && !is_file($db)) return $onError;

        $dir = dirname($db);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $restore = Bean::hasDatabase('default') ? 'default' : null;
        try {
            // One named connection per install path: reusing the name across installs
            // would silently keep the first database open.
            $conn = self::OWN_KEY . '_' . substr(sha1($db), 0, 8);
            if (!Bean::hasDatabase($conn)) Bean::addDatabase($conn, 'sqlite:' . $db);
            Bean::selectDatabase($conn);
            Bean::freeze(false);   // fluid: the table appears on first store
            return $fn();
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('ConnectionStore: own-db operation failed',
                ['err' => $e->getMessage()]);
            return $onError;
        } finally {
            if ($restore) Bean::selectDatabase($restore);
        }
    }

    /**
     * This install's connection for a connector, or null.
     *
     * The successor to forInstance(). No instance id, no member id: everything in
     * the file belongs to this install already.
     */
    /**
     * @param string|null $account WHICH connection, when an install has more than
     *        one of the same connector — matched against the account id the provider
     *        gave us (a Shopify shop domain, a Stripe acct_), the display name, or
     *        the connection's own id.
     *
     * @throws \RuntimeException when the answer is ambiguous, or when a named
     *         account is not connected. Both are questions this code cannot answer,
     *         and answering them anyway is how a pipeline reads the wrong shop.
     */
    public static function for(string $type, ?string $env = null, ?string $account = null): ?\RedBeanPHP\OODBBean {
        if ($type === '') return null;

        // The QUERY runs inside withOwnDb; the DECISION does not. withOwnDb catches
        // Throwable and returns its fallback, so an ambiguity thrown in there would
        // be swallowed and re-reported as "no connection" — sending someone to
        // reconnect a connector that was already connected twice.
        $live = self::withOwnDb(function () use ($type, $env) {
            $where  = 'connector_type = ? AND enabled = 1';
            $params = [$type];
            if ($env !== null) { $where .= ' AND environment = ?'; $params[] = $env; }

            // Production first when unspecified, then newest — same rule as
            // forInstance, and for the same reason: an install with both a
            // production and a staging connection must not silently get staging.
            $rows = Bean::find('connections',
                $where . " ORDER BY CASE WHEN environment = 'production' THEN 0 ELSE 1 END, id DESC",
                $params);

            $out = [];
            foreach ($rows as $c) {
                if ($c->id && empty($c->revokedAt)) $out[] = $c;
            }
            return $out;
        }, []);

        if (!$live) return null;

        if ($account !== null && $account !== '') {
            foreach ($live as $c) if (self::matchesAccount($c, $account)) return $c;
            throw new \RuntimeException(
                "No {$type} connection matching '{$account}'. Connected: " . self::describe($live) . '.'
            );
        }

        // ONE is unambiguous. More than one, with no account named, is a question
        // this code cannot answer — so it asks.
        //
        // It used to take the newest row. A pipeline against two Shopify stores
        // therefore pulled from whichever was connected last, and silently changed
        // which one the day a third was added. Nothing in the run said so; the
        // orders were simply the wrong shop's.
        if (count($live) > 1) {
            throw new \RuntimeException(
                'This install has ' . count($live) . " {$type} connections and the step did not say which: "
                . self::describe($live) . '. Name one with "account".'
            );
        }

        return $live[0];
    }

    /** Does this connection answer to the name a step used? */
    private static function matchesAccount(\RedBeanPHP\OODBBean $c, string $account): bool {
        $account = strtolower(trim($account));
        foreach ([$c->externalEid, $c->externalName, $c->connectionName, $c->id] as $candidate) {
            if ($candidate !== null && strtolower(trim((string) $candidate)) === $account) return true;
        }
        return false;
    }

    /** The connected accounts, for an error that can actually be acted on. */
    private static function describe(array $conns): string {
        $out = [];
        foreach ($conns as $c) {
            $name = (string) ($c->externalEid ?: $c->externalName ?: $c->connectionName ?: $c->id);
            $out[] = $name . ' (' . (string) $c->environment . ')';
        }
        return implode(', ', $out);
    }

    /**
     * An INSTANCE's connection, read from that instance's own store.
     *
     * The real replacement for forInstance(). Core is a control plane: MCP tool
     * calls, publish drivers and the Connections hub all run here but act for an
     * instance, so "this install's file" is the wrong file for them — they need
     * the instance's.
     *
     * The token is decrypted here, while the instance's key is still the one in
     * scope, and carried on the bean as `plainToken`. Decrypting later would need
     * the caller to know whose key to use, which is exactly the knowledge this
     * class exists to hold. It is never stored: nothing calls store() on this bean.
     */
    public static function forInstall(int $instanceId, string $type, ?string $env = null, ?string $account = null): ?\RedBeanPHP\OODBBean {
        if ($instanceId <= 0 || $type === '') return null;

        $inst = Bean::load('instance', $instanceId);
        if (!$inst->id) return null;

        $dir = $inst->box()->dir();
        if ($dir === '' || !is_dir($dir)) {
            \Flight::get('log')?->warning('ConnectionStore: instance has no directory on this host',
                ['instance' => $instanceId, 'dir' => $dir]);
            return null;
        }

        self::useInstall($dir);
        try {
            $conn = self::for($type, $env, $account);
            if ($conn) {
                $conn->plainToken = self::ownToken($conn);
                // Carried for the same reason as plainToken: a connector whose access
                // token expires needs the refresh token to mint a new one, and only
                // here is the instance's key in scope to decrypt it.
                $conn->plainRefreshToken = self::ownSecret($conn, 'refreshToken');
                // Where the refreshed token has to be written back to. The broker runs
                // in core, so without this it would re-seal a rotated token with the
                // WRONG key and lock the connection out permanently.
                $conn->installDir = $dir;
            }
            return $conn;
        } finally {
            self::useOwnInstall();
        }
    }

    /**
     * Retired (Phase 3). Store a connection into an INSTANCE's own file.
     *
     * It reached across the boundary: core opened another install's data/ and wrote
     * it. That is only possible while the two share a disk, so the same connect
     * against a self-hosted instance found no directory and returned 0 — and 0 was
     * indistinguishable from "stored, but I did not get an id". Its own guards were
     * the shape this codebase keeps being bitten by: two silent `return 0`s where
     * the honest answer was "that instance is not on this host".
     *
     * Its replacement is a PUSH, not a write: \app\ConnectorPush::push() delivers
     * the credential to that install's own /connectorapi/receive with that
     * install's broker key. One door, on-disk or self-hosted, which is what makes
     * ejection real rather than nominal.
     *
     * Throwing rather than deleting: a caller that reappears gets told what to do
     * instead of a fatal about an undefined method.
     */
    public static function putForInstall(int $instanceId, string $type, string $env, array $payload): int {
        throw new \RuntimeException(
            'ConnectionStore::putForInstall is retired — core no longer writes another '
          . "install's connections file. Use \\app\\ConnectorPush::push(). "
          . 'See CONNECTIONS_PER_INSTANCE.md.');
    }

    /**
     * Run arbitrary bean work against an INSTANCE's store.
     *
     * for()/put() cover "read one connection" and "write one connection", which is
     * most callers. It is not all of them: the publish drivers create a row and then
     * update it with the outcome, and Brokerinfo lists and deletes. Those were left
     * doing plain Bean:: calls against whatever database happened to be selected —
     * which is CORE's, so a driver read the instance's key and wrote core's table,
     * and never found its own keypair again.
     *
     * The lesson is that a bean from forInstall() is READ-ONLY: RedBean stores to the
     * database selected at store() time, not the one the bean came from, so keeping
     * one past the call and saving it silently writes to the wrong file. Anything
     * that mutates belongs in here, where the right database is selected for the
     * whole unit of work.
     */
    public static function withInstall(int $instanceId, callable $fn, $onError = null, bool $create = false) {
        if ($instanceId <= 0) return $onError;

        $inst = Bean::load('instance', $instanceId);
        if (!$inst->id) return $onError;

        $dir = $inst->box()->dir();
        if ($dir === '' || !is_dir($dir)) {
            \Flight::get('log')?->warning('ConnectionStore: instance has no directory on this host',
                ['instance' => $instanceId, 'dir' => $dir]);
            return $onError;
        }

        self::useInstall($dir);
        try {
            return self::withOwnDb($fn, $onError, $create);
        } finally {
            self::useOwnInstall();
        }
    }

    /** This install's encryption key, for callers writing inside withInstall(). */
    public static function currentKey(): string {
        return self::ownKey();
    }

    /**
     * The usable credential for one of this install's own connections.
     *
     * SEALED rows are not ours to open. The publish drivers store an SSH private key
     * sealed by SshKey, not by EncryptionService, and they unseal it themselves at
     * the point of use. Trying anyway logged a decrypt ERROR on every publish for a
     * connection that was working perfectly — and a log that cries wolf is worse than
     * no log, because it is the one people learn to scroll past.
     */
    /**
     * Seal a secret, or store nothing when there is none.
     *
     * The distinction is load-bearing: empty ciphertext means "this connection has
     * no secret" (a public API), while ciphertext that decrypts to nothing means
     * the key is wrong. Encrypting '' would collapse the two.
     */
    private static function sealOrEmpty(string $secret, string $key): string {
        return $secret === '' ? '' : EncryptionService::encryptWith($secret, $key);
    }

    /**
     * Decrypt any sealed field on a connection with THIS install's key.
     *
     * ownToken() is the access-token case; refresh tokens need the same treatment
     * and there is no reason for two copies of the logic.
     */
    /**
     * The merchant's own provider app for this connection, decrypted, or [].
     *
     * Shaped as the connector's $ctx['app'] so it can be handed straight to
     * authorizeUrl()/exchangeCode(). Returns [] when the connection uses the shared
     * app, which is the common case and not an error.
     *
     * Both halves or nothing: a connection carrying only one of them cannot
     * authenticate, and completing the pair from the server-wide ini would pair a
     * merchant's key with tiknix's secret — the failure that looks like Shopify
     * rejecting the request rather than a mixed-up app.
     */
    public static function ownApp(?\RedBeanPHP\OODBBean $conn): array {
        if (!$conn || !($conn->id ?? 0)) return [];
        $id     = self::ownSecret($conn, 'appKey');
        $secret = self::ownSecret($conn, 'appSecret');
        if ($id === '' || $secret === '') return [];
        return ['client_id' => $id, 'client_secret' => $secret];
    }

    public static function ownSecret(?\RedBeanPHP\OODBBean $conn, string $field): string {
        $raw = (string) ($conn->$field ?? '');
        if ($raw === '') return '';
        if ((string) ($conn->authType ?? '') === self::AUTH_SEALED) return '';

        try {
            return EncryptionService::decryptWith($raw, self::ownKey());
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('ConnectionStore: could not decrypt a connection secret', [
                'connection' => (int) ($conn->id ?? 0), 'field' => $field, 'err' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Write a refreshed token back into the instance's own store.
     *
     * The broker runs in CORE, so it cannot simply store() the bean it was handed:
     * that would seal the new token with core's key into a file the instance reads
     * with its own, and the connection would be permanently unreadable. Switching
     * to the instance's database first is the whole point of this method.
     */
    public static function refreshStored(string $installDir, int $connId, array $tok): bool {
        if ($installDir === '' || $connId <= 0) return false;

        self::useInstall($installDir);
        try {
            // withOwnDb, not a bare Bean::load. Selecting the install's directory is
            // only half of it — the connections file is a SEPARATE RedBean database,
            // so loading without switching to it reads the app's tables, finds no
            // such row, and reports a clean failure to write a token that was
            // perfectly good.
            return (bool) self::withOwnDb(function () use ($connId, $tok) {
                $key  = self::ownKey();
                $conn = Bean::load('connections', $connId);
                if (!$conn->id) return false;

                $conn->accessToken = self::sealOrEmpty((string) ($tok['access_token'] ?? ''), $key);
                if (!empty($tok['refresh_token'])) {
                    // Only when a new one came back. Providers that rotate send one;
                    // those that do not send nothing, and blanking it would break the
                    // connection at the NEXT expiry rather than immediately — the
                    // worst kind of failure to diagnose.
                    $conn->refreshToken = self::sealOrEmpty((string) $tok['refresh_token'], $key);
                }
                if (!empty($tok['expires_at'])) $conn->expiresAt = (int) $tok['expires_at'];
                $conn->updatedAt = date('Y-m-d H:i:s');
                Bean::store($conn);
                return true;
            }, false, false);
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('ConnectionStore: could not store a refreshed token', [
                'connection' => $connId, 'err' => $e->getMessage(),
            ]);
            return false;
        } finally {
            self::useOwnInstall();
        }
    }

    public static function ownToken(?\RedBeanPHP\OODBBean $conn): string {
        $raw = (string) ($conn->accessToken ?? '');
        if ($raw === '') return '';
        if ((string) ($conn->authType ?? '') === self::AUTH_SEALED) return '';

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
            // An absent secret is stored as nothing, not as encrypted emptiness.
            // secretbox turns '' into a perfectly good nonce+MAC, so encrypting it
            // makes a connection that HAS no secret indistinguishable from one whose
            // secret will not decrypt — and the broker, which reads an empty
            // plaintext as a decryption failure, refused a working public-API
            // connection on those grounds. Empty ciphertext now means exactly one
            // thing: there is no secret here.
            $conn->accessToken   = self::sealOrEmpty((string) ($payload['access_token'] ?? ''), $key);

            // Connector-specific detail: github's owner/repo/defaultBranch, a publish
            // driver's public key and fingerprint. Dropping it made put() unusable for
            // those flows, which is why they were still writing core's table by hand.
            if (isset($payload['connection_name'])) $conn->connectionName = (string) $payload['connection_name'];
            if (array_key_exists('metadata', $payload)) {
                $conn->metadataJson = is_string($payload['metadata'])
                    ? $payload['metadata']
                    : json_encode($payload['metadata'] ?: []);
            }

            // Refresh state, for providers whose access token expires. The refresh
            // token is a credential in its own right — it MINTS access tokens — so it
            // is sealed with the same key and never stored in the clear.
            //
            // Only written when supplied: a refresh that returns a new access token
            // but no new refresh token (the common case) must not blank the one on
            // the row, or the connection dies at the next expiry.
            // A merchant's OWN provider app (Shopify custom app: API key + secret), for
            // stores authorised against their app rather than the shared tiknix one.
            // Sealed with the same key as the tokens, because the secret mints tokens for
            // that store just as surely as a refresh token does.
            //
            // Written only when supplied, for the same reason as refresh_token below: a
            // later re-auth that does not resend them must not blank the pair and leave
            // the connection unable to re-authorise against the app it belongs to.
            foreach (['app_key' => 'appKey', 'app_secret' => 'appSecret'] as $in => $prop) {
                if (array_key_exists($in, $payload)) {
                    $conn->$prop = self::sealOrEmpty((string) $payload[$in], $key);
                }
            }

            if (array_key_exists('refresh_token', $payload)) {
                $conn->refreshToken = self::sealOrEmpty((string) $payload['refresh_token'], $key);
            }
            if (array_key_exists('expires_at', $payload)) {
                $conn->expiresAt = (int) $payload['expires_at'] ?: null;
            }

            $conn->enabled       = 1;
            $conn->revokedAt     = null;
            $conn->lastError     = null;
            $conn->updatedAt     = date('Y-m-d H:i:s');
            if (!$conn->createdAt) $conn->createdAt = $conn->updatedAt;

            return (int) Bean::store($conn);
        }, 0, true);
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
        // Refuses rather than reads. This looked in core's shared connections table,
        // and a connector found there is one that did NOT travel with its instance —
        // which is the whole thing this move exists to prevent. Returning null would
        // be worse than throwing: the caller would report "not connected" and
        // somebody would go looking for a setting that is not missing.
        //
        // Use for() for this install, or forInstall($instanceId, ...) for another.
        throw new \RuntimeException(
            'ConnectionStore::forInstance is retired — connectors live with their '
          . 'instance now. Use for() or forInstall(). See CONNECTIONS_PER_INSTANCE.md.');

        // @phpstan-ignore-next-line — kept so the original query is readable in blame
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
        // forInstall() already decrypted this with the instance's key, which is the
        // only key that could have opened it.
        $carried = (string) ($conn->plainToken ?? '');
        if ($carried !== '') return $carried;

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
        // Retired. This wrote core's shared table, which is the one place a
        // credential can land and then not travel with its instance. Use put() for
        // this install, or \app\ConnectorPush::push($instanceId, ...) for another.
        throw new \RuntimeException(
            'ConnectionStore::upsert is retired — connectors live with their instance '
          . 'now. Use put(), or \\app\\ConnectorPush::push() for another install. '
          . 'See CONNECTIONS_PER_INSTANCE.md.');

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
        // Same reasoning as putForInstall(): no secret is stored as no ciphertext.
        $tok = (string) ($payload['access_token'] ?? '');
        $conn->accessToken    = $tok === '' ? '' : EncryptionService::encrypt($tok);
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
