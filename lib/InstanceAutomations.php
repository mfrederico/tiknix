<?php
/**
 * InstanceAutomations — read an instance's pipeline/durable-object automations from
 * CORE (for the /connections "Integrations" hub). Core has filesystem access to the
 * sibling instance dirs, so it reads the instance's `pipelines/*.json` (via the shared
 * \app\Pipeline\Loader) and its SQLite `dobject` table READ-ONLY — never touching
 * secrets. Actions (Run) go through the instance's own `/pipeline/*` endpoints with
 * its `[pipeline] trigger_secret`, exactly like the pipelines.tiknix sidecar does.
 */

namespace app;

use app\Pipeline\Loader;

class InstanceAutomations {

    /** The instance's pipeline definitions (metadata only). */
    public static function pipelines(string $dir): array {
        $out = [];
        foreach ((new Loader($dir))->all() as $slug => $def) {
            $out[] = [
                'slug'        => (string) $slug,
                'name'        => (string) ($def['name'] ?? $slug),
                'description' => (string) ($def['description'] ?? ''),
                'steps'       => count($def['steps'] ?? []),
                'stateful'    => (bool) ($def['stateful'] ?? false),
                'expose_tool' => (bool) ($def['expose_as_tool'] ?? false),
                'expose_api'  => (bool) ($def['expose_as_api'] ?? false),
                'cron'        => (string) ($def['trigger']['cron'] ?? ''),
                'github'      => $def['trigger']['github'] ?? null,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['slug'], $b['slug']));
        return $out;
    }

    /** Live durable objects from the instance DB (read-only), newest first. */
    public static function durableObjects(string $dir, int $limit = 50): array {
        $db = self::db($dir);
        if (!$db) return [];
        $out = [];
        try {
            $st = $db->query('SELECT type, obj_key, slug, state_json, wake_at, updated_at FROM dobject ORDER BY updated_at DESC LIMIT ' . (int) $limit);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'type'       => (string) $r['type'],
                    'key'        => (string) $r['obj_key'],
                    'slug'       => (string) $r['slug'],
                    'state'      => json_decode((string) $r['state_json'], true),
                    'wake_at'    => (int) $r['wake_at'],
                    'updated_at' => (int) $r['updated_at'],
                ];
            }
        } catch (\Throwable $e) { /* instance has no dobject table yet */ }
        return $out;
    }

    /** Trigger a (non-stateful) pipeline on the instance via its own endpoint. */
    public static function trigger(string $dir, string $slug, array $context = []): array {
        $cfg = self::config($dir);
        if ($cfg['baseurl'] === '' || $cfg['secret'] === '') return ['error' => 'This instance has no [pipeline] trigger_secret.'];
        $ch = curl_init($cfg['baseurl'] . '/pipeline/trigger/' . rawurlencode($slug));
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($context) ?: '{}',
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['secret']],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $d = is_string($resp) ? json_decode($resp, true) : null;
        if ($code === 200 && !empty($d['run_id'])) return ['run_id' => (int) $d['run_id']];
        return ['error' => $d['message'] ?? "trigger failed (HTTP $code)"];
    }

    /**
     * Ask CORE (via this instance's own broker key) what this instance is connected to.
     * Metadata only — connector/environment/account/status, never a credential. This is
     * how an instance can SEE its connections: core and instances are separate apps with
     * separate databases, so the rows live in core and are reached through the broker.
     * Returns ['connections' => [...]] or ['error' => string].
     */
    public static function brokerConnections(string $dir): array {
        $r = self::brokerPost($dir, '/brokerinfo/connections');
        if (!$r['ok']) return ['error' => $r['error']];
        return ['connections' => $r['body']['connections'] ?? []];
    }

    /** Connectors CORE offers (metadata only) so the instance can render Connect buttons. */
    public static function connectors(string $dir): array {
        $r = self::brokerPost($dir, '/brokerinfo/connectors');
        if (!$r['ok']) return ['error' => $r['error']];
        return ['connectors' => $r['body']['connectors'] ?? []];
    }

    /** Begin an OAuth connect: returns ['url' => <handoff URL to redirect to>] or ['error']. */
    public static function connectIntent(string $dir, string $connector, string $env, string $shop, string $returnUrl): array {
        $r = self::brokerPost($dir, '/brokerinfo/connectintent',
            ['connector' => $connector, 'environment' => $env, 'shop' => $shop, 'return_url' => $returnUrl]);
        if (!$r['ok']) return ['error' => $r['error']];
        $url = (string) ($r['body']['data']['url'] ?? '');
        return $url !== '' ? ['url' => $url] : ['error' => 'The control plane did not return a connect URL.'];
    }

    /** Connect an api_key connector with a pasted key. Returns ['data'=>...] or ['error']. */
    public static function connectKey(string $dir, string $connector, string $env, string $key): array {
        $r = self::brokerPost($dir, '/brokerinfo/connectkey',
            ['connector' => $connector, 'environment' => $env, 'key' => $key]);
        return $r['ok'] ? ['data' => $r['body']['data'] ?? []] : ['error' => $r['error']];
    }

    /** Disconnect one of this instance's connections by id. Returns ['ok'=>true] or ['error']. */
    public static function disconnectConnection(string $dir, int $connectionId): array {
        $r = self::brokerPost($dir, '/brokerinfo/disconnect', ['connection_id' => $connectionId]);
        return $r['ok'] ? ['ok' => true] : ['error' => $r['error']];
    }

    /** [base, key, error] from the instance's conf/broker.ini (base=null on error). */
    private static function brokerBase(string $dir): array {
        $ini = @parse_ini_file($dir . '/conf/broker.ini', true) ?: [];
        $endpoint = (string) ($ini['broker']['endpoint'] ?? '');
        $key      = (string) ($ini['broker']['key'] ?? '');
        if ($endpoint === '' || $key === '') return [null, null, 'This instance has no broker key (conf/broker.ini) — connect it from the control plane.'];
        $p = parse_url($endpoint);
        if (empty($p['host'])) return [null, null, 'The broker endpoint in conf/broker.ini is malformed.'];
        return [($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $key, ''];
    }

    /**
     * POST to a core /brokerinfo/* endpoint with this instance's broker key. Returns
     * ['ok'=>bool, 'code'=>int, 'body'=>array, 'error'=>string]. The instance_id is
     * derived server-side from the key — the caller never supplies it.
     */
    public static function brokerPost(string $dir, string $path, array $body = []): array {
        [$base, $key, $err] = self::brokerBase($dir);
        if ($base === null) return ['ok' => false, 'error' => $err];
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body) ?: '{}',
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $key],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        if ($resp === false) return ['ok' => false, 'error' => $cerr ?: 'Could not reach the control plane.'];
        $d = is_string($resp) ? json_decode($resp, true) : null;
        if (!is_array($d)) return ['ok' => false, 'error' => "Unexpected response (HTTP $code)."];
        $ok = $code < 400 && (($d['success'] ?? true) !== false);
        return ['ok' => $ok, 'code' => $code, 'body' => $d, 'error' => $ok ? '' : (string) ($d['message'] ?? "Request failed (HTTP $code).")];
    }

    /** Fire every pipeline whose trigger.github matches this GitHub event + branch. */
    public static function fireGithub(string $dir, string $event, string $branch, array $context): array {
        $fired = [];
        foreach ((new Loader($dir))->all() as $slug => $def) {
            $gh = $def['trigger']['github'] ?? null;
            if (!is_array($gh)) continue;
            $events   = is_array($gh['events'] ?? null)   ? $gh['events']   : ['push'];
            $branches = is_array($gh['branches'] ?? null) ? $gh['branches'] : [];
            if (!in_array($event, $events, true)) continue;
            if ($branches && $branch !== '' && !in_array($branch, $branches, true)) continue;
            $r = self::trigger($dir, (string) $slug, $context);
            $fired[] = ['slug' => (string) $slug, 'run_id' => $r['run_id'] ?? null, 'error' => $r['error'] ?? null];
        }
        return $fired;
    }

    private static function config(string $dir): array {
        $ini = @parse_ini_file($dir . '/conf/config.ini', true) ?: [];
        return ['baseurl' => rtrim((string) ($ini['app']['baseurl'] ?? ''), '/'),
                'secret'  => (string) ($ini['pipeline']['trigger_secret'] ?? '')];
    }

    private static function db(string $dir): ?\PDO {
        $ini = @parse_ini_file($dir . '/conf/config.ini', true) ?: [];
        if (($ini['database']['type'] ?? '') !== 'sqlite') return null;
        $path = (string) ($ini['database']['path'] ?? '');
        if ($path === '') return null;
        $abs = $path[0] === '/' ? $path : $dir . '/' . $path;
        if (!is_file($abs)) return null;
        try { $pdo = new \PDO('sqlite:' . $abs); $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT); return $pdo; }
        catch (\Throwable $e) { return null; }
    }
}
