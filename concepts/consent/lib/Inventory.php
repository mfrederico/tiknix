<?php
/**
 * Inventory — every outside service this site uses, in one list: the ones its own connections need (its storage
 * bucket, its video library, payments, the sign-up check), and the ones browsers reported seeing on its pages
 * (consenthost rows, from the policy's reports). Each has a group: the owner's choice, else what Known says, else
 * none — "needs sorting", and blocked while visitors are asked.
 */

namespace app\concepts\consent;

use app\Bean;

class Inventory {

    /** Rows a site may collect from reports: past this, a flood of made-up hosts is refused (logged), not stored. */
    public const MAX_HOSTS = 300;
    /** Every page asks for the list (its policy is built from it): kept this long, per site, then read again. */
    public const CACHE_SECONDS = 600;

    /** The list, from this site's cache file when it is fresh (cache/consent-<host>.json). */
    public static function cached(): array {
        $file = self::cacheFile();
        // a connection made since (Google Analytics, a video library) counts now, not in ten minutes
        $connections = \app\ConnectionStore::ownDbPath();
        if (is_file($file) && filemtime($file) > time() - self::CACHE_SECONDS && (!is_file($connections) || filemtime($connections) <= filemtime($file))) {
            $list = json_decode((string) file_get_contents($file), true);
            if (is_array($list)) return $list;
            \Flight::get('log')->error("consent: {$file} is not a list; reading it again");
        }
        $list = self::all();
        Settings::noteGroups(array_keys(Banner::inUse($list)));
        $dir = dirname($file);
        // the list itself is good: a cache that cannot be written costs speed, not the page
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || @file_put_contents($file . '.tmp', json_encode($list, JSON_UNESCAPED_SLASHES)) === false || !@rename($file . '.tmp', $file)) {
            \Flight::get('log')->error("consent: cannot write {$file}: the list is read again on every page");
        }
        return $list;
    }

    /** After a change the next page must see. */
    public static function forget(): void { $f = self::cacheFile(); if (is_file($f)) unlink($f); }

    private static function cacheFile(): string {
        $host = preg_replace('/[^a-z0-9.-]/', '', strtolower((string) \app\Host::visitorHost())) ?: 'cli';
        return \app\Paths::root() . "/cache/consent-{$host}.json";
    }

    /**
     * What the app's own connections load in a visitor's browser — needed, never asked.
     * @return array<string,array{0:string,1:string,2:string}> source => [group, name, purpose]
     */
    public static function fromConnections(): array {
        $out = [];
        // what the install itself says it needs (Flight config consent.needed, host => [group, name, purpose]): the control plane's own tools
        foreach ((array) (\Flight::get('consent.needed') ?? []) as $h => $row) $out[(string) $h] = array_values((array) $row);
        $live = \app\ConnectionMentions::liveRows();
        if (isset($live['s3']) && \app\Storage::isConnected()) {
            // the bucket's own address (signed links to private files) and the public one (a CDN in front, when set)
            try {
                $s = \app\Storage::settings();
                $urls = [\app\Storage::url(\app\Storage::key('consent-probe.txt', \app\Storage::PRIVATE_))];
                // a store with no bucket policy and no Public address serves no public files, so there is no second address to list
                if ($s['public_url'] !== '') $urls[] = $s['public_url'];
                elseif ($s['policy']) $urls[] = \app\Storage::url(\app\Storage::key('consent-probe.txt', \app\Storage::PUBLIC_));
                foreach ($urls as $u) {
                    $h = strtolower((string) parse_url($u, PHP_URL_HOST));
                    if ($h !== '') $out[$h] = ['necessary', 'File storage (' . ($s['provider'] ?: 'S3') . ')', 'Keeps the photos and files people upload here.'];
                }
            } catch (\Throwable $e) {
                \Flight::get('log')->error('consent: the storage connection gave no address: ' . $e->getMessage());
            }
        }
        if (isset($live['bunnystream'])) foreach (['iframe.mediadelivery.net', 'video.bunnycdn.com', '*.b-cdn.net'] as $h) $out[$h] = Known::HOSTS[$h];
        if (isset($live['stripe'])) foreach (['js.stripe.com', '*.stripe.com', '*.stripe.network'] as $h) $out[$h] = Known::HOSTS[$h];
        if (isset($live['shopify'])) $out['cdn.shopify.com'] = Known::HOSTS['cdn.shopify.com'];
        if (class_exists(\app\Turnstile::class) && !empty(\app\Turnstile::state()['configured'])) $out['challenges.cloudflare.com'] = Known::HOSTS['challenges.cloudflare.com'];
        return $out;
    }

    /**
     * The whole list, needed first, then by group, then by name.
     * @return list<array{host:string,group:string,name:string,purpose:string,from:string,hits:int,first_seen:string,last_seen:string,page:string,advice:string}>
     */
    public static function all(): array {
        $list = [];
        foreach (self::fromConnections() as $h => [$g, $n, $p]) $list[$h] = ['host' => $h, 'group' => $g, 'name' => $n, 'purpose' => $p, 'from' => 'connection', 'hits' => 0, 'first_seen' => '', 'last_seen' => '', 'page' => '', 'advice' => ''];
        foreach (self::declared(\app\Concepts::collect('consent.services')) as $h => $row) $list[$h] = $row;
        foreach (Bean::findAll('consenthost', ' ORDER BY host ') as $r) {
            $h = (string) $r->host;
            $known = Known::of($h);
            $row = ['host' => $h, 'group' => (string) $r->kind !== '' ? (string) $r->kind : ($known[0] ?? ''),
                    'name' => (string) $r->name !== '' ? (string) $r->name : ($known[1] ?? $h), 'purpose' => (string) $r->purpose !== '' ? (string) $r->purpose : ($known[2] ?? ''),
                    'from' => (string) $r->kind !== '' ? 'owner' : ($known ? 'known' : 'seen'), 'hits' => (int) $r->hits, 'first_seen' => (string) $r->firstSeen,
                    'last_seen' => (string) $r->lastSeen, 'page' => (string) $r->page,
                    'advice' => Known::selfHost($h) ? 'A library or font: serve it from this app instead (/rt/vendor, or public/assets).' : ''];
            if (isset($list[$h])) { $list[$h]['hits'] = $row['hits']; $list[$h]['last_seen'] = $row['last_seen']; $list[$h]['page'] = $row['page']; $list[$h]['first_seen'] = $row['first_seen']; continue; }
            $list[$h] = $row;
        }
        $order = array_flip(array_merge(array_keys(Groups::ALL), ['']));
        uasort($list, fn($a, $b) => [$order[$a['group']] ?? 99, strtolower($a['name'])] <=> [$order[$b['group']] ?? 99, strtolower($b['name'])]);
        return array_values($list);
    }

    /**
     * One row per service for showing: a service with several addresses (Stripe) reads as one, its hosts in a list.
     * $sortable keeps apart the rows an owner can move one host at a time (everything but a connection's).
     */
    public static function merged(array $services, bool $sortable = false): array {
        $out = [];
        foreach ($services as $s) {
            $k = $sortable && $s['from'] !== 'connection' ? 'h:' . $s['host'] : $s['group'] . '|' . $s['name'];
            if (isset($out[$k])) { $out[$k]['hosts'] .= ', ' . $s['host']; continue; }
            $out[$k] = $s + ['hosts' => $s['host']];
        }
        return array_values($out);
    }

    /**
     * What other plugins say they load (collect point consent.services). A service that waits for a yes never loads
     * before one, so no browser would ever report it, and the question would never be asked: it must be declared.
     *   "collect": {"consent.services": [{"level": "PUBLIC", "when": "Tag::active", "data": {"services": {
     *       "www.example.com": {"group": "analytics", "name": "Example", "purpose": "Counts visits."}}}}]}
     */
    public static function declared(array $collected): array {
        $out = [];
        foreach ((array) ($collected['services'] ?? []) as $host => $s) {
            $group = (string) ($s['group'] ?? '');
            if (!Groups::exists($group)) throw new \UnexpectedValueException("consent.services: {$host} names the group '{$group}' (" . implode(', ', array_keys(Groups::ALL)) . ')');
            $out[(string) $host] = ['host' => (string) $host, 'group' => $group, 'name' => (string) ($s['name'] ?? $host), 'purpose' => (string) ($s['purpose'] ?? ''), 'from' => 'connection', 'hits' => 0, 'first_seen' => '', 'last_seen' => '', 'page' => '', 'advice' => ''];
        }
        return $out;
    }

    /** Is any service this site uses one a visitor must say yes to? Then the banner shows. */
    public static function needsAsking(?array $all = null): bool {
        foreach ($all ?? self::all() as $s) if (in_array($s['group'], Groups::asked(), true)) return true;
        return false;
    }

    /**
     * What a page may load from, as policy sources: needed services always, and those in $groups.
     * Known services of an allowed group count even before one is seen (a yes to Statistics lets Google Analytics run).
     * @param string[] $groups
     * @return string[] "https://host" / "https://*.domain"
     */
    public static function sources(array $groups, ?array $all = null): array {
        $groups = array_values(array_unique(array_merge([Groups::NECESSARY], $groups)));
        $hosts = [];
        foreach ($all ?? self::all() as $s) if (in_array($s['group'], $groups, true)) $hosts[$s['host']] = true;
        foreach (Known::HOSTS as $pattern => [$g]) if (in_array($g, $groups, true)) $hosts[$pattern] = true;
        $out = [];
        foreach (array_keys($hosts) as $h) $out[] = 'https://' . $h;
        sort($out);
        return $out;
    }

    /** A browser said a page tried to load from $host: count it (and note where). False when it was refused. */
    public static function record(string $host, string $page, string $directive, ?string $now = null): bool {
        $host = strtolower($host);
        if (!Known::isHost($host)) return false;
        $now = $now ?? date('Y-m-d H:i:s');
        $r = Bean::findOne('consenthost', 'host = ?', [$host]);
        if (!$r) {
            if (Bean::count('consenthost') >= self::MAX_HOSTS) { \Flight::get('log')->error("consent: {$host} reported, but the site already lists " . self::MAX_HOSTS . ' outside services — not stored'); return false; }
            $r = Bean::dispense('consenthost');
            $r->host = $host; $r->kind = ''; $r->name = ''; $r->purpose = ''; $r->hits = 0; $r->firstSeen = $now;
            $new = true;
        }
        // a busy page reports on every view: one write a minute per service is plenty
        if (empty($new) && (string) $r->lastSeen !== '' && strtotime((string) $r->lastSeen) > strtotime($now) - 60) return true;
        $r->hits = (int) $r->hits + 1;
        $r->lastSeen = $now;
        $r->page = substr($page, 0, 300);
        $r->directive = substr($directive, 0, 40);
        Bean::store($r);
        if (!empty($new)) self::forget();   // a service the list did not have: the next page's policy includes it
        return true;
    }

    /**
     * The owner puts a service in a group ('' = back to unsorted). A service newly in a group visitors are asked about
     * means a yes given before did not cover it: everyone is asked again (Settings::bumpVersion).
     * @return bool whether visitors will be asked again
     */
    public static function sort(string $host, string $group): bool {
        $host = strtolower(trim($host));
        if (!Known::isHost($host) && !str_starts_with($host, '*.')) throw new \InvalidArgumentException("'{$host}' is not a host name");
        if ($group !== '' && !Groups::exists($group)) throw new \InvalidArgumentException("no group called '{$group}'");
        $r = Bean::findOne('consenthost', 'host = ?', [$host]);
        if (!$r) { $r = Bean::dispense('consenthost'); $r->host = $host; $r->name = ''; $r->purpose = ''; $r->hits = 0; $r->firstSeen = date('Y-m-d H:i:s'); $r->lastSeen = ''; $r->page = ''; $r->directive = ''; }
        $was = (string) $r->kind !== '' ? (string) $r->kind : (Known::of($host)[0] ?? '');
        $r->kind = $group;
        Bean::store($r);
        self::forget();
        $now = $group !== '' ? $group : (Known::of($host)[0] ?? '');
        if (in_array($now, Groups::asked(), true) && $now !== $was) { Settings::bumpVersion(); return true; }
        return false;
    }
}
