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
use app\services\connectors\ConnectorRegistry;

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
                'id'          => (int) $c->id,
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

    /**
     * GET|POST /brokerinfo/connectors — the connectors CORE offers (metadata only, no
     * secrets), so an instance can render Connect buttons for services it can wire up.
     * The instance's own connector .ini files are empty by design; availability is
     * defined by CORE (which holds the OAuth client secrets and does the flow).
     */
    public function connectors($params = []) {
        [$key] = $this->requireBroker(); if (!$key) return;
        $out = [];
        foreach (ConnectorRegistry::all() as $c) {
            $m = $c->meta();
            $out[] = [
                'key'        => $c->key(),
                'label'      => (string) ($m['label'] ?? $c->key()),
                'blurb'      => (string) ($m['blurb'] ?? ''),
                'category'   => (string) ($m['category'] ?? 'Other'),
                'icon'       => (string) ($m['icon'] ?? 'plug'),
                'auth_type'  => (string) ($m['auth_type'] ?? 'oauth'),
                'configured' => (bool) $c->isConfigured(),
            ];
        }
        Flight::json(['connectors' => $out]);
    }

    /**
     * POST /brokerinfo/connectkey — connect an api_key-type connector, driven by the
     * instance. Body: {connector, environment, key}. Core validates + encrypts + stores
     * the credential tagged to the KEY's instance (never a caller-supplied id). The raw
     * key never persists in the clear and never comes back.
     */
    public function connectkey($params = []) {
        [$key, $iid, $mid] = $this->requireBroker(); if (!$key) return;
        $body = $this->jsonBody();
        $type = strtolower(trim((string) ($body['connector'] ?? '')));
        $env  = $this->env((string) ($body['environment'] ?? 'production'));
        $raw  = trim((string) ($body['key'] ?? ''));
        if ($type === '' || $raw === '') { Flight::jsonError('connector and key are required.', 400); return; }

        $connector = ConnectorRegistry::get($type);
        if (!$connector) { Flight::jsonError('Unknown connector: ' . $type, 400); return; }
        if (($connector->meta()['auth_type'] ?? 'oauth') !== 'api_key') {
            Flight::jsonError(ucfirst($type) . ' does not connect with a pasted key.', 400); return;
        }
        try {
            $payload = $connector->validateApiKey($raw);
            $id = ConnectionStore::upsert($type, $mid, $iid, $env, $payload, 'api_key');
        } catch (\Throwable $e) {
            Flight::jsonError($e->getMessage(), 400); return;
        }
        Flight::jsonSuccess([
            'id'          => $id,
            'connector'   => $type,
            'environment' => $env,
            'account'     => (string) ($payload['external_name'] ?? $payload['external_eid'] ?? ''),
        ], ucfirst($type) . ' connected.');
    }

    /**
     * POST /brokerinfo/disconnect — remove one of THIS instance's connections. Body:
     * {connection_id}. Scoped hard to the key's instance_id: a broker key can only ever
     * disconnect its own instance's rows.
     */
    public function disconnect($params = []) {
        [$key, $iid] = $this->requireBroker(); if (!$key) return;
        $cid  = (int) ($this->jsonBody()['connection_id'] ?? 0);
        $conn = Bean::load('connections', $cid);
        if (!$conn->id || (int) $conn->instanceId !== $iid) { Flight::jsonError('Connection not found for this instance.', 404); return; }
        Bean::trash($conn);
        Flight::jsonSuccess([], 'Disconnected.');
    }

    /**
     * POST /brokerinfo/connectintent — begin an OAuth connect, driven by the instance.
     * Body: {connector, environment, shop, return_url}. Mints a short-lived signed
     * intent (identity = the KEY's instance/owner, never caller-supplied) and returns a
     * one-time handoff URL on core. The browser follows it; core runs the OAuth with ITS
     * client secret and stores the credential tagged to this instance, then returns to
     * return_url. The instance never touches a token.
     */
    public function connectintent($params = []) {
        [$key, $iid, $mid] = $this->requireBroker(); if (!$key) return;
        $body = $this->jsonBody();
        $type = strtolower(trim((string) ($body['connector'] ?? '')));
        $env  = $this->env((string) ($body['environment'] ?? 'production'));
        $shop = trim((string) ($body['shop'] ?? ''));

        $connector = ConnectorRegistry::get($type);
        if (!$connector) { Flight::jsonError('Unknown connector: ' . $type, 400); return; }
        if (($connector->meta()['auth_type'] ?? 'oauth') !== 'oauth') {
            Flight::jsonError(ucfirst($type) . ' connects with a pasted key, not a sign-in.', 400); return;
        }
        if (!$connector->isConfigured()) {
            Flight::jsonError(ucfirst($type) . ' is not configured on this server.', 400); return;
        }
        // return_url must be https on THIS instance's OWN host — no open redirect.
        $returnUrl = $this->safeInstanceUrl((string) ($body['return_url'] ?? ''), $iid);
        if ($returnUrl === '') { Flight::jsonError('return_url must be an https URL on this instance.', 400); return; }

        $intent = OAuthStateService::issue([
            'purpose'     => 'connect_handoff',
            'provider'    => $type,
            'instance_id' => $iid,
            'member_id'   => $mid,
            'environment' => $env,
            'shop'        => $shop,
            'return_url'  => $returnUrl,
        ]);
        $base = rtrim((string) (Flight::get('app.baseurl') ?: 'https://tiknix.com'), '/');
        Flight::jsonSuccess(['url' => $base . '/connections/handoff/' . rawurlencode($type) . '?intent=' . urlencode($intent)]);
    }

    /** Return $url only if it's https on the instance's OWN configured host, else ''. */
    private function safeInstanceUrl(string $url, int $instanceId): string {
        $inst = \RedBeanPHP\R::load('instance', $instanceId);
        if (!$inst->id) return '';
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return '';
        $dir = '/var/www/html/default/' . $inst->slug . '.' . ((string) ($inst->app ?: 'tiknix'));
        $ini = @parse_ini_file($dir . '/conf/config.ini', true) ?: [];
        $expected = strtolower((string) parse_url((string) ($ini['app']['baseurl'] ?? ''), PHP_URL_HOST));
        if ($expected === '' || strtolower((string) $p['host']) !== $expected) return '';
        return $url;
    }

    /** Resolve + gate the broker key. Returns [keyBean, instanceId, memberId] or nulls (after sending the error). */
    private function requireBroker(): array {
        $key = $this->brokerKey();
        if (!$key) { Flight::jsonError('Forbidden.', 403); return [null, 0, 0]; }
        $iid = (int) ($key->instanceId ?? 0);
        if ($iid <= 0) { Flight::jsonError('This broker key is not bound to an instance.', 403); return [null, 0, 0]; }
        return [$key, $iid, (int) ($key->memberId ?? 0)];
    }

    /** Decode the JSON request body (broker calls are server-to-server JSON). */
    private function jsonBody(): array {
        $raw = file_get_contents('php://input') ?: '';
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /** Constrain a free-text environment to the known set; default production. */
    private function env(string $env): string {
        $env = strtolower(trim($env));
        return in_array($env, ['development', 'staging', 'production'], true) ? $env : 'production';
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
