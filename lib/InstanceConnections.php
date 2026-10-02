<?php
/**
 * InstanceConnections — the CONTROL PLANE reading or working on a PROJECT's connection store
 * (RUNTIME-SPLIT-MAP.md step 2: split out of ConnectionStore, which is the runtime's and only
 * ever knows its own install). Core acts for an instance here — MCP tool calls, publish
 * drivers, the Connections hub, Brokerinfo — by resolving the instance's directory from the
 * registry and pointing ConnectionStore at it for one unit of work. Only possible while the
 * project shares core's disk: for an app in its own container both methods THROW, and callers
 * ask the app instead (ConnectorPush::ask → its /connectorapi/* doors).
 */

namespace app;

class InstanceConnections {

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

        // An app in its own container keeps its store there. Its folder here is the builder's
        // workspace: it has no connections.db, and reading it answers "nothing connected".
        if (\Model_Instance::tenantRow($inst)) {
            throw new \RuntimeException("{$inst->slug} runs in its own container: its connections are in the app, "
                . 'not on this host. Ask the app (ConnectorPush::ask, /connectorapi/*) or use them from the app.');
        }

        $dir = $inst->box()->dir();
        if ($dir === '' || !is_dir($dir)) {
            \Flight::get('log')?->warning('InstanceConnections: instance has no directory on this host',
                ['instance' => $instanceId, 'dir' => $dir]);
            return null;
        }

        ConnectionStore::useInstall($dir);
        try {
            $conn = ConnectionStore::for($type, $env, $account);
            if ($conn) {
                $conn->plainToken = ConnectionStore::ownToken($conn);
                // Carried for the same reason as plainToken: a connector whose access
                // token expires needs the refresh token to mint a new one, and only
                // here is the instance's key in scope to decrypt it.
                $conn->plainRefreshToken = ConnectionStore::ownSecret($conn, 'refreshToken');
                // Where the refreshed token has to be written back to. The broker runs
                // in core, so without this it would re-seal a rotated token with the
                // WRONG key and lock the connection out permanently.
                $conn->installDir = $dir;
            }
            return $conn;
        } finally {
            ConnectionStore::useOwnInstall();
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
            'InstanceConnections::putForInstall is retired — core no longer writes another '
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

        // An app in its own container keeps its store there. Its folder here is the builder's
        // workspace: it has no connections.db, and reading it answers "nothing connected".
        if (\Model_Instance::tenantRow($inst)) {
            throw new \RuntimeException("{$inst->slug} runs in its own container: its connections are in the app, "
                . 'not on this host. Ask the app (ConnectorPush::ask, /connectorapi/*) or use them from the app.');
        }

        $dir = $inst->box()->dir();
        if ($dir === '' || !is_dir($dir)) {
            \Flight::get('log')?->warning('InstanceConnections: instance has no directory on this host',
                ['instance' => $instanceId, 'dir' => $dir]);
            return $onError;
        }

        ConnectionStore::useInstall($dir);
        try {
            return ConnectionStore::withOwnDb($fn, $onError, $create);
        } finally {
            ConnectionStore::useOwnInstall();
        }
    }

}
