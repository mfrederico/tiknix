<?php
/**
 * DomainCerts — what TLS each project's domains actually serve, checked from the outside.
 *
 * TLS ends at capricorn on this machine: <slug>.tiknix.com rides the wildcard, a custom domain
 * has a certificate of its own (TenantDomains). Reading those files is not the question a
 * person has — "will the browser trust it, and for how long?" — and the wildcard's files are
 * root's anyway. So each domain is PROBED: a TLS handshake against our own address with the
 * domain as SNI, and the certificate that comes back is what is recorded: its expiry, its
 * subject, and any failure in the handshake's own words. One `domaincert` row per domain,
 * refreshed hourly by core's `domain-certs` pipeline; the dashboard, the project card and the
 * Builder's board read the rows. A domain nothing answers for is a row with an error, not a
 * missing row — "not checked" and "broken" must not look alike.
 */
namespace app;

class DomainCerts {

    /** Days left under which a certificate is "expiring" on every surface. */
    public const WARN_DAYS = 14;

    /** Every domain a project answers on: its hosted name, then its own domains (TenantDomains). */
    public static function domainsOf(object $inst): array {
        $out = [];
        if (trim((string) ($inst->ctDomain ?? '')) !== '') $out[] = (string) $inst->ctDomain;
        foreach (TenantDomains::of($inst) as $d) if (!in_array($d, $out, true)) $out[] = $d;
        return $out;
    }

    /**
     * Probe one domain: the certificate our edge serves for it.
     * @return array{ok:bool, expires_at:string, days_left:?int, subject:string, issuer:string, error:string}
     */
    public static function probe(string $domain, int $timeout = 8): array {
        $addr = TenantDomains::ourAddress();
        $cmd = 'timeout ' . (int) $timeout . ' openssl s_client -connect ' . escapeshellarg($addr . ':443') . ' -servername ' . escapeshellarg($domain)
             . ' -verify_return_error </dev/null 2>&1 | openssl x509 -noout -enddate -subject -issuer -checkhost ' . escapeshellarg($domain) . ' 2>&1';
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        if ($code !== 0 || !preg_match('/notAfter=(.+)/', $text, $m)) {
            $err = str_contains($text, 'Could not read certificate') || trim($text) === ''
                 ? "the edge served no certificate for this name (TLS handshake with {$addr} failed)"
                 : mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 240);
            return ['ok' => false, 'expires_at' => '', 'days_left' => null, 'subject' => '', 'issuer' => '', 'error' => $err];
        }
        $exp = strtotime(trim($m[1]));
        if ($exp === false) return ['ok' => false, 'expires_at' => '', 'days_left' => null, 'subject' => '', 'issuer' => '', 'error' => 'unreadable notAfter: ' . trim($m[1])];
        preg_match('/subject=(.+)/', $text, $s); preg_match('/issuer=(.+)/', $text, $i);
        $days = (int) floor(($exp - time()) / 86400);
        // The served certificate must NAME the domain: the edge answers any name with something,
        // and a wildcard for *.tiknix.com is no good to a custom domain.
        $covers = str_contains($text, 'does match certificate');
        $error = $days <= 0 ? 'expired' : (!$covers ? 'the served certificate does not cover this name (' . trim((string) ($s[1] ?? '')) . ')' : '');
        return ['ok' => $error === '', 'expires_at' => date('Y-m-d H:i:s', $exp), 'days_left' => $days,
                'subject' => trim((string) ($s[1] ?? '')), 'issuer' => trim((string) ($i[1] ?? '')), 'error' => $error];
    }

    /**
     * Probe every live project's domains and keep the result. Rows for domains no project
     * serves any more are removed. @return array{checked:int, failing:string[], removed:int}
     */
    public static function refresh(): array {
        $checked = 0; $failing = []; $seen = [];
        foreach (Bean::find('instance', "ct_kind = 'tenant' AND ct_domain != '' AND (status IS NULL OR status != 'deleted')") as $inst) {
            foreach (self::domainsOf($inst) as $d) {
                $seen[] = $d;
                $r = self::probe($d);
                $row = Bean::findOne('domaincert', 'domain = ?', [$d]) ?: Bean::dispense('domaincert');
                $row->domain = $d; $row->instanceRef = (int) $inst->id; $row->slug = (string) $inst->slug;
                $row->ok = $r['ok'] ? 1 : 0; $row->expiresAt = $r['expires_at']; $row->daysLeft = $r['days_left'];
                $row->subject = $r['subject']; $row->issuer = $r['issuer']; $row->error = $r['error']; $row->checkedAt = date('Y-m-d H:i:s');
                Bean::store($row);
                $checked++;
                if (!$r['ok']) { $failing[] = "{$d}: {$r['error']}"; Flight::get('log')->error('DomainCerts: ' . $d . ' — ' . $r['error'], ['project' => $inst->slug]); }
            }
        }
        $removed = 0;
        foreach (Bean::find('domaincert') as $row) if (!in_array((string) $row->domain, $seen, true)) { Bean::trash($row); $removed++; }
        return ['checked' => $checked, 'failing' => $failing, 'removed' => $removed];
    }

    /**
     * A project's domains with their last check, for a card: count, the worst state, the soonest
     * expiry. 'state' is ok | expiring | failing | unchecked.
     * @return array{count:int, state:string, days_left:?int, domains:array<int,array>}
     */
    public static function summary(object $inst, ?\PDO $core = null): array {
        $domains = self::domainsOf($inst);
        // The rows live in CORE's database. A sidecar's default RedBean connection is the
        // project's own workbench.db (BuildControl::selectInstance), where a `domaincert` query
        // answers nothing in fluid mode and read as "unchecked" — so a sidecar passes core's PDO.
        $rows = [];
        if ($core) {
            $st = $core->prepare('SELECT * FROM domaincert WHERE instance_ref = ?'); $st->execute([(int) $inst->id]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $rows[(string) $r['domain']] = $r;
        } else {
            foreach (Bean::find('domaincert', 'instance_ref = ?', [(int) $inst->id]) as $r) $rows[(string) $r->domain] = $r->export();
        }
        $out = []; $state = 'ok'; $soonest = null; $any = false;
        foreach ($domains as $d) {
            $r = $rows[$d] ?? null;
            if (!$r) { $out[] = ['domain' => $d, 'state' => 'unchecked', 'days_left' => null, 'error' => '', 'checked_at' => '']; if ($state === 'ok') $state = 'unchecked'; continue; }
            $any = true;
            $days = $r['days_left'] === null ? null : (int) $r['days_left'];
            $st = empty($r['ok']) ? 'failing' : ($days !== null && $days <= self::WARN_DAYS ? 'expiring' : 'ok');
            if ($st === 'failing' || ($st === 'expiring' && $state !== 'failing')) $state = $st;
            if ($days !== null && ($soonest === null || $days < $soonest)) $soonest = $days;
            $out[] = ['domain' => $d, 'state' => $st, 'days_left' => $days, 'error' => (string) $r['error'], 'checked_at' => (string) $r['checked_at'], 'expires_at' => (string) $r['expires_at']];
        }
        if (!$any && $domains) $state = 'unchecked';
        return ['count' => count($domains), 'state' => $state, 'days_left' => $soonest, 'domains' => $out];
    }
}
