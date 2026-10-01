<?php
/**
 * TenantDomains — point another domain at a project's container, as its own site.
 *
 * The app serves it with a config and data of its own (the runtime's app\Host:
 * conf/hosts/<domain>.ini, hosts/<domain>/ — clitool --host-add in the container). Here, on
 * core, the rest of what a domain needs:
 *
 *   1. DNS    the domain resolves to this server — checked, never assumed: a certificate
 *             request for a domain that points elsewhere fails at Let's Encrypt and counts
 *             against its rate limit.
 *   2. TLS    a subdomain of a domain whose wildcard capricorn already serves needs nothing;
 *             any other gets its own certificate from Let's Encrypt over HTTP-01 (capricorn
 *             answers /.well-known/acme-challenge from ACME_WEBROOT), written by lego where
 *             capricorn looks for it (CERT_DIR/<domain>.pem).
 *   3. The app  --host-add in the container: its config, its database.
 *   4. Routing  capricorn's .proxy.<name> file → the container.
 *
 * The domains live on the instance row (ct_hosts, JSON). Removing one stops the routing and
 * moves its config aside in the app; its data stays there.
 */

namespace app;

class TenantDomains {

    public const CERT_DIR     = '/etc/letsencrypt/lego/certificates';
    public const LEGO_PATH    = '/etc/letsencrypt/lego';
    public const ACME_WEBROOT = '/var/www/letsencrypt';
    /** Served by capricorn's default wildcard: their subdomains need no certificate of their own. */
    public const WILDCARD_ZONES = ['tiknix.com'];

    /** @return string[] the domains a project serves as their own sites */
    public static function of(object $inst): array {
        $j = json_decode((string) ($inst->ctHosts ?? ''), true);
        return is_array($j) ? array_values(array_filter(array_map('strval', $j))) : [];
    }

    public static function add(object $inst, string $domain): array {
        $steps = [];
        $d = Host::normalize($domain);
        if (!Host::valid($d)) return ['ok' => false, 'error' => "'{$domain}' is not a domain name"];
        if (!\Model_Instance::tenantRow($inst)) return ['ok' => false, 'error' => "{$inst->slug} does not live in its own container"];
        if ($d === (string) $inst->ctDomain) return ['ok' => false, 'error' => "{$d} is already {$inst->slug}'s main site"];
        if (in_array($d, self::of($inst), true)) return ['ok' => false, 'error' => "{$inst->slug} already serves {$d}"];
        $owner = self::proxyOwner($d);
        if ($owner !== '' && $owner !== (string) $inst->ctIp) return ['ok' => false, 'error' => "{$d} is already routed to {$owner} (" . ProxmoxDeploy::proxyPath($d) . ')'];

        // 1) DNS
        $ours = self::ourAddress();
        if ($ours === '') return ['ok' => false, 'error' => "cannot tell this server's public address: [app] baseurl's host does not resolve"];
        $ip = gethostbyname($d);
        if ($ip === $d) return ['ok' => false, 'error' => "{$d} does not resolve yet — give it an A record for {$ours}, then try again"];
        if ($ip !== $ours) return ['ok' => false, 'error' => "{$d} resolves to {$ip}, not this server ({$ours}) — point its A record at {$ours}, then try again"];
        $steps[] = "DNS: {$d} → {$ip}";

        // 2) TLS
        $tls = self::ensureCert($d);
        if (!$tls['ok']) return ['ok' => false, 'error' => $tls['error'], 'steps' => $steps];
        $steps[] = 'TLS: ' . $tls['step'];

        // 3) the app: its own config and database
        [$code, $out] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --host-add=' . escapeshellarg($d) . ' 2>&1', null, 600);
        if ($code !== 0) return ['ok' => false, 'error' => "the app refused {$d}: " . trim($out), 'steps' => $steps];
        $steps[] = 'app: ' . trim((string) preg_replace('/^# /m', '', $out));

        // 4) routing
        $r = ProxmoxDeploy::writeProxy($d, (string) $inst->ctIp);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] . " — the app has {$d} now; route it and retry", 'steps' => $steps];
        $steps[] = 'routing: ' . $r['step'];

        $inst->ctHosts = json_encode(array_values(array_unique(array_merge(self::of($inst), [$d]))));
        CoreDb::with(fn() => Bean::store($inst));
        return ['ok' => true, 'steps' => $steps, 'url' => 'https://' . $d];
    }

    public static function remove(object $inst, string $domain): array {
        $d = Host::normalize($domain);
        if (!in_array($d, self::of($inst), true)) return ['ok' => false, 'error' => "{$inst->slug} does not serve {$d}"];
        $steps = [];
        if (!ProxmoxDeploy::removeProxy($d)) return ['ok' => false, 'error' => 'could not remove ' . ProxmoxDeploy::proxyPath($d)];
        $steps[] = "routing: {$d} no longer reaches {$inst->slug}";
        [$code, $out] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --host-remove=' . escapeshellarg($d) . ' --yes 2>&1', null, 120);
        if ($code !== 0) return ['ok' => false, 'error' => "the app did not remove {$d}: " . trim($out), 'steps' => $steps];
        $steps[] = 'app: ' . trim((string) preg_replace('/^# /m', '', $out));
        $inst->ctHosts = json_encode(array_values(array_diff(self::of($inst), [$d])));
        CoreDb::with(fn() => Bean::store($inst));
        $steps[] = 'its certificate, if it has one, is left to expire in ' . self::CERT_DIR;
        return ['ok' => true, 'steps' => $steps];
    }

    /** This server's public address: what its own [app] baseurl host resolves to. */
    public static function ourAddress(): string {
        $host = (string) parse_url((string) \Flight::get('app.baseurl'), PHP_URL_HOST);
        if ($host === '') return '';
        $ip = gethostbyname($host);
        return $ip === $host ? '' : $ip;
    }

    /** The address a domain's proxy file already routes to ('' = none). */
    private static function proxyOwner(string $d): string {
        $f = ProxmoxDeploy::proxyPath($d);
        if (!is_file($f)) return '';
        return preg_match('/^proxyhost=(\S+)/m', (string) file_get_contents($f), $m) ? $m[1] : '?';
    }

    /**
     * A certificate capricorn will serve for $d: the wildcard zone's, or one of its own
     * (issued, or kept while it has more than 30 days left — Let's Encrypt allows 5 duplicates
     * a week, so it is never re-issued needlessly).
     */
    public static function ensureCert(string $d): array {
        foreach (self::WILDCARD_ZONES as $z) {
            if (str_ends_with($d, '.' . $z) && substr_count(substr($d, 0, -strlen($z) - 1), '.') === 0) {
                return ['ok' => true, 'step' => "covered by the *.{$z} certificate"];
            }
        }
        $pem = self::CERT_DIR . "/{$d}.pem";
        if (is_file($pem)) {
            exec('openssl x509 -checkend ' . (30 * 86400) . ' -noout -in ' . escapeshellarg(self::CERT_DIR . "/{$d}.crt") . ' 2>&1', $o, $c);
            if ($c === 0) return ['ok' => true, 'step' => "{$d} already has a certificate valid for 30+ days"];
        }
        $email = self::legoEmail();
        if ($email === '') return ['ok' => false, 'error' => 'no lego account in ' . self::LEGO_PATH . '/accounts — cannot request a certificate'];
        // This lego has one command for both: `run` issues a first certificate, and renews an
        // existing one when it is within --renew-days of expiry (options after the command).
        $cmd = 'lego run --accept-tos --ari-disable --no-random-sleep --renew-days 30 --path ' . escapeshellarg(self::LEGO_PATH)
             . ' --pem --email ' . escapeshellarg($email) . ' --http --http.webroot ' . escapeshellarg(self::ACME_WEBROOT)
             . ' -d ' . escapeshellarg($d) . ' 2>&1';
        $before = is_file($pem) ? filemtime($pem) : 0;
        exec($cmd, $out, $code);
        clearstatcache();
        if ($code !== 0 || !is_file($pem) || filemtime($pem) === $before) {
            return ['ok' => false, 'error' => "Let's Encrypt did not " . ($before ? 'renew' : 'issue') . " the certificate for {$d}: " . trim(implode(' ', array_slice($out, -3)))];
        }
        return ['ok' => true, 'step' => ($before ? 'renewed' : 'issued') . " the certificate for {$d} (HTTP-01)"];
    }

    /** When $d's own certificate expires ('' = it has none: a wildcard covers it, or none was issued). */
    public static function certExpires(string $d): string {
        $crt = self::CERT_DIR . "/{$d}.crt";
        if (!is_file($crt)) return '';
        exec('openssl x509 -enddate -noout -in ' . escapeshellarg($crt) . ' 2>&1', $o, $c);
        return $c === 0 && str_contains((string) ($o[0] ?? ''), '=') ? trim(explode('=', $o[0], 2)[1]) : '';
    }

    /**
     * Renew what is due: every custom domain of every project in its own container, run daily
     * from this machine's crontab (tenant.php --renew-certs) — TLS ends at capricorn here, so
     * the certificates are here, not in the containers. A certificate with more than 30 days
     * left is left alone; a failure is logged at ERROR, named, and fails the run.
     *
     * @return array{ok:bool, steps:string[], error:string}
     */
    public static function renewAll(): array {
        $steps = []; $failed = [];
        $rows = CoreDb::with(fn() => array_values(Bean::find('instance', "ct_hosts IS NOT NULL AND ct_hosts != '' AND ct_hosts != '[]' ORDER BY slug")), null);
        if ($rows === null) return ['ok' => false, 'steps' => [], 'error' => "core's registry could not be read: " . CoreDb::lastError()];
        foreach ($rows as $inst) {
            foreach (self::of($inst) as $d) {
                $r = self::ensureCert($d);
                if ($r['ok']) { $steps[] = "{$inst->slug} {$d}: {$r['step']}"; continue; }
                $failed[] = "{$inst->slug} {$d}";
                $steps[] = "{$inst->slug} {$d}: FAILED — {$r['error']}";
                error_log("ERROR TenantDomains::renewAll: {$d} ({$inst->slug}): {$r['error']}");
            }
        }
        if (!$steps) $steps[] = 'no custom domains';
        return ['ok' => !$failed, 'steps' => $steps, 'error' => $failed ? 'not renewed: ' . implode(', ', $failed) : ''];
    }

    private static function legoEmail(): string {
        foreach (glob(self::LEGO_PATH . '/accounts/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $e = basename($dir);
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
        }
        return '';
    }
}
