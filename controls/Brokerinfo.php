<?php
/**
 * Brokerinfo — the read-only "what is this instance connected to?" lookup.
 *
 * An instance authenticates with its OWN broker key (conf/broker.ini, the same
 * `brk_` capability it uses for tool calls) and gets back its connections'
 * METADATA only: connector, environment, account name, status. Never a token, never
 * another instance's rows — the instance_id comes from the KEY, not the caller.
 *
 * This exists because core and instances are separate apps with separate databases:
 * credentials stay encrypted in core and are reached through the broker, so an
 * instance otherwise has no way to SEE what it's wired to. Custody is unchanged —
 * this returns data, exactly like every other broker response.
 *
 * authcontrol: brokerinfo::connections = 101 (self-authenticating via the broker key).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Brokerinfo extends Control {

    /** GET|POST /brokerinfo/connections — the calling instance's connections (metadata only). */
    public function connections($params = []) {
        $key = $this->brokerKey();
        if (!$key) { Flight::jsonError('Forbidden.', 403); return; }
        $instanceId = (int) ($key->instanceId ?? 0);
        if ($instanceId <= 0) { Flight::jsonError('This broker key is not bound to an instance.', 403); return; }

        $out = [];
        foreach (Bean::find('connections', 'instance_id = ? ORDER BY connector_type, environment', [$instanceId]) as $c) {
            $out[] = [
                'connector'   => (string) $c->connectorType,
                'environment' => (string) ($c->environment ?: 'production'),
                'name'        => (string) ($c->externalName ?: $c->externalEid),
                'url'         => (string) ($c->externalUrl ?? ''),
                'enabled'     => (int) $c->enabled === 1,
                'revoked'     => !empty($c->revokedAt),
                'last_used'   => (string) ($c->lastUsedAt ?? ''),
            ];
        }
        Flight::json(['instance_id' => $instanceId, 'connections' => $out]);
    }

    /** Resolve the caller's broker key from the Authorization bearer (hash lookup — the raw key is never stored). */
    private function brokerKey() {
        $h = '';
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        foreach ($headers as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $h = (string) $v; break; } }
        if ($h === '') $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $token = stripos($h, 'bearer ') === 0 ? trim(substr($h, 7)) : '';
        if ($token === '') return null;
        $key = Bean::findOne('apikey', 'token_hash = ? AND key_class = ? AND is_active = 1',
            [EncryptionService::hashHex($token), 'broker']);
        return ($key && $key->id) ? $key : null;
    }
}
