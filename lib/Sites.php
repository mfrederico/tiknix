<?php
/**
 * Sites — the franchise / location / warehouse / client an install is acting for right now.
 *
 * CONNECTOR-CATALOG-PLAN.md §2c. Every install has at least one site (`main`, seeded); a
 * multi-location business adds rows. Bindings (which Stripe, which mailbox) hang off the
 * current site, so concept code asks for "payments" and gets Denver's Stripe in Denver with
 * nothing in the code knowing Denver exists.
 *
 * The current site is resolved ONCE per request, in this order and no other — cannonwms's
 * base control resolves its warehouse the same way ("single source of truth"):
 *   1. the request's HOST, when it matches a site's `domain`
 *      (serenity-denver.tiknix.com → Denver). A host that matches no site is a 404 — never
 *      the default site: a franchise link that quietly showed another franchise's data is
 *      the worst outcome. Hosts that are the install's own [app] baseurl host (or any host
 *      when no site declares a domain) fall through.
 *   2. the session switcher (the sidebar's "Denver ▾") — switchTo().
 *   3. the member's default site (member.site_ref).
 *   4. the install's default site.
 *
 * A single-site install never sees any of this: no switcher, no site column, no host map.
 */

namespace app;

class SiteNotFoundException extends \RuntimeException {}

class Sites {

    public const DEFAULT_SLUG = 'main';
    public const SLUG_RE = '/^[a-z][a-z0-9-]{1,39}$/D';
    private const SESSION_KEY = 'tiknix.site';

    /** Per-request memo; null = not resolved yet. */
    private static ?\RedBeanPHP\OODBBean $current = null;

    public static function reset(): void { self::$current = null; }

    /** All sites, default first. Empty only on an install whose seeds have not run. */
    public static function all(): array {
        return array_values(Bean::find('site', "ORDER BY CASE WHEN slug = ? THEN 0 ELSE 1 END, name", [self::DEFAULT_SLUG]));
    }

    public static function bySlug(string $slug): ?\RedBeanPHP\OODBBean {
        $s = Bean::findOne('site', 'slug = ?', [$slug]);
        return ($s && $s->id) ? $s : null;
    }

    public static function byId(int $id): ?\RedBeanPHP\OODBBean {
        if ($id <= 0) return null;
        $s = Bean::load('site', $id);
        return $s->id ? $s : null;
    }

    /** The install's default site. Missing = the seed did not run: an error, not a quiet stand-in. */
    public static function default(): \RedBeanPHP\OODBBean {
        $s = self::bySlug(self::DEFAULT_SLUG);
        if (!$s) throw new \RuntimeException("Sites: no default site '" . self::DEFAULT_SLUG . "' — run `php scripts/clitool.php --build` (seed 21_Sites).");
        return $s;
    }

    /** True once a second site exists — the switch that turns the multi-site UI on. */
    public static function multi(): bool {
        return Bean::count('site') > 1;
    }

    /** The site a host names, or null when no site declares that domain. */
    public static function byHost(string $host): ?\RedBeanPHP\OODBBean {
        $host = strtolower(trim(preg_replace('/:\d+$/', '', $host)));
        if ($host === '') return null;
        $s = Bean::findOne('site', 'domain = ?', [$host]);
        return ($s && $s->id) ? $s : null;
    }

    /**
     * The current site for this request (see the order in the class docblock).
     *
     * @throws SiteNotFoundException when the host is a site domain nobody declared
     */
    public static function current(?string $host = null, ?int $memberId = null): \RedBeanPHP\OODBBean {
        if (self::$current !== null) return self::$current;

        $host = $host ?? (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && self::anyDomains()) {
            $byHost = self::byHost($host);
            if ($byHost) return self::$current = $byHost;
            if (!self::isInstallHost($host)) {
                \Flight::get('log')?->warning('Sites: host matches no site', ['host' => $host, 'sites' => self::describe()]);
                throw new SiteNotFoundException("No site answers to {$host}. Sites here: " . self::describe() . '.');
            }
        }

        $switched = (int) ($_SESSION[self::SESSION_KEY] ?? 0);
        if ($switched > 0) {
            $s = self::byId($switched);
            if ($s) return self::$current = $s;
            unset($_SESSION[self::SESSION_KEY]);   // a site that was deleted: the switch is void
        }

        $memberId = $memberId ?? (int) ($_SESSION['member']['id'] ?? 0);
        if ($memberId > 0) {
            $m = Bean::load('member', $memberId);
            if ($m->id && (int) ($m->siteRef ?? 0) > 0) {
                $s = self::byId((int) $m->siteRef);
                if ($s) return self::$current = $s;
            }
        }
        return self::$current = self::default();
    }

    /** The sidebar switcher: choose a site for this session. Refuses a site that is not there. */
    public static function switchTo(int $siteId): \RedBeanPHP\OODBBean {
        $s = self::byId($siteId);
        if (!$s) throw new SiteNotFoundException("Sites: no site #{$siteId} to switch to.");
        $_SESSION[self::SESSION_KEY] = (int) $s->id;
        self::$current = $s;
        return $s;
    }

    /**
     * Create a site. The slug is the stable id (URLs, seeds, promotion to an instance);
     * the domain is optional and unique.
     */
    public static function create(string $slug, string $name, string $domain = '', array $settings = []): \RedBeanPHP\OODBBean {
        $slug = strtolower(trim($slug));
        if (!preg_match(self::SLUG_RE, $slug)) throw new \InvalidArgumentException("Sites: '{$slug}' is not a slug (a-z, then a-z0-9-, 2–40 characters).");
        if (self::bySlug($slug)) throw new \InvalidArgumentException("Sites: a site '{$slug}' already exists.");
        $domain = strtolower(trim($domain));
        if ($domain !== '' && self::byHost($domain)) throw new \InvalidArgumentException("Sites: {$domain} already names a site.");
        $s = Bean::dispense('site');
        $s->slug         = $slug;
        $s->name         = trim($name) !== '' ? trim($name) : $slug;
        $s->domain       = $domain;
        $s->status       = 'active';
        $s->settingsJson = json_encode($settings);
        $s->brandingJson = '{}';
        $s->addressJson  = '{}';
        $s->parentRef    = 0;
        $s->createdAt    = date('Y-m-d H:i:s');
        Bean::store($s);
        return $s;
    }

    /** SQL fragment + params scoping a per-site bean to the current site. */
    public static function where(string $alias = ''): array {
        $col = ($alias !== '' ? $alias . '.' : '') . 'site_ref';
        return ["{$col} = ?", [(int) self::current()->id]];
    }

    // ---- per-site data (CONNECTOR-CATALOG-PLAN.md §2c: "data scoping is the concept's declaration") ----

    /**
     * Make a bean per-site: add `site_ref` (indexed) and stamp every existing row with the
     * default site, so nothing that exists today is orphaned. Idempotent; DDL, so it runs
     * from a seed or the plugin-enable step, never from a request. The identifier is a
     * validated bean name (lowercase alphanumerics), never user input.
     *
     * @return string added | backfilled:<n> | unchanged
     */
    public static function scopeBean(string $bean): string {
        $bean = Bean::normalize($bean);
        if (!preg_match('/^[a-z][a-z0-9]*$/D', $bean)) throw new \InvalidArgumentException("Sites::scopeBean: '{$bean}' is not a bean name.");
        $tables = array_map('strtolower', Bean::inspect());
        if (!in_array($bean, $tables, true)) return 'unchanged';   // the bean's own seed has not created it yet
        $cols = Bean::inspect($bean);
        $out = 'unchanged';
        $table = '`' . $bean . '`';
        if (!array_key_exists('site_ref', $cols)) {
            $ddl = 'ALTER TABLE ' . $table . ' ADD COLUMN site_ref INTEGER DEFAULT 0';
            Bean::exec($ddl);
            $out = 'added';
        }
        $idx = 'CREATE INDEX IF NOT EXISTS idx_' . $bean . '_site_ref ON ' . $table . ' (site_ref)';
        Bean::exec($idx);
        $main = self::bySlug(self::DEFAULT_SLUG);
        if ($main) {
            $count = 'SELECT COUNT(*) FROM ' . $table . ' WHERE site_ref = 0 OR site_ref IS NULL';
            $n = (int) Bean::getCell($count);
            if ($n > 0) {
                $fill = 'UPDATE ' . $table . ' SET site_ref = ? WHERE site_ref = 0 OR site_ref IS NULL';
                Bean::exec($fill, [(int) $main->id]);
                $out = ($out === 'added' ? 'added, ' : '') . "backfilled:{$n}";
            }
        }
        return $out;
    }

    /** Scope a query to the current site: filter('status = ?', ['paid']) → ['site_ref = ? AND (status = ?)', [id, 'paid']]. */
    public static function filter(string $sql = '', array $params = [], string $alias = ''): array {
        [$w, $p] = self::where($alias);
        $sql = trim($sql);
        if ($sql === '') return [$w, $p];
        if (preg_match('/^\s*(ORDER|LIMIT|GROUP)\b/i', $sql)) return [$w . ' ' . $sql, array_merge($p, $params)];
        return [$w . ' AND (' . $sql . ')', array_merge($p, $params)];
    }

    /** Stamp a new row with the current site (a row that already has one is left alone). */
    public static function stamp(\RedBeanPHP\OODBBean $bean): void {
        if ((int) ($bean->siteRef ?? 0) <= 0) $bean->siteRef = (int) self::current()->id;
    }

    // ---- per-site config (a site with its own domain has its own .ini) -----------------------
    //
    // conf/sites/<slug>.ini is merged over the install's config for the request, so
    // mystore2.com answers with its own name, branding, from-address, locale. Only sections a
    // site may own are read — never the database, security, firehose, broker, sidecar or
    // engine sections, and never a credential: those belong to the install and to connections.

    public const CONFIG_SECTIONS = ['app', 'brand', 'mail', 'features', 'locale', 'shop', 'seo'];
    private const CONFIG_KEYS_NEVER = ['mailgun_api_key', 'smtp_password', 'api_key', 'secret', 'secret_key', 'key', 'password', 'token'];

    public static function configPath(\RedBeanPHP\OODBBean $site): string {
        return dirname(__DIR__) . '/conf/sites/' . $site->slug . '.ini';
    }

    /**
     * Apply the site's .ini overlay to this request's runtime config. Returns the keys set.
     * A malformed file is an ERROR naming it — a site that half-applies its config would look
     * like another site. A section or key a site may not own is refused by name, not skipped.
     */
    public static function applyConfig(\RedBeanPHP\OODBBean $site): array {
        $file = self::configPath($site);
        $applied = [];
        if (!is_file($file)) {
            // No overlay: the site's NAME still comes from the site row when it differs from
            // the install's, so a second site is never shown under the first one's name.
            if (self::multi() && (string) $site->name !== '') { \Flight::set('app.name', (string) $site->name); $applied[] = 'app.name'; }
            return $applied;
        }
        $ini = @parse_ini_file($file, true, INI_SCANNER_TYPED);
        if ($ini === false) throw new \RuntimeException("Sites: {$file} is not a valid ini file — site '{$site->slug}' cannot be served with half its config.");
        foreach ($ini as $section => $values) {
            if (!is_array($values)) throw new \RuntimeException("Sites: {$file} has a key outside any section ('{$section}'); every key belongs to a section.");
            if (!in_array($section, self::CONFIG_SECTIONS, true)) {
                throw new \RuntimeException("Sites: {$file} sets [{$section}], which a site may not own (allowed: " . implode(', ', self::CONFIG_SECTIONS) . ').');
            }
            foreach ($values as $key => $value) {
                if (in_array(strtolower((string) $key), self::CONFIG_KEYS_NEVER, true)) {
                    throw new \RuntimeException("Sites: {$file} sets [{$section}] {$key} — credentials live in Connections, never in a site's ini.");
                }
                \Flight::set("{$section}.{$key}", $value);
                $applied[] = "{$section}.{$key}";
            }
        }
        if (!isset($ini['app']['name']) && self::multi() && (string) $site->name !== '') { \Flight::set('app.name', (string) $site->name); $applied[] = 'app.name'; }
        return $applied;
    }

    private static function anyDomains(): bool {
        return Bean::count('site', "domain <> ''") > 0;
    }

    /** The install's own host ([app] baseurl), where the default resolution applies. */
    private static function isInstallHost(string $host): bool {
        $base = (string) (\Flight::get('app.baseurl') ?? '');
        $own  = strtolower((string) parse_url($base, PHP_URL_HOST));
        return $own !== '' && $own === strtolower(preg_replace('/:\d+$/', '', $host));
    }

    private static function describe(): string {
        $out = [];
        foreach (self::all() as $s) $out[] = $s->slug . ($s->domain ? " ({$s->domain})" : '');
        return implode(', ', $out);
    }
}
