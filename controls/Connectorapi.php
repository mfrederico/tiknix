<?php
/**
 * Connectorapi — an install answering "what am I connected to?" over HTTP.
 *
 * This is the inverse of controls/Brokerinfo, and the inversion is the point.
 * Brokerinfo exists because credentials used to live in core: an instance called
 * IN to ask the control plane what it was wired to. Now the instance owns its
 * connectors (CONNECTIONS_PER_INSTANCE.md), so the direction turns around —
 * core and sidecars call the INSTANCE.
 *
 * That matters for one case that direct file access cannot serve: an instance on
 * another machine. Reading data/connections.db works when the caller shares a
 * disk with it, and stops working the moment somebody self-hosts. These routes
 * work identically either way, which is what "the instance runs its own code"
 * has to mean if it is going to be true off this box.
 *
 * AUTH is this install's own broker key (conf/broker.ini), compared in constant
 * time. That key already means "you are allowed to act as this instance", which
 * is exactly the claim being made here. It is not a session and not CSRF-guarded:
 * these are server-to-server.
 *
 * The routes:
 *
 *   GET|POST /connectorapi/list        metadata only — connector, account, status
 *   POST     /connectorapi/connect     {connector, key} — validated HERE, then stored
 *   POST     /connectorapi/receive     {connector, payload} — validated by the CALLER
 *   POST     /connectorapi/disconnect  {id}
 *
 * `receive` is the landing point for core's OAuth callback (lib/ConnectorPush.php):
 * core owns the app registration, so it runs the handshake and pushes the result in
 * here rather than writing this install's file across a disk it may not share.
 *
 * WHAT IT WILL NOT DO: hand back a token. `list` returns metadata only —
 * connector, environment, account name, status. A caller that wants to USE a
 * connection asks this install to use it on their behalf, or shares its disk. A
 * route that returns credentials over HTTP would undo the reason they were moved
 * here.
 *
 * authcontrol: connectorapi::* = 101 (PUBLIC — self-authenticating via the key).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Connectorapi extends Control {

    /** The environments a connection may be filed under. */
    private const ENVIRONMENTS = ['production', 'development', 'staging'];

    /**
     * The auth types receive() will take on trust.
     *
     * `oauth` and `token` come from core's GitHub/registry flows; `api_key` from a key
     * core validated against the provider before pushing. ConnectionStore::AUTH_SEALED
     * is absent on purpose — those rows are sealed by SshKey and ownToken() will not
     * open them, so accepting one would store something permanently unreadable.
     */
    private const RECEIVABLE_AUTH = ['oauth', 'api_key', 'token'];

    /** GET|POST /connectorapi/list — this install's connections, metadata only. */
    public function list($params = []): void {
        if (!$this->authed()) return;

        $out = ConnectionStore::withOwnDb(function () {
            $rows = [];
            foreach (Bean::findAll('connections') as $c) {
                if (!$c->id) continue;
                $rows[] = [
                    'id'          => (int) $c->id,
                    'connector'   => (string) $c->connectorType,
                    'environment' => (string) ($c->environment ?: 'production'),
                    'name'        => (string) ($c->externalName ?: $c->externalEid),
                    'url'         => (string) ($c->externalUrl ?? ''),
                    'enabled'     => (int) $c->enabled === 1,
                    'revoked'     => !empty($c->revokedAt),
                    'updated_at'  => (string) ($c->updatedAt ?? ''),
                ];
            }
            return $rows;
        }, []);

        Flight::json(['connections' => $out]);
    }

    /**
     * POST /connectorapi/connect — store a connector on THIS install.
     *
     * Body: {connector, environment, key}. The credential is validated against the
     * provider before it is stored, so a bad paste fails here rather than at the
     * first real call, and it is encrypted with this install's own key on the way
     * in. The raw value is never echoed back.
     *
     * This is what makes "people re-apply their connectors" a real workflow rather
     * than a manual database edit: core's hub, a sidecar or a script can all drive
     * it, and the credential still only ever lands in one place.
     */
    public function connect($params = []): void {
        if (!$this->authed()) return;

        $body = $this->jsonBody();
        $type = strtolower(trim((string) ($body['connector'] ?? '')));
        // Read once, then validate. The previous form guarded the in_array() check
        // with ?? but read $body['environment'] raw in the true branch, so omitting
        // the key — which every caller does — was an undefined-key error and a 500.
        $env = (string) ($body['environment'] ?? 'production');
        if (!in_array($env, self::ENVIRONMENTS, true)) $env = 'production';
        $raw  = trim((string) ($body['key'] ?? ''));

        if ($type === '' || $raw === '') { Flight::jsonError('connector and key are required.', 400); return; }

        $connector = \app\services\connectors\ConnectorRegistry::get($type);
        if (!$connector) { Flight::jsonError('Unknown connector: ' . $type, 400); return; }
        if (($connector->meta()['auth_type'] ?? 'oauth') !== 'api_key') {
            Flight::jsonError(ucfirst($type) . ' does not connect with a pasted key.', 400); return;
        }

        try {
            // Provider's own words on failure — "Not Authenticated" and "token
            // expired" want different things done about them.
            $payload = $connector->validateApiKey($raw);
            $payload['auth_type'] = 'api_key';
            $id = ConnectionStore::put($type, $env, $payload);
        } catch (\Throwable $e) {
            Flight::jsonError($e->getMessage(), 400); return;
        }

        if ($id <= 0) { Flight::jsonError('The connection could not be stored.', 500); return; }

        Flight::jsonSuccess([
            'id'          => $id,
            'connector'   => $type,
            'environment' => $env,
            'account'     => (string) ($payload['external_name'] ?? $payload['external_eid'] ?? ''),
        ], ucfirst($type) . ' connected.');
    }

    /**
     * POST /connectorapi/receive — take a credential somebody else already validated.
     *
     * The OAuth sibling of connect(), and deliberately a SEPARATE route rather than a
     * looser connect(). connect() re-validates a pasted key against the provider before
     * it stores anything; an OAuth token cannot be obtained that way at all, because the
     * client secret and the provider-allowlisted redirect URI belong to core. Core runs
     * the handshake and hands the result here.
     *
     * Body: {connector, environment, payload}. `payload` is the connector's own
     * exchangeCode()/validateApiKey() output, passed through whole — access_token,
     * token_type, scopes, external_eid/name/url, connection_name, and metadata, which
     * is where a connector puts its expiry. Cherry-picking fields here would silently
     * drop whatever a connector adds next.
     *
     * THIS IS THE ONE NEW ATTACK SURFACE in the per-instance design: every other route
     * either proves a credential against its provider or hands out no secret at all,
     * and this one takes a token on trust. So it is gated on more than reachability:
     *
     *  - the broker key for THIS install, constant-time compared (authed(), as with
     *    every route here). No key configured means closed.
     *  - POST only. A credential must not be deliverable by following a link.
     *  - auth_type from a fixed allowlist. Notably NOT ssh_sealed: those rows are
     *    sealed by SshKey at the point of use and ownToken() refuses to open them, so
     *    a pushed row claiming it would read as connected and never produce a token.
     *  - a non-empty access_token, and a connector key of a sane shape.
     *  - every acceptance logged with connector, account and caller.
     *
     * Registry membership is deliberately NOT a gate. github is not a registry
     * connector — core drives that handshake itself — so requiring one would reject the
     * commonest push with a message ("unknown connector") that reads like a config
     * problem rather than a rule.
     */
    public function receive($params = []): void {
        if (!$this->authed()) return;

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            Flight::jsonError('POST only.', 405); return;
        }

        $body = $this->jsonBody();
        $type = strtolower(trim((string) ($body['connector'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $type)) {
            Flight::jsonError('connector is required and must be a plain connector key.', 400); return;
        }

        $env = (string) ($body['environment'] ?? '');
        if (!in_array($env, self::ENVIRONMENTS, true)) {
            Flight::jsonError('environment must be one of: ' . implode(', ', self::ENVIRONMENTS) . '.', 400); return;
        }

        $payload = $body['payload'] ?? null;
        if (!is_array($payload)) { Flight::jsonError('payload must be an object.', 400); return; }

        $authType = strtolower(trim((string) ($payload['auth_type'] ?? '')));
        if (!in_array($authType, self::RECEIVABLE_AUTH, true)) {
            Flight::jsonError('payload.auth_type must be one of: ' . implode(', ', self::RECEIVABLE_AUTH) . '.', 400); return;
        }
        if ((string) ($payload['access_token'] ?? '') === '') {
            Flight::jsonError('payload.access_token is required.', 400); return;
        }
        $payload['auth_type'] = $authType;

        $id = ConnectionStore::put($type, $env, $payload);
        if ($id <= 0) { Flight::jsonError('The connection could not be stored on this install.', 500); return; }

        $account = (string) ($payload['external_name'] ?? $payload['external_eid'] ?? '');
        Flight::get('log')?->info('connectorapi: accepted a pushed connection', [
            'connector' => $type, 'environment' => $env, 'auth_type' => $authType,
            'account' => $account, 'id' => $id,
            'from' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);

        Flight::jsonSuccess([
            'id'          => $id,
            'connector'   => $type,
            'environment' => $env,
            'account'     => $account,
        ], ucfirst($type) . ' stored on this install.');
    }

    /** POST /connectorapi/disconnect — body {id}. Removes one of this install's rows. */
    public function disconnect($params = []): void {
        if (!$this->authed()) return;

        $id = (int) ($this->jsonBody()['id'] ?? 0);
        if ($id <= 0) { Flight::jsonError('id is required.', 400); return; }

        $gone = ConnectionStore::withOwnDb(function () use ($id) {
            $c = Bean::load('connections', $id);
            if (!$c->id) return false;
            Bean::trash($c);
            return true;
        }, false);

        if (!$gone) { Flight::jsonError('No such connection on this install.', 404); return; }
        Flight::jsonSuccess([], 'Disconnected.');
    }

    // ---- auth --------------------------------------------------------------------

    /**
     * This install's own broker key, from conf/broker.ini.
     *
     * Compared with hash_equals: a timing-safe comparison costs nothing and the
     * alternative leaks the key one byte at a time to anyone patient.
     *
     * No key configured means these routes are CLOSED, not open. An install that
     * has never been issued one has nobody entitled to call it.
     */
    private function authed(): bool {
        $ini = @parse_ini_file(dirname(__DIR__) . '/conf/broker.ini', true) ?: [];
        $key = (string) ($ini['broker']['key'] ?? '');

        if ($key === '') {
            Flight::jsonError('This install has no broker key; the connector API is closed.', 403);
            return false;
        }

        if (!hash_equals($key, $this->bearer())) {
            Flight::get('log')?->warning('connectorapi: bad or missing broker key');
            Flight::jsonError('Forbidden.', 403);
            return false;
        }
        return true;
    }

    private function bearer(): string {
        $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
        return trim((string) ($_SERVER['HTTP_X_BROKER_KEY'] ?? ''));
    }

    private function jsonBody(): array {
        $d = json_decode((string) (file_get_contents('php://input') ?: ''), true);
        return is_array($d) ? $d : [];
    }
}
