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
        if (!in_array($env, ['production', 'development', 'staging'], true)) $env = 'production';
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
