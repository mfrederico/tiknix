<?php
/**
 * TenantCarry — move a project that lives as a HOST CLONE of core (/var/www/html/default/
 * <slug>.tiknix) into a tenant container on the tiknix-app template (RUNTIME-SPLIT-MAP.md step 5).
 * Same slug, same instance row, same domain once cut over; the host clone is left as it was.
 *
 *   inventory()  what the project has beyond core: its OWN files (paths core never had), and
 *                core files it EDITED (a path core has, holding content no core commit ever had).
 *                Everything else in the clone is core at some version — the runtime replaces it.
 *   seed()       the app repository: the template made into this app (TenantApp::prepareRepo),
 *                the project's own files, the edited core files kept under .carry/edited/ for
 *                porting, CARRY.md naming each one — pushed to the origin as branch `app`. The
 *                origin's `main` stays the host clone's (core's builder still merges there).
 *   data()       the project's data into the provisioned tenant: SQLite databases (a consistent
 *                backup of each, never a copy of a live file), the connections store and its key,
 *                uploads, site configs, its own conf/*.ini (not core's copies: those hold core's
 *                credentials), and config.ini with the tenant's base URL.
 *   cutover()    the databases once more (the host site kept taking writes), the real base URL,
 *                and the domain(s) proxied to the tenant.
 *   rollback()   the proxy files removed: the host clone serves the domain again.
 *
 * Porting the edited core files is work, not a step: each becomes an extension point or goes
 * (CARRY.md lists them; cat-poo-box's table in RUNTIME-SPLIT-MAP.md §5d is the pattern).
 */

namespace app;

class TenantCarry {

    const HOSTS = '/var/www/html/default';
    /** The core repository host clones branched from (every branch of it: its whole history). */
    const CORE = '/var/www/html/default/tiknix';
    const BRANCH = 'app';

    /** Paths never carried as code: data, the host's, the builder's, or regenerated. */
    const NOT_CODE = ['vendor/', 'data/', 'database/', 'log/', 'logs/', 'cache/', 'secure/', 'conf/',
        'backups/', 'storage/', 'public/uploads/', 'uploads/', '.aibuilder/', 'bin/', 'docker/', 'sql/',
        '.mcp.json', 'CLAUDE.md', 'concepts.lock', '.release', 'composer.json', 'composer.lock', '.gitignore',
        '.fpm-isolated'];   // the host's isolated-pool marker: a tenant is its own pool

    /** Run in the tenant: trash authcontrol rows whose controller is in no controls/ dir. */
    const PRUNE_PHP = <<<'PHP'
require "vendor/autoload.php"; new \app\Bootstrap();
$have = [];
foreach (array_merge(["controls", "vendor/tiknix/runtime/controls"], glob("concepts/*/controls") ?: []) as $d)
    foreach (glob("$d/*.php") ?: [] as $f) $have[strtolower(basename($f, ".php"))] = true;
$gone = [];
foreach (\app\Bean::findAll("authcontrol") as $row) {
    if (isset($have[strtolower((string) $row->control)])) continue;
    $gone[(string) $row->control] = ($gone[(string) $row->control] ?? 0) + 1;
    \app\Bean::trash($row);
}
ksort($gone);
echo $gone ? "permission rows for controllers that are nowhere, removed: " . implode(", ", array_map(fn($k, $n) => "$k ($n)", array_keys($gone), $gone)) : "permission rows: none orphaned";
if ($gone) passthru("php scripts/resetcache.php >/dev/null");
PHP;

    /** conf/ files that are the host's or the builder's, never the app's. */
    const HOST_CONF = ['config.ini', 'aibuilder.ini', 'broker.ini', 'nginx-fpm.conf', 'proxmox.ini'];

    public static function hostDir(string $slug): string {
        $dir = self::HOSTS . '/' . $slug . '.tiknix';
        if (is_link($dir)) throw new \RuntimeException("{$dir} is a symlink, not a project");
        if (!is_dir("{$dir}/.git")) throw new \RuntimeException("no host clone at {$dir}");
        return $dir;
    }

    /** @return array{dir:string,head:string,own:string[],edited:string[],core:int} */
    public static function inventory(string $slug): array {
        $dir = self::hostDir($slug);
        [$blobs, $paths] = self::coreHistory();
        $own = []; $edited = []; $core = 0;
        foreach (self::lines(self::gitOut($dir, 'ls-tree -r HEAD')) as $l) {
            if (!preg_match('/^\d+ blob ([0-9a-f]{40})\t(.+)$/', $l, $m)) continue;
            [$sha, $path] = [$m[1], $m[2]];
            if (self::notCode($path)) continue;
            if (!isset($paths[$path])) $own[] = $path;
            elseif (!isset($blobs[$sha])) $edited[] = $path;
            else $core++;
        }
        return ['dir' => $dir, 'head' => trim(self::gitOut($dir, 'rev-parse --short HEAD')), 'own' => $own, 'edited' => $edited, 'core' => $core];
    }

    public static function seed(object $inst): array {
        $slug = (string) $inst->slug;
        $inv = self::inventory($slug);
        if (!InstanceRepo::hasOrigin($slug)) return ['ok' => false, 'error' => 'no origin at ' . InstanceRepo::originPath($slug)];
        $origin = InstanceRepo::originPath($slug);
        if (self::sh('git -C ' . escapeshellarg($origin) . ' rev-parse -q --verify refs/heads/' . self::BRANCH)[0] === 0)
            return ['ok' => false, 'error' => "{$origin} already has a branch '" . self::BRANCH . "' — this project was seeded; delete that branch to seed again"];
        $core = (string) parse_url((string) \Flight::get('app.baseurl'), PHP_URL_HOST);
        if ($core === '') return ['ok' => false, 'error' => '[app] baseurl names no host'];

        $steps = [];
        $tmp = sys_get_temp_dir() . '/tenantcarry-' . $slug . '-' . bin2hex(random_bytes(3));
        $work = "{$tmp}/app"; $src = "{$tmp}/src";
        try {
            @mkdir($src, 0700, true);
            $steps[] = TenantApp::prepareRepo($work, $inst, $core);
            $r = self::sh('git -C ' . escapeshellarg($inv['dir']) . ' archive HEAD | tar -x -C ' . escapeshellarg($src));
            if ($r[0] !== 0) throw new \RuntimeException("git archive of {$inv['dir']} failed: {$r[1]}");
            foreach ($inv['own'] as $p) self::copy("{$src}/{$p}", "{$work}/{$p}");
            foreach ($inv['edited'] as $p) self::copy("{$src}/{$p}", "{$work}/.carry/edited/{$p}");
            // Which plugins it has and which are on: its lock, with the concepts/ it names (own files).
            if (is_file("{$src}/concepts.lock")) { self::copy("{$src}/concepts.lock", "{$work}/concepts.lock"); $steps[] = 'concepts.lock carried'; }
            file_put_contents("{$work}/CARRY.md", self::report($inst, $inv));
            $steps[] = count($inv['own']) . ' own file(s) carried; ' . count($inv['edited']) . ' edited core file(s) kept under .carry/edited/ to port; ' . $inv['core'] . ' core file(s) left to the runtime';

            self::must(TenantApp::git($work, 'add -A'), 'git add');
            self::must(TenantApp::git($work, '-c user.email=core@tiknix.local -c user.name=tiknix commit -q -m '
                . escapeshellarg("{$slug}: carried from its host clone at {$inv['head']} onto the tiknix-app template")), 'commit');
            self::must(self::sh('git -C ' . escapeshellarg($work) . ' push -q ' . escapeshellarg($origin) . ' HEAD:refs/heads/' . self::BRANCH . ' 2>&1'), "push to {$origin}");
            $steps[] = "pushed to {$origin} as branch " . self::BRANCH;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'steps' => $steps];
        } finally {
            self::sh('rm -rf ' . escapeshellarg($tmp));
        }
        return ['ok' => true, 'steps' => $steps];
    }

    /**
     * The project's data into its provisioned tenant, and config.ini with base URL https://$domain.
     * $databasesOnly: the cutover's second pass — the databases and connections store again.
     */
    public static function data(object $inst, string $domain, bool $databasesOnly = false): array {
        $slug = (string) $inst->slug;
        $dir = self::hostDir($slug);
        $stage = sys_get_temp_dir() . '/tenantcarry-data-' . $slug . '-' . bin2hex(random_bytes(3));
        $steps = [];
        try {
            @mkdir("{$stage}/root/database", 0755, true);
            // The app's databases (security.db included) and the connections store: consistent backups.
            $dbs = glob("{$dir}/database/*.db") ?: [];
            if (!$dbs) throw new \RuntimeException("{$dir}/database has no *.db — nothing to carry is not a success");
            foreach ($dbs as $db) { self::backup($db, "{$stage}/root/database/" . basename($db)); }
            if (is_file("{$dir}/data/connections.db")) { @mkdir("{$stage}/root/data", 0755, true); self::backup("{$dir}/data/connections.db", "{$stage}/root/data/connections.db"); }
            $steps[] = 'databases: ' . implode(', ', array_map('basename', $dbs)) . (is_file("{$dir}/data/connections.db") ? ', data/connections.db' : '');

            if (!$databasesOnly) {
                foreach (['secure', 'public/uploads', 'uploads', 'data/pipe-runs', 'conf/sites'] as $p) {
                    if (!is_dir("{$dir}/{$p}")) continue;
                    @mkdir(dirname("{$stage}/root/{$p}"), 0755, true);
                    self::must(self::sh('cp -a ' . escapeshellarg("{$dir}/{$p}") . ' ' . escapeshellarg("{$stage}/root/{$p}")), "copy {$p}");
                    $steps[] = "{$p}/";
                }
                [$carried, $coreCopies] = self::appConf($dir);
                foreach ($carried as $f) { @mkdir("{$stage}/root/conf", 0755, true); copy("{$dir}/conf/{$f}", "{$stage}/root/conf/{$f}"); }
                if ($carried) $steps[] = 'conf: ' . implode(', ', $carried);
                if ($coreCopies) $steps[] = "not carried (core's own copies, core's credentials): conf/" . implode(', conf/', $coreCopies);
                @mkdir("{$stage}/root/conf", 0755, true);
                file_put_contents("{$stage}/root/conf/config.ini", self::config($inst, $dir, $domain));
                $steps[] = "conf/config.ini: the tenant's, with the project's settings over it (base URL https://{$domain})";
            }

            $tgz = "{$stage}/data.tgz";
            self::must(self::sh('tar -czf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg("{$stage}/root") . ' .'), 'tar');
            [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && tar -xzf - --no-same-owner --no-overwrite-dir', null, 1800, $tgz);
            if ($c !== 0) throw new \RuntimeException("unpacking in the tenant failed ({$c}): {$o}");
            $steps[] = 'unpacked in the tenant (' . round(filesize($tgz) / 1048576, 1) . ' MB)';
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'steps' => $steps];
        } finally {
            self::sh('rm -rf ' . escapeshellarg($stage));
        }

        // The runtime's seeds against the carried database, the guidance, a fresh FPM.
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --build 2>&1 | tail -40; exit ${PIPESTATUS[0]}', null, 900);
        if ($c !== 0) return ['ok' => false, 'error' => "clitool --build failed on the carried data:\n" . trim($o), 'steps' => $steps];
        $steps[] = 'seeds ran on the carried database';
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && if [ -f concepts.lock ]; then php scripts/clitool.php --concept-seeds=all 2>&1 | tail -20; exit ${PIPESTATUS[0]}; fi', null, 900);
        if ($c !== 0) return ['ok' => false, 'error' => "the plugins' seeds failed on the carried database:\n" . trim($o), 'steps' => $steps];
        if (trim($o) !== '') $steps[] = "plugins' seeds ran";
        // Permission rows for controllers that are nowhere now (control-plane pages the host clone
        // had: teams, workbench, …) would claim routes the app does not answer. Named, then gone.
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php -r ' . escapeshellarg(self::PRUNE_PHP) . ' 2>&1', null, 120);
        if ($c !== 0) return ['ok' => false, 'error' => "pruning permission rows failed: {$o}", 'steps' => $steps];
        $steps[] = trim(implode(' ', array_filter(explode("\n", $o), fn($l) => !str_contains($l, 'Deprecated'))));
        // The guidance regenerated for this app (its plugins) is committed: the tree stays clean,
        // or its first --update refuses on "uncommitted code edits".
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --agent-sync >/dev/null && php scripts/resetcache.php >/dev/null'
            . ' && { git diff --quiet -- CLAUDE.md || git commit -q -m "CLAUDE.md regenerated for the carried app" -- CLAUDE.md; }', null, 300);
        if ($c !== 0) return ['ok' => false, 'error' => "agent guidance / permission cache failed: {$o}", 'steps' => $steps];
        [$c, $o] = TenantHost::ssh($inst, 'root', 'systemctl restart php8.5-fpm', null, 120);
        if ($c !== 0) return ['ok' => false, 'error' => "php-fpm restart failed: {$o}", 'steps' => $steps];
        return ['ok' => true, 'steps' => $steps];
    }

    /** The databases once more, the real base URL, the domain(s) proxied to the tenant. */
    public static function cutover(object $inst, string $domain, array $aliases = []): array {
        $staging = (string) $inst->ctDomain;   // where it was proved; retired once the real domain serves
        $d = self::data($inst, $domain, true);
        if (!$d['ok']) return $d;
        $steps = $d['steps'];
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && sed -i -E ' . escapeshellarg('s#^baseurl *= *.*#baseurl = "https://' . $domain . '"#') . ' conf/config.ini && grep -E "^baseurl" conf/config.ini', null, 60);
        if ($c !== 0) return ['ok' => false, 'error' => "setting the base URL failed: {$o}", 'steps' => $steps];
        $steps[] = trim($o);
        foreach (array_merge([$domain], $aliases) as $host) {
            $p = TenantHost::publish($inst, $host);
            if (!$p['ok']) return ['ok' => false, 'error' => "proxy for {$host}: " . ($p['error'] ?? 'failed'), 'steps' => $steps];
            $steps[] = "{$host} → {$inst->ctIp}";
        }
        if ($staging !== '' && $staging !== $domain && !in_array($staging, $aliases, true)) {
            if (!ProxmoxDeploy::removeProxy($staging)) return ['ok' => false, 'error' => "could not retire the staging host {$staging}", 'steps' => $steps];
            $steps[] = "{$staging} retired";
        }
        $inst->ctDomain = $domain;
        $inst->ctAliases = implode(',', $aliases);
        Bean::store($inst);
        // The host clone no longer serves the site: core's builder must not build into it.
        $marker = InstanceRepo::originPath((string) $inst->slug) . '/' . InstanceRepo::CARRIED_MARKER;
        $rec = ['slug' => (string) $inst->slug, 'container' => (int) $inst->ctVmid, 'ip' => (string) $inst->ctIp,
                'domain' => $domain, 'aliases' => $aliases, 'at' => date('Y-m-d H:i:s')];
        if (file_put_contents($marker, json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false)
            return ['ok' => false, 'error' => "the site is served from the tenant, but {$marker} could not be written — the host clone's builder is NOT blocked", 'steps' => $steps];
        $steps[] = "builder on the host clone blocked ({$marker})";
        return ['ok' => true, 'steps' => $steps];
    }

    /** The proxy files for the domain and its aliases removed: the host clone serves them again. */
    public static function rollback(object $inst): array {
        $hosts = array_values(array_filter(array_merge([(string) $inst->ctDomain], explode(',', (string) $inst->ctAliases))));
        if (!$hosts) return ['ok' => false, 'error' => "{$inst->slug} records no domain proxied to its tenant"];
        foreach ($hosts as $h) if (!ProxmoxDeploy::removeProxy($h)) return ['ok' => false, 'error' => "could not remove the proxy file for {$h}"];
        $steps = array_map(fn($h) => "{$h} → the host clone again", $hosts);
        $marker = InstanceRepo::originPath((string) $inst->slug) . '/' . InstanceRepo::CARRIED_MARKER;
        if (is_file($marker) && !unlink($marker)) return ['ok' => false, 'error' => "the domains are back on the host clone, but {$marker} could not be removed — its builder is still blocked", 'steps' => $steps];
        $steps[] = 'builder on the host clone unblocked';
        $steps[] = 'NOTE: anything written to the tenant since the cutover is not in the host clone';
        return ['ok' => true, 'steps' => $steps];
    }

    // ------------------------------------------------------------------------------------------

    /**
     * conf/*.ini the app owns: [carried, core's]. A name core's conf/ also has — the file, or
     * an .example.ini of it — came from core's provisioning and holds core's credentials (an
     * older copy of them, even when it differs): never carried. Only a file core has no
     * counterpart for is the app's own.
     */
    private static function appConf(string $dir): array {
        $carried = []; $coreCopies = [];
        foreach (glob("{$dir}/conf/*.ini") ?: [] as $f) {
            $b = basename($f);
            if (in_array($b, self::HOST_CONF, true) || preg_match('/^config\..+\.ini$|\.bak|\.example\./', $b)) continue;
            // core's own file, or one core ships an example of (provisioning copied it from there)
            if (is_file(self::CORE . "/conf/{$b}") || is_file(self::CORE . '/conf/' . substr($b, 0, -4) . '.example.ini')) $coreCopies[] = $b;
            else $carried[] = $b;
        }
        return [$carried, $coreCopies];
    }

    /**
     * The tenant's config.ini (the template's, as provisioning wrote it) with the project's own
     * settings over it, key by key — except what belonged to the host or the control plane: the
     * base URL (https://$domain here), the cache's version store (a valkey on the host), [firehose]
     * (reporting to core) and [mail] (never read: mail is a connection). The project's app_key wins
     * when it has one (its encrypted data needs it); when it has none, nothing was encrypted with
     * one and the tenant's own stays. Refuses a host path it cannot rewrite.
     */
    private static function config(object $inst, string $dir, string $domain): string {
        $old = (string) @file_get_contents("{$dir}/conf/config.ini");
        if (trim($old) === '') throw new \RuntimeException("{$dir}/conf/config.ini is missing or empty");
        [$c, $tenant] = TenantHost::ssh($inst, 'app', 'cat /srv/app/conf/config.ini', null, 60);
        if ($c !== 0 || trim($tenant) === '') throw new \RuntimeException("the tenant's conf/config.ini could not be read ({$c}): {$tenant}");
        return self::mergeConfig($tenant, $old, $dir, $domain);
    }

    /** config()'s merge, on the two files' text: the tenant's with the project's ($old, from $dir) over it. */
    public static function mergeConfig(string $tenant, string $old, string $dir, string $domain): string {

        $hostOnly = fn(string $sec, string $key) => in_array($sec, ['firehose', 'mail'], true)
            || ($sec === 'app' && $key === 'baseurl')
            || ($sec === 'cache' && in_array($key, ['version_store', 'redis_host', 'redis_port', 'redis_database', 'redis_password'], true));
        $values = self::iniLines($old, $hostOnly);
        $values['app']['baseurl'] = 'baseurl = "https://' . $domain . '"';

        $have = array_map(fn($keys) => array_fill_keys(array_keys($keys), true), self::iniLines($tenant, fn() => false));
        $out = []; $sec = '';
        foreach (explode("\n", rtrim($tenant, "\n")) as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m)) {
                $sec = trim($m[1]);
                $out[] = $line;
                foreach ($values[$sec] ?? [] as $k => $l) if (!isset($have[$sec][$k])) $out[] = $l;   // the project's extra keys
                continue;
            }
            if (preg_match('/^\s*([A-Za-z0-9_.-]+)\s*=/', $line, $m) && isset($values[$sec][$m[1]])) { $out[] = $values[$sec][$m[1]]; continue; }
            $out[] = $line;
        }
        foreach ($values as $s2 => $keys) {
            if ($s2 === '' || !$keys || isset($have[$s2])) continue;                                         // sections the template lacks
            $out[] = ''; $out[] = "[{$s2}]";
            foreach ($keys as $l) $out[] = $l;
        }
        $ini = str_replace($dir . '/', '/srv/app/', implode("\n", $out) . "\n");
        if (preg_match_all('#^[^;\n]*(/var/www/[^\s"]*|/home/ubuntu/[^\s"]*)#m', $ini, $m))
            throw new \RuntimeException("{$dir}/conf/config.ini names host paths the tenant cannot reach: " . implode(', ', array_unique($m[1])));
        if (parse_ini_string($ini, true, INI_SCANNER_RAW) === false) throw new \RuntimeException('the merged config.ini does not parse');
        return $ini;
    }

    /** @return array<string,array<string,string>>  section => key => the raw line, minus $skip(section, key) */
    private static function iniLines(string $ini, callable $skip): array {
        $out = []; $sec = '';
        foreach (explode("\n", $ini) as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m)) { $sec = trim($m[1]); $out[$sec] ??= []; continue; }
            if (preg_match('/^\s*([A-Za-z0-9_.-]+)\s*=/', $line, $m) && !$skip($sec, $m[1])) $out[$sec][$m[1]] = rtrim($line);
        }
        return $out;
    }

    /** A consistent copy of a live SQLite database (the backup API, not a file copy). */
    private static function backup(string $from, string $to): void {
        $src = new \SQLite3($from, SQLITE3_OPEN_READONLY);
        $dst = new \SQLite3($to);
        if (!$src->backup($dst)) throw new \RuntimeException("SQLite backup of {$from} failed: " . $src->lastErrorMsg());
        $src->close(); $dst->close();
    }

    private static function report(object $inst, array $inv): string {
        $out = "# {$inst->slug}: carried from its host clone\n\n"
            . "Carried by `scripts/tenant.php --carry` from `{$inv['dir']}` at `{$inv['head']}` onto the tiknix-app\n"
            . "template: " . count($inv['own']) . " file(s) of its own came along; " . $inv['core'] . " file(s) were core at some version\n"
            . "and are the runtime's now.\n\n## Core files this project edited — to port\n\n"
            . "Each is kept as the project had it under `.carry/edited/`. None is loaded: port what it\n"
            . "changed into an extension point (a controller of the app's own, a Chrome slot, a Contact\n"
            . "category, a view override) or drop it, then delete it here and under `.carry/edited/`.\n\n";
        foreach ($inv['edited'] ?: ['(none)'] as $p) $out .= "- [ ] `{$p}`\n";
        return $out;
    }

    private static function coreHistory(): array {
        static $cache = null;
        if ($cache) return $cache;
        $blobs = [];
        foreach (self::lines(self::gitOut(self::CORE, 'rev-list --all --objects')) as $l) $blobs[substr($l, 0, 40)] = true;
        $paths = array_fill_keys(self::lines(self::gitOut(self::CORE, 'log --all --name-only --format=')), true);
        return $cache = [$blobs, $paths];
    }

    private static function notCode(string $path): bool {
        foreach (self::NOT_CODE as $p) if ($path === rtrim($p, '/') || str_starts_with($path, $p)) return true;
        return (bool) preg_match('#\.(db|sqlite|log)$#', $path);
    }

    private static function copy(string $from, string $to): void {
        @mkdir(dirname($to), 0755, true);
        if (is_link($from)) { symlink(readlink($from), $to); return; }
        if (!copy($from, $to)) throw new \RuntimeException("could not copy {$from}");
        chmod($to, fileperms($from) & 0777);
    }

    private static function gitOut(string $dir, string $args): string {
        $r = self::sh('git -C ' . escapeshellarg($dir) . ' ' . $args);
        if ($r[0] !== 0) throw new \RuntimeException("git {$args} in {$dir} failed: {$r[1]}");
        return $r[1];
    }

    private static function lines(string $s): array { return array_values(array_filter(explode("\n", $s), 'strlen')); }

    private static function must(array $r, string $what): void {
        if ($r[0] !== 0) throw new \RuntimeException("{$what} failed: {$r[1]}");
    }

    /** @return array{0:int,1:string} */
    private static function sh(string $cmd): array { return TenantApp::sh($cmd); }
}
