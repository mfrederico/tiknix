<?php
/**
 * Scan — a browser walks the site and reports what happens BEFORE anyone answers the question. An app runs no browser, so
 * it asks the platform (/brokerinfo/consentscan), which walks the site from its QA host and posts back to /consent/scanresult.
 * The policy stops outside services; it cannot stop this site's own code setting a cookie or filling browser storage, and
 * only a scan sees those. It also reaches pages nobody has opened yet.
 * A stranger's walk may use the route list: anyone can ask for those addresses. The signed-in walk never does, and never
 * as the owner: it is a plain member account of its own (SCAN_EMAIL, an address that takes no mail), follows only links a
 * person could click, and is switched off again when the result arrives. Each row of consentscan is one scan.
 */

namespace app\concepts\consent;

use app\Bean;

class Scan {

    /** Not pages: doors for machines, and this plugin's own. */
    private const NOT_PAGES = ['mcp', 'brokerinfo', 'connectorapi', 'webhook', 'msghook', 'msgtrack', 'pipeline', 'pipelines', 'consent', 'githook', 'api'];
    /** The platform signs in as this (scripts/consent-scan.php): made on first use, marked as Tiknix's, level member. */
    public const SCAN_EMAIL = 'consent-scan@scan.tiknix.invalid';
    public const STALE_MINUTES = 15;
    private const KEEP = 5;

    /** The public pages authcontrol names. @param array $rows authcontrol rows (control, method, level) @return string[] */
    public static function pages(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $control = strtolower((string) $r['control']); $method = strtolower((string) $r['method']); $level = (int) $r['level'];
            if ($method === '*' || $control === '' || in_array($control, self::NOT_PAGES, true) || $level < 101) continue;
            $path = $method === 'index' ? "/{$control}" : "/{$control}/{$method}";
            if (!preg_match('#^/[a-z0-9/_-]+$#', $path)) continue;
            $out[] = $path;
        }
        return $out;
    }

    /** Ask the platform to walk this site. @throws \RuntimeException with its reason */
    public static function request(string $host): void {
        $file = \app\Paths::root() . '/conf/broker.ini';
        $ini = is_file($file) ? (@parse_ini_file($file, true) ?: []) : [];
        $endpoint = (string) ($ini['broker']['endpoint'] ?? ''); $key = (string) ($ini['broker']['key'] ?? '');
        $p = parse_url($endpoint);
        if (empty($p['host']) || $key === '') throw new \RuntimeException('conf/broker.ini has no [broker] endpoint and key: this app cannot reach Tiknix to ask for a scan');
        $url = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/brokerinfo/consentscan';
        $rows = Bean::getAll('SELECT control, method, level FROM authcontrol');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_NOSIGNAL => true,
            CURLOPT_POSTFIELDS => json_encode(['host' => $host, 'pages' => ['visitor' => self::pages($rows)]], JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', "Authorization: Bearer {$key}"]]);
        $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
        if ($body === false) throw new \RuntimeException("Tiknix did not answer: {$err}");
        $j = json_decode((string) $body, true);
        if ($code !== 200 || empty($j['success'])) throw new \RuntimeException((string) ($j['message'] ?? $j['error'] ?? "Tiknix answered {$code}"));
        $r = Bean::dispense('consentscan');
        $r->status = 'running'; $r->startedAt = date('Y-m-d H:i:s'); $r->finishedAt = ''; $r->error = ''; $r->result = '';
        Bean::store($r);
    }

    /** Is this the platform, posting a result? Its key is this app's own broker key. */
    public static function fromPlatform(string $authorization): bool {
        $file = \app\Paths::root() . '/conf/broker.ini';
        $ini = is_file($file) ? (@parse_ini_file($file, true) ?: []) : [];
        $key = (string) ($ini['broker']['key'] ?? '');
        return $key !== '' && hash_equals("Bearer {$key}", $authorization);
    }

    /** Store what the browser saw, add outside services to the list, keep the last few scans. @param string[]|null $listed hosts already listed; null reads them */
    public static function receive(array $result, ?array $listed = null): void {
        // the scan's account is off whenever no scan runs: it signs nobody in and receives nothing (a launch restores it)
        $account = Bean::findOne('member', 'email = ?', [self::SCAN_EMAIL]);
        if ($account) { $account->status = 'disabled'; $account->disabledBy = 'tiknix'; Bean::store($account); }
        $r = Bean::findOne('consentscan', " status = 'running' ORDER BY id DESC ") ?? Bean::dispense('consentscan');
        if (!$r->id) $r->startedAt = date('Y-m-d H:i:s');
        $ok = !empty($result['ok']);
        $r->status = $ok ? 'done' : 'failed';
        $r->finishedAt = date('Y-m-d H:i:s');
        $r->error = $ok ? '' : substr((string) ($result['error'] ?? 'no reason given'), 0, 500);
        $r->result = $ok ? json_encode($result, JSON_UNESCAPED_SLASHES) : '';
        Bean::store($r);
        if ($ok) {
            $listed = $listed ?? array_column(Inventory::all(), 'host');
            foreach (['visitor', 'member'] as $who) foreach ((array) ($result[$who]['hosts'] ?? []) as $host => $h) {
                if (!in_array((string) $host, $listed, true)) Inventory::record((string) $host, (string) ($h['pages'][0] ?? ''), (string) ($h['types'][0] ?? ''));
            }
        }
        $old = array_slice(array_values(Bean::find('consentscan', ' ORDER BY id DESC ')), self::KEEP);
        if ($old) Bean::trashAll($old);
    }

    /** The newest scan as the page shows it: state running|stale|failed|done, and the findings when done. Null: never scanned. */
    public static function latest(array $services, string $sessionCookie): ?array {
        $r = Bean::findOne('consentscan', ' ORDER BY id DESC ');
        if (!$r) return null;
        $state = (string) $r->status;
        if ($state === 'running' && strtotime((string) $r->startedAt) < time() - self::STALE_MINUTES * 60) $state = 'stale';
        $out = ['state' => $state, 'at' => $state === 'done' || $state === 'failed' ? (string) $r->finishedAt : (string) $r->startedAt, 'error' => (string) $r->error];
        if ($state === 'done') {
            $result = json_decode((string) $r->result, true);
            if (!is_array($result)) throw new \UnexpectedValueException("consent: scan #{$r->id} holds no readable result");
            $out += self::judge($result, $services, $sessionCookie);
        }
        return $out;
    }

    /**
     * What the walk means, against the list.
     * @return array{pages:int,broken:array,waiting:array,cookies:array,storage:array,stopped:int}
     *   broken   pages that answered with a server error
     *   waiting  outside services that ran before any answer and are not needed
     *   cookies  cookies there before any answer, other than the two this site needs
     *   storage  what the site's code left in browser storage: its own, so the owner must say whether it is needed
     */
    public static function judge(array $result, array $services, string $sessionCookie): array {
        $groups = []; $names = [];
        foreach ($services as $s) { $groups[$s['host']] = $s['group']; $names[$s['host']] = $s['name']; }
        $out = ['pages' => 0, 'broken' => [], 'waiting' => [], 'cookies' => [], 'storage' => [], 'stopped' => 0];
        foreach (['visitor' => 'A visitor', 'member' => 'Signed in'] as $who => $label) {
            $walk = $result[$who] ?? null;
            if (!is_array($walk)) continue;
            $out['stopped'] += (int) ($walk['stopped'] ?? 0);
            foreach ((array) ($walk['pages'] ?? []) as $p) {
                $out['pages']++;
                if (is_int($p['status'] ?? null) && $p['status'] >= 500) $out['broken'][$p['path']] = ['path' => $p['path'], 'status' => $p['status'], 'who' => $label];
            }
            foreach ((array) ($walk['hosts'] ?? []) as $host => $h) {
                $known = Known::of((string) $host);
                $group = $groups[$host] ?? ($known[0] ?? '');
                if ($group === Groups::NECESSARY || isset($out['waiting'][$host])) continue;
                $out['waiting'][$host] = ['host' => (string) $host, 'name' => $names[$host] ?? ($known[1] ?? (string) $host), 'group' => $group, 'page' => (string) ($h['pages'][0] ?? ''), 'who' => $label];
            }
            foreach ((array) ($walk['cookies'] ?? []) as $name => $c) {
                if (in_array((string) $name, [$sessionCookie, Choice::COOKIE], true) || isset($out['cookies'][$name])) continue;
                $out['cookies'][$name] = ['name' => (string) $name, 'domain' => (string) ($c['domain'] ?? ''), 'page' => (string) ($c['page'] ?? ''), 'who' => $label];
            }
            foreach ((array) ($walk['storage'] ?? []) as $k => $page) {
                [$kind, $key] = explode(':', (string) $k, 2) + [1 => ''];
                if (!isset($out['storage'][$k])) $out['storage'][$k] = ['key' => $key, 'kind' => $kind === 'local' ? 'Kept after closing' : 'Until the tab closes', 'page' => (string) $page, 'who' => $label];
            }
        }
        foreach (['broken', 'waiting', 'cookies', 'storage'] as $k) $out[$k] = array_values($out[$k]);
        return $out;
    }
}
