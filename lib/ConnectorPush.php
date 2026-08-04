<?php
/**
 * ConnectorPush — core hands a finished credential TO the instance it belongs to.
 *
 * The outbound half of controls/Connectorapi. Phase 2 moved storage onto the
 * instance; core kept the OAuth callback, because the app registration — client
 * id, client secret, the redirect URI the provider will accept — belongs to core
 * and an instance genuinely cannot start that handshake alone. So core stays a
 * LANDING PAD: it completes the exchange and then pushes the result in here,
 * storing nothing of its own. See CONNECTIONS_PER_INSTANCE.md, Phase 3.
 *
 * Why this is not ConnectionStore::putForInstall (which it replaces): that wrote
 * the instance's file directly, which only works while core shares a disk with it.
 * The moment somebody self-hosts, the same connect silently lands nowhere. One
 * door — POST /connectorapi/receive, the instance's own broker key — is the same
 * door on-disk or off, which is what makes ejection real rather than nominal.
 *
 * Why it is not on InstanceAutomations: that class reads an instance's pipelines
 * and durable objects, and its brokerPost() runs the other way (instance → core).
 * This is core → instance credential delivery; it shares neither the direction nor
 * the credential.
 *
 * EVERY failure throws. A push that cannot be made must never come back as a
 * quiet 0 that the caller reports as "connected" — that exact shape is what let
 * GitHub write core's table for a day with nothing objecting.
 */

namespace app;

class ConnectorPush {

    /** The route on the instance that receives an already-validated credential. */
    private const PATH = '/connectorapi/receive';

    private const TIMEOUT = 20;

    /**
     * Push one connection into an instance's own store. Returns the connection id
     * AS ASSIGNED BY THAT INSTANCE (its file, its ids — core has no row for it).
     *
     * @param array $payload the connector's own exchangeCode()/validateApiKey()
     *                       output, passed through verbatim: access_token,
     *                       token_type, scopes, external_eid/name/url, metadata
     *                       (which is where expiry lives), connection_name,
     *                       auth_type.
     * @throws \RuntimeException when the instance cannot be located, cannot be
     *                           authenticated to, cannot be reached, or refuses.
     */
    public static function push(int $instanceId, string $connector, string $env, array $payload): int {
        $connector = strtolower(trim($connector));
        if ($connector === '') throw new \RuntimeException('ConnectorPush: no connector named.');
        if ((string) ($payload['access_token'] ?? '') === '') {
            throw new \RuntimeException('ConnectorPush: refusing to push an empty credential for ' . $connector . '.');
        }

        $target = self::target($instanceId);

        $res = self::post($target['url'] . self::PATH, $target['key'], [
            'connector'   => $connector,
            'environment' => $env,
            'payload'     => $payload,
        ]);

        $id = (int) ($res['data']['id'] ?? 0);
        if ($id <= 0) {
            // A 200 with no id is not a success. Saying so here is the difference
            // between "connected" on screen and a connector that is actually there.
            throw new \RuntimeException('ConnectorPush: ' . $target['url']
                . ' accepted the ' . $connector . ' push but returned no connection id.');
        }

        \Flight::get('log')?->info('ConnectorPush: delivered a connection to an instance', [
            'instance'  => $instanceId,
            'connector' => $connector,
            'env'       => $env,
            'via'       => $target['how'],
            'remote_id' => $id,
        ]);
        return $id;
    }

    /**
     * WHERE to push, and WITH WHICH KEY — resolved together, because they are one
     * answer. Connectorapi authenticates against the broker key in the conf/ of the
     * install that is SERVING, so a URL from one copy and a key from another is a
     * 403 with nothing obviously wrong.
     *
     * A containerized tenant is its own install on its own rootfs: core cannot read
     * its conf/, so the key comes from the encrypted copy on the instance row (the
     * same value the deploy hands the entrypoint — see ProxmoxDeploy::brokerConfig).
     * An instance served from a directory beside core reads that directory.
     *
     * @return array{url:string,key:string,how:string}
     * @throws \RuntimeException — never a guess. "Somewhere on this host, probably"
     *                             is how a credential ends up in the wrong file.
     */
    public static function target(int $instanceId): array {
        if ($instanceId <= 0) throw new \RuntimeException('ConnectorPush: no instance id.');

        $inst = Bean::load('instance', $instanceId);
        if (!$inst->id) throw new \RuntimeException('ConnectorPush: no instance ' . $instanceId . '.');

        // 1. Deployed container: the canonical domain the deploy wrote as BASE_URL.
        $domain = strtolower(trim((string) ($inst->ctDomain ?? '')));
        if ($domain !== '') {
            $sealed = (string) ($inst->brokerKey ?? '');
            if ($sealed === '') {
                throw new \RuntimeException('ConnectorPush: instance ' . $instanceId . ' is deployed to '
                    . $domain . ' but core holds no broker key for it — redeploy it so the key is issued.');
            }
            try {
                $key = (string) EncryptionService::decrypt($sealed);
            } catch (\Throwable $e) {
                throw new \RuntimeException('ConnectorPush: instance ' . $instanceId
                    . "'s stored broker key could not be read: " . $e->getMessage());
            }
            if ($key === '') {
                throw new \RuntimeException('ConnectorPush: instance ' . $instanceId
                    . "'s stored broker key decrypted to nothing.");
            }
            return ['url' => 'https://' . $domain, 'key' => $key, 'how' => 'container'];
        }

        // 2. Served from a directory on this host. The install's OWN config is the
        //    authority on its URL — deriving it from the slug would be a guess that
        //    happens to be right until somebody points a real domain at it.
        $dir = $inst->box()->dir();
        if ($dir === '' || !is_dir($dir)) {
            throw new \RuntimeException('ConnectorPush: instance ' . $instanceId
                . ' is not deployed and has no directory on this host (' . $dir . ').');
        }

        $cfg = @parse_ini_file($dir . '/conf/config.ini', true);
        if (!is_array($cfg)) {
            throw new \RuntimeException('ConnectorPush: could not read ' . $dir . '/conf/config.ini.');
        }
        $url = rtrim((string) ($cfg['app']['baseurl'] ?? ''), '/');
        if (!preg_match('#^https?://[^/]+$#i', $url)) {
            throw new \RuntimeException('ConnectorPush: instance ' . $instanceId
                . ' has no usable [app] baseurl in conf/config.ini (got "' . $url . '").');
        }

        $bini = @parse_ini_file($dir . '/conf/broker.ini', true);
        $key  = is_array($bini) ? (string) ($bini['broker']['key'] ?? '') : '';
        if ($key === '') {
            throw new \RuntimeException('ConnectorPush: instance ' . $instanceId
                . ' has no broker key in conf/broker.ini — its connector API is closed.');
        }

        return ['url' => $url, 'key' => $key, 'how' => 'directory:' . basename($dir)];
    }

    /**
     * One JSON POST, bearing the instance's broker key. Returns the decoded body on
     * success and throws otherwise — including on a body that is not JSON, which is
     * what an authcontrol row at the wrong level looks like (a 303 to /auth/login,
     * HTML, HTTP 200-ish). Reading that as failure-with-a-reason beats reading it
     * as an empty result.
     */
    private static function post(string $url, string $key, array $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,   // a redirect here is a permission bug, not a route
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $key,
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        // No curl_close(): it is deprecated in PHP 8.5 and throws inside the web
        // handler, which surfaces as a bare 404 with an empty log.

        if ($resp === false) {
            throw new \RuntimeException('ConnectorPush: could not reach ' . $url . ' — ' . ($cerr ?: 'no response'));
        }
        $d = json_decode((string) $resp, true);
        if (!is_array($d)) {
            throw new \RuntimeException('ConnectorPush: ' . $url . ' answered HTTP ' . $code
                . ' with a non-JSON body (' . substr(trim(strip_tags((string) $resp)), 0, 160) . ').');
        }
        if ($code >= 400 || ($d['success'] ?? true) === false) {
            throw new \RuntimeException('ConnectorPush: ' . $url . ' refused the push (HTTP ' . $code . ') — '
                . (string) ($d['message'] ?? 'no reason given'));
        }
        return $d;
    }
}
