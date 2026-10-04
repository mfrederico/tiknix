<?php
/**
 * TenantApp — a new app, made from the tiknix-app template, registered with the control plane
 * (RUNTIME-SPLIT-MAP.md step 4). The counterpart of TenantHost: this makes the app, that gives
 * it a home.
 *
 *   create()  an instance row (status active, a deploy token) and a bare origin repository
 *             under _origins/<slug>.git holding the template, with its tiknix/runtime
 *             repository pointed at THIS control plane's git endpoint and composer.lock
 *             re-resolved against it — so the tenant's `composer install` fetches the runtime
 *             from core, authenticated by the app's own token.
 *
 * The origin is where the app STARTS; after provisioning, the tenant's own repository is the
 * app's home and the control plane reads from it (TenantHost::ssh), never pushes into it.
 */

namespace app;

class TenantApp {

    const TEMPLATE = '/var/www/html/default/tiknix-app';

    public static function create(string $slug, string $name, int $memberId): array {
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 40) return ['ok' => false, 'error' => "'{$slug}' is not a slug (lowercase letters, digits, hyphens)"];
        if (Bean::findOne('instance', 'slug = ?', [$slug])) return ['ok' => false, 'error' => "instance '{$slug}' already exists"];
        if (InstanceRepo::hasOrigin($slug)) return ['ok' => false, 'error' => 'an origin already exists at ' . InstanceRepo::originPath($slug)];
        if (!is_dir(self::TEMPLATE . '/.git')) return ['ok' => false, 'error' => 'no template repository at ' . self::TEMPLATE];
        $core = (string) parse_url((string) \Flight::get('app.baseurl'), PHP_URL_HOST);
        if ($core === '') return ['ok' => false, 'error' => '[app] baseurl names no host'];

        $inst = Bean::dispense('instance');
        $inst->slug = $slug;
        $inst->displayName = $name;
        $inst->memberId = $memberId;
        $inst->status = 'active';
        $inst->engine = 'claude';
        $inst->createdAt = date('Y-m-d H:i:s');
        // A project lives in its own container from the start: the row says so now, and vmid /
        // address / domain fill in as the container is cloned and published (TenantHost::create).
        // Before this was set with the clone, every page that asks "is this a tenant row?" read
        // a brand-new project as a host clone and looked for a folder that never existed (500).
        $inst->ctKind = 'tenant'; $inst->ctVmid = 0; $inst->ctIp = ''; $inst->ctDomain = '';
        Bean::store($inst);
        GitHttp::deployToken($inst);

        $steps = [];
        $work = sys_get_temp_dir() . '/tenantapp-' . $slug . '-' . bin2hex(random_bytes(3));
        try {
            $steps[] = self::prepareRepo($work, $inst, $core);
            self::git($work, 'add -A');
            self::git($work, '-c user.email=core@tiknix.local -c user.name=tiknix commit -q -m ' . escapeshellarg("{$name}: new app from the tiknix-app template"));

            $origin = InstanceRepo::originPath($slug);
            $r = self::sh('git init -q --bare -b main ' . escapeshellarg($origin));
            if ($r[0] !== 0) throw new \RuntimeException("could not create {$origin}: {$r[1]}");
            $r = self::sh('git -C ' . escapeshellarg($work) . ' push -q ' . escapeshellarg($origin) . ' HEAD:main 2>&1');
            if ($r[0] !== 0) throw new \RuntimeException("push to {$origin} failed: {$r[1]}");
            $steps[] = "origin {$origin}";
        } catch (\Throwable $e) {
            Bean::trash($inst);
            if (isset($origin) && is_dir($origin)) self::sh('rm -rf ' . escapeshellarg($origin));
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            self::sh('rm -rf ' . escapeshellarg($work));
        }
        return ['ok' => true, 'inst' => $inst, 'steps' => $steps];
    }

    /**
     * A working copy of the template at $work made into THIS app: its tiknix/runtime repository
     * pointed at this control plane's git endpoint and the lock re-resolved there (as the tenant
     * will fetch it, with the app's own deploy token), its name on the config example. Nothing
     * is committed. Throws, naming what failed.
     */
    public static function prepareRepo(string $work, object $inst, string $core): string {
        $slug = (string) $inst->slug;
        $name = (string) ($inst->displayName ?: $slug);
        $r = self::sh('git clone -q ' . escapeshellarg(self::TEMPLATE) . ' ' . escapeshellarg($work));
        if ($r[0] !== 0) throw new \RuntimeException('clone of the template failed: ' . $r[1]);

        $cj = json_decode((string) file_get_contents("{$work}/composer.json"), true);
        if (!is_array($cj)) throw new \RuntimeException("the template's composer.json is not JSON");
        $cj['repositories']['tiknix-runtime'] = ['type' => 'vcs', 'url' => "https://{$core}/git/runtime.git"];
        $cj['name'] = 'tiknix-app/' . $slug;
        file_put_contents("{$work}/composer.json", json_encode($cj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $auth = json_encode(['http-basic' => [$core => ['username' => $slug, 'password' => GitHttp::deployToken($inst)]]]);
        $r = self::sh('cd ' . escapeshellarg($work) . ' && COMPOSER_AUTH=' . escapeshellarg($auth)
            . ' php -d error_reporting=0 $(command -v composer) update tiknix/runtime --no-install --no-interaction 2>&1');
        if ($r[0] !== 0) throw new \RuntimeException("composer could not resolve tiknix/runtime from https://{$core}/git/runtime.git: " . substr($r[1], -600));

        $ex = "{$work}/conf/config.example.ini";
        file_put_contents($ex, preg_replace('/^name = ".*"$/m', 'name = ' . json_encode($name), (string) file_get_contents($ex)));
        return "runtime from https://{$core}/git/runtime.git";
    }

    public static function git(string $dir, string $args): array {
        return self::sh('git -C ' . escapeshellarg($dir) . ' ' . $args);
    }

    /** @return array{0:int,1:string} */
    public static function sh(string $cmd): array {
        exec('env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE bash -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }
}
