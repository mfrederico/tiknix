<?php
/**
 * TenantHost — an app's own container on the Proxmox node (RUNTIME-SPLIT-MAP.md step 4).
 *
 *   buildTemplate()  the tenant IMAGE: a container from Proxmox's stock ubuntu-24.04-standard
 *                with core's key, tenant/base.sh (PHP 8.5 + nginx + FPM as `app`, Composer,
 *                the app's Claude Code, trimmed) and tenant/seal.sh (own host keys and
 *                machine-id per clone), stopped and converted to a template named
 *                tiknix-base-<YYYYmmddHHMM>
 *   create()     a LINKED clone of the newest template on the internal NAT'd bridge
 *                (10.10.10.<vmid>) — it shares the template's disk and holds only its changes
 *   provision()  tenant/app.sh over SSH, as root: this app's credentials, clone and build
 *   publish()    capricorn's proxy file, so <host> is served from the container
 *   ssh()        run a command in the tenant as `app` (the builder's door) or `root`
 *   destroy()    stop and delete the container, stop serving its host
 *
 * SSH is the exec channel Proxmox's API does not have: the old OCI design had to make
 * containers bootstrap themselves because nothing could run a command inside one. A system
 * container runs sshd, and create() hands it core's key.
 *
 * Every step answers ['ok' => bool, …] and names what went wrong; nothing is retried or
 * substituted.
 */

namespace app;

class TenantHost {

    /** Proxmox's stock OS template the tenant template is built from (node's `local` storage). */
    const OS_TEMPLATE = 'local:vztmpl/ubuntu-24.04-standard_24.04-2_amd64.tar.zst';
    /** Name prefix of tenant templates; the newest (by its timestamp suffix) is the one cloned. */
    const TEMPLATE_PREFIX = 'tiknix-base-';
    const ROOTFS     = 'local-lvm';
    const ROOTFS_GB  = 4;
    const MEMORY_MB  = 1024;
    const SWAP_MB    = 512;
    const CORES      = 2;
    const BRIDGE     = 'vmbr1';
    const SUBNET     = '10.10.10.';
    const GATEWAY    = '10.10.10.1';
    const DNS        = '8.8.8.8';
    /** Core's tenant key: its public half is authorised in every tenant (root at create, app by provision.sh). */
    const KEY        = '/home/ubuntu/.ssh/tiknix_tenant_ed25519';
    const KNOWN      = '/home/ubuntu/.ssh/known_hosts_tenants';
    const BASE_SH    = __DIR__ . '/../tenant/base.sh';
    const SEAL_SH    = __DIR__ . '/../tenant/seal.sh';
    const APP_SH     = __DIR__ . '/../tenant/app.sh';
    /** Container ids never touched: 101 is the control plane itself. */
    const PROTECTED  = [101];

    /** The instance row for a slug, or a refusal naming why it cannot be a tenant. */
    public static function instance(string $slug): array {
        $inst = Bean::findOne('instance', 'slug = ?', [$slug]);
        if (!$inst || !$inst->id) return ['ok' => false, 'error' => "no instance '{$slug}' in the registry"];
        if ((string) $inst->status !== 'active') return ['ok' => false, 'error' => "instance '{$slug}' is {$inst->status}, not active"];
        return ['ok' => true, 'inst' => $inst];
    }

    /** The newest tenant template on the node, or a refusal naming how to build one. */
    public static function currentTemplate(ProxmoxService $pve, string $node): array {
        $best = null;
        foreach ($pve->containers($node) as $c) {
            $name = (string) ($c['name'] ?? '');
            if ((int) ($c['template'] ?? 0) !== 1 || !str_starts_with($name, self::TEMPLATE_PREFIX)) continue;
            if ($best === null || strcmp($name, (string) $best['name']) > 0) $best = $c;
        }
        if ($best === null) return ['ok' => false, 'error' => 'no tenant template on ' . $node . ' — build one: php scripts/tenant.php --build-template'];
        return ['ok' => true, 'vmid' => (int) $best['vmid'], 'name' => (string) $best['name']];
    }

    /**
     * Build a new tenant template: a container from the stock OS template, base.sh + seal.sh
     * run in it over SSH, stopped, converted. Older templates stay (clones depend on them);
     * new tenants clone the newest.
     */
    public static function buildTemplate(): array {
        $pve = ProxmoxService::fromConfig();
        if (!$pve) return ['ok' => false, 'error' => 'conf/proxmox.ini is not configured'];
        $pub = @file_get_contents(self::KEY . '.pub');
        if ($pub === false || trim($pub) === '') return ['ok' => false, 'error' => 'core has no tenant key at ' . self::KEY . '.pub'];
        $node = $pve->node();
        $vmid = self::freeVmid($pve);
        if (!$vmid['ok']) return $vmid;
        $vmid = $vmid['vmid'];
        $ip = self::SUBNET . $vmid;
        $name = self::TEMPLATE_PREFIX . date('YmdHi');
        $steps = [];
        $r = $pve->createCt($node, $vmid, self::OS_TEMPLATE, self::ctParams($name, $ip) + [
            'description' => 'tiknix tenant template (TenantHost::buildTemplate)',
            'rootfs' => self::ROOTFS . ':' . self::ROOTFS_GB,
            'ssh-public-keys' => trim($pub),
            'onboot' => 0,
        ]);
        if (!$r['ok']) return ['ok' => false, 'error' => "create {$vmid} failed: {$r['exit']}\n{$r['log']}"];
        $s = $pve->startCt($node, $vmid);
        if (!$s['ok']) return ['ok' => false, 'error' => "start {$vmid} failed: {$s['exit']}"];
        self::forgetHostKey($ip);
        $w = self::waitForSsh($ip, 120);
        if (!$w['ok']) return $w;
        $steps[] = "container {$vmid} ({$name}) at {$ip}";
        $probe = (object) ['slug' => $name, 'ctIp' => $ip];
        foreach (['base' => self::BASE_SH, 'seal' => self::SEAL_SH] as $what => $file) {
            [$code, $out] = self::ssh($probe, 'root', 'bash -s', (string) file_get_contents($file), 1800);
            $steps[] = trim(implode("\n", array_filter(explode("\n", $out), fn($l) => str_starts_with($l, '==') || str_contains($l, 'PHP ') || str_contains($l, 'Claude Code'))));
            if ($code !== 0) return ['ok' => false, 'error' => "{$what}.sh exited {$code} in {$vmid}", 'steps' => $steps, 'output' => $out];
        }
        $st = $pve->stopCt($node, $vmid);
        if (!$st['ok']) return ['ok' => false, 'error' => "stop {$vmid} failed: {$st['exit']}", 'steps' => $steps];
        $t = $pve->templateCt($node, $vmid);
        if (!$t['ok']) return ['ok' => false, 'error' => "converting {$vmid} to a template failed: {$t['exit']}\n{$t['log']}", 'steps' => $steps];
        self::forgetHostKey($ip);
        $steps[] = "template {$name} = {$vmid}";
        return ['ok' => true, 'vmid' => $vmid, 'name' => $name, 'steps' => $steps];
    }

    public static function create(object $inst): array {
        if ((int) $inst->ctVmid > 0) return ['ok' => false, 'error' => "{$inst->slug} already has container {$inst->ctVmid} ({$inst->ctIp})"];
        $host = substr(preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $inst->slug)), 0, 60);
        return self::cloneContainer($host, 'tiknix app ' . $inst->slug, [], function (int $vmid, string $ip) use ($inst) {
            $inst->ctVmid = $vmid; $inst->ctIp = $ip; $inst->ctKind = 'tenant';
            Bean::store($inst);
        });
    }

    /**
     * A container of the platform's own kind: a linked clone of the newest tenant template, on
     * the tenant network at 10.10.10.<vmid>, started, sshd answering (root, core's tenant key).
     * An app's container (create) and the platform's own machines (QaHost) are made this way.
     *
     * $onCloned(vmid, ip) runs as soon as the clone exists — before it is configured or started —
     * so whoever asked can write the container down even if a later step fails.
     * $settings override ctParams (memory, cores…).
     *
     * @return array{ok:bool,vmid?:int,ip?:string,step?:string,error?:string}
     */
    public static function cloneContainer(string $hostname, string $description, array $settings, callable $onCloned): array {
        $pve = ProxmoxService::fromConfig();
        if (!$pve) return ['ok' => false, 'error' => 'conf/proxmox.ini is not configured'];
        $node = $pve->node();
        $tpl = self::currentTemplate($pve, $node);
        if (!$tpl['ok']) return $tpl;
        $vmid = self::freeVmid($pve);
        if (!$vmid['ok']) return $vmid;
        $vmid = $vmid['vmid'];
        $ip = self::SUBNET . $vmid;
        $c = $pve->cloneCt($node, $tpl['vmid'], $vmid, ['hostname' => $hostname, 'full' => 0,
            'description' => $description . ' (linked clone of ' . $tpl['name'] . ')']);
        if (!$c['ok']) return ['ok' => false, 'error' => "clone of {$tpl['name']} to {$vmid} failed: {$c['exit']}" . ($c['log'] !== '' ? "\n{$c['log']}" : '')];
        $onCloned($vmid, $ip);
        $cfg = $pve->setCtConfig($node, $vmid, $settings + self::ctParams($hostname, $ip) + ['onboot' => 1]);
        if (($cfg['error'] ?? '') !== '') return ['ok' => false, 'error' => "configuring {$vmid} failed: {$cfg['error']}"];
        $s = $pve->startCt($node, $vmid);
        if (!$s['ok']) return ['ok' => false, 'error' => "start {$vmid} failed: {$s['exit']}" . ($s['log'] !== '' ? "\n{$s['log']}" : '')];
        self::forgetHostKey($ip);
        $w = self::waitForSsh($ip, 120);
        if (!$w['ok']) return $w;
        return ['ok' => true, 'vmid' => $vmid, 'ip' => $ip, 'step' => "container {$vmid} at {$ip}: linked clone of {$tpl['name']}, sshd answering after {$w['seconds']}s"];
    }

    /** Stop and delete a container the platform made (never a protected one), and forget its host key. */
    public static function destroyContainer(int $vmid, string $ip): array {
        if ($vmid <= 0) return ['ok' => false, 'error' => 'no container'];
        if (in_array($vmid, self::PROTECTED, true)) return ['ok' => false, 'error' => "refusing to touch protected container {$vmid}"];
        $pve = ProxmoxService::fromConfig();
        if (!$pve) return ['ok' => false, 'error' => 'conf/proxmox.ini is not configured'];
        $node = $pve->node();
        $pve->stopCt($node, $vmid);
        $d = $pve->destroyCt($node, $vmid);
        if (!$d['ok']) return ['ok' => false, 'error' => "destroy {$vmid} failed: {$d['exit']}"];
        self::forgetHostKey($ip);
        return ['ok' => true, 'step' => "container {$vmid} destroyed"];
    }

    /** Settings every tenant container carries (a new one, a template build, a clone). */
    private static function ctParams(string $hostname, string $ip): array {
        return [
            'hostname'   => $hostname,
            'memory'     => self::MEMORY_MB,
            'swap'       => self::SWAP_MB,
            'cores'      => self::CORES,
            'net0'       => 'name=eth0,bridge=' . self::BRIDGE . ',ip=' . $ip . '/24,gw=' . self::GATEWAY,
            'nameserver' => self::DNS,
            'features'   => 'nesting=1',
        ];
    }

    private static function freeVmid(ProxmoxService $pve): array {
        $vmid = $pve->nextId();
        if ($vmid <= 0 || in_array($vmid, self::PROTECTED, true)) return ['ok' => false, 'error' => "Proxmox offered vmid {$vmid}"];
        if ($vmid >= 255) return ['ok' => false, 'error' => "vmid {$vmid} does not fit the 10.10.10.<vmid> address plan"];
        return ['ok' => true, 'vmid' => $vmid];
    }

    /** A reused address is a new machine with new host keys: forget the old one's. */
    private static function forgetHostKey(string $ip): void {
        if (is_file(self::KNOWN)) exec('ssh-keygen -q -R ' . escapeshellarg($ip) . ' -f ' . escapeshellarg(self::KNOWN) . ' 2>/dev/null');
    }

    public static function waitForSsh(string $ip, int $timeout): array {
        $t0 = time();
        while (time() - $t0 < $timeout) {
            $fp = @fsockopen($ip, 22, $errno, $errstr, 2);
            if ($fp) { fclose($fp); return ['ok' => true, 'seconds' => time() - $t0]; }
            sleep(2);
        }
        return ['ok' => false, 'error' => "no sshd on {$ip}:22 after {$timeout}s"];
    }

    /**
     * Run app.sh in the tenant as root (the system layer is the template's). The settings reach it as `export` lines ahead
     * of the script on stdin — never on a command line, where the deploy token would sit in
     * a process list.
     */
    /**
     * The app's crontab — the ONE definition: tenant/app.sh writes it (APP_CRONTAB), terminal()
     * refreshes it. Its schedule (pipeline-cron) and its builder
     * terminal (bin/terminal-bridge.php, kept running: flock admits one, the next minute restarts it).
     */
    const CRONTAB = "* * * * * cd /srv/app && php scripts/pipeline-cron.php >> log/pipeline-cron.log 2>&1\n"
        . "* * * * * cd /srv/app && flock -n /tmp/aib-terminal.lock php vendor/tiknix/runtime/bin/terminal-bridge.php >> log/terminal-bridge.log 2>&1\n";

    /**
     * System software a plugin can ask for (its manifest's requires.system), by name — what core
     * knows how to install in an app's container as root. An app cannot do it itself (it has no
     * root), and none of it is in the base template: each container carries only what its
     * ENABLED plugins asked for (`tenant.php --system`). A name not listed here is refused by
     * name, never skipped.
     *
     *   check    exits 0 when it is already there (then nothing is done)
     *   install  runs as root, idempotent; its last line is reported
     */
    /**
     * What EVERY container has, over the base template, applied by system() before the plugins'
     * recipes — the way a base addition reaches containers made before it. Same shape as
     * SYSTEM_RECIPES.
     */
    public const BASE_RECIPES = [
        // The query cache's version store, shared by php-fpm, cron and pipelines (tenant/base.sh
        // has the same). With apcu each process family kept its own counters, so a pipeline's
        // write reached the web only after query_cache_ttl. Loopback only, 16 MB cap, no
        // persistence (a counter that restarts from zero only invalidates, never serves stale).
        'valkey' => [
            'check'   => 'command -v valkey-server >/dev/null && php -m | grep -qi "^redis$" && grep -q "^version_store = \"valkey\"" /srv/app/conf/config.ini && valkey-cli ping 2>/dev/null | grep -q PONG',
            'install' => 'export DEBIAN_FRONTEND=noninteractive; PHPV=$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"); '
                       . 'apt-get update -qq >/dev/null && apt-get install -y -qq valkey-server php${PHPV}-redis >/dev/null; '
                       . 'printf "bind 127.0.0.1 -::1\nport 6379\nprotected-mode yes\ndaemonize no\nsupervised systemd\ndir /var/lib/valkey\nsave \"\"\nappendonly no\nmaxmemory 16mb\nmaxmemory-policy allkeys-lru\nloglevel notice\nlogfile /var/log/valkey/valkey-server.log\ndatabases 1\n" > /etc/valkey/valkey.conf; '
                       . 'systemctl enable valkey-server >/dev/null 2>&1; systemctl restart valkey-server; valkey-cli ping | grep -q PONG || { echo "valkey did not answer PONG"; exit 1; }; '
                       . 'sed -i \'s/^version_store = .*/version_store = "valkey"/\' /srv/app/conf/config.ini; grep -q "^version_store = \"valkey\"" /srv/app/conf/config.ini || { echo "conf/config.ini has no version_store line to set"; exit 1; }; '
                       . 'systemctl restart "php*-fpm"; echo "installed: $(valkey-server --version | cut -d" " -f1-2), php-redis, version_store = valkey"',
        ],
    ];

    public const SYSTEM_RECIPES = [
        // Headless Chrome for the pdf plugin. Google's .deb from Google's apt repository: Ubuntu's
        // own chromium is a snap, which does not run in these containers.
        'google-chrome' => [
            'check'   => 'command -v google-chrome >/dev/null',
            'install' => 'export DEBIAN_FRONTEND=noninteractive; install -d -m 0755 /etc/apt/keyrings; '
                       . 'curl -fsSL https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor --yes -o /etc/apt/keyrings/google-chrome.gpg; '
                       . 'echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list; '
                       . 'apt-get update -qq >/dev/null && apt-get install -y -qq google-chrome-stable >/dev/null; echo "installed: $(google-chrome --version)"',
        ],
        // An MQTT broker IN the app's container, for a plugin whose product talks MQTT (IoT devices).
        // Not the platform's broker (tiknix's live delivery on the host), which stays one.
        //   listeners  mqttSync(): 1883 on localhost for the app's own code (every domain's topics),
        //              and one websockets listener PER DOMAIN, mount_point "<domain>/", its own
        //              accounts — host nginx sends wss://<domain>/mqtt to it (capricorn xpi/mqtt.conf)
        //   accounts   no anonymous access; who may connect and which topics they may use are the
        //              APP's files, data/mosquitto/<domain>/{passwd,acl} (and _local/ for 1883),
        //              empty at first — nobody gets in until the plugin adds them
        //   reload     the app may run `sudo systemctl reload mosquitto` after changing them, and
        //              nothing else as root
        'mosquitto' => [
            // The broker and what the app may do with it; its listeners — one per domain — are
            // mqttSync()'s, written right after and again whenever the app's domains change.
            'check'   => 'command -v mosquitto >/dev/null && test -f /etc/sudoers.d/tiknix-mosquitto',
            'install' => 'export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >/dev/null && apt-get install -y -qq mosquitto mosquitto-clients >/dev/null; '
                       . 'install -d -o app -g mosquitto -m 0750 /srv/app/data/mosquitto; '
                       . 'echo "app ALL=(root) NOPASSWD: /usr/bin/systemctl reload mosquitto" > /etc/sudoers.d/tiknix-mosquitto; chmod 0440 /etc/sudoers.d/tiknix-mosquitto; '
                       . 'visudo -cf /etc/sudoers.d/tiknix-mosquitto >/dev/null || { rm -f /etc/sudoers.d/tiknix-mosquitto; echo "sudoers rule refused by visudo"; exit 1; }; '
                       . 'systemctl enable mosquitto >/dev/null 2>&1; echo "installed: mosquitto $(dpkg-query -W -f=\'${Version}\' mosquitto)"',
        ],
    ];

    /**
     * Install what this app's INSTALLED plugins ask for in requires.system (SYSTEM_RECIPES), as root
     * in its container — installed, not only enabled: enabling verifies the software is there
     * (requires.commands), so it has to arrive first (install → --system → enable). Idempotent —
     * meant for a daily run. A plugin asking for something core has no recipe for is a failure
     * naming both.
     */
    public static function system(object $inst): array {
        $read = '$l = json_decode((string) @file_get_contents("concepts.lock"), true); if (!is_array($l)) { fwrite(STDERR, "concepts.lock is missing or not JSON\n"); exit(1); } '
              . 'foreach (($l["concepts"] ?? []) as $n => $c) { '
              . '$m = json_decode((string) @file_get_contents("concepts/$n/concept.json"), true); if (!is_array($m)) { fwrite(STDERR, "concepts/$n/concept.json is missing or not JSON\n"); exit(1); } '
              . 'foreach ((array) ($m["requires"]["system"] ?? []) as $s) echo $n, " ", $s, "\n"; }';
        [$c, $o] = self::ssh($inst, 'app', 'cd /srv/app && php -r ' . escapeshellarg($read), null, 30);
        if ($c !== 0) return ['ok' => false, 'error' => "could not read {$inst->slug}'s installed plugins: " . trim((string) $o)];
        $needs = [];   // recipe => [plugins asking]
        foreach (array_filter(array_map('trim', explode("\n", (string) $o))) as $line) {
            [$plugin, $need] = array_pad(explode(' ', $line, 2), 2, '');
            $needs[$need][] = $plugin;
        }
        $steps = [];
        // The base first: what every container has, whether or not a plugin asks for anything.
        foreach (self::BASE_RECIPES as $need => $recipe) {
            [$c, $o] = self::ssh($inst, 'root', 'set -e; if ' . $recipe['check'] . '; then echo "already installed"; exit 0; fi; ' . $recipe['install'], null, 900);
            $last = trim((string) strrchr("\n" . trim((string) $o), "\n"));
            if ($c !== 0) return ['ok' => false, 'steps' => $steps, 'error' => "installing {$need} (base) in {$inst->slug} failed: " . trim((string) $o)];
            $steps[] = "{$need}: {$last} (base)";
        }
        if (!$needs) { $steps[] = 'nothing its installed plugins need beyond the base'; return ['ok' => true, 'steps' => $steps]; }
        foreach ($needs as $need => $plugins) {
            $for = ' (for ' . implode(', ', $plugins) . ')';
            $recipe = self::SYSTEM_RECIPES[$need] ?? null;
            if ($recipe === null) {
                return ['ok' => false, 'steps' => $steps, 'error' => "{$inst->slug}: plugin " . implode(', ', $plugins) . " requires system '{$need}', "
                    . 'which the platform has no recipe for (known: ' . implode(', ', array_keys(self::SYSTEM_RECIPES)) . ') — add one to TenantHost::SYSTEM_RECIPES'];
            }
            [$c, $o] = self::ssh($inst, 'root', 'set -e; if ' . $recipe['check'] . '; then echo "already installed"; exit 0; fi; ' . $recipe['install'], null, 900);
            $last = trim((string) strrchr("\n" . trim((string) $o), "\n"));
            if ($c !== 0) return ['ok' => false, 'steps' => $steps, 'error' => "installing {$need} in {$inst->slug}{$for} failed: " . trim((string) $o)];
            $steps[] = "{$need}: {$last}{$for}";
        }
        if (isset($needs['mosquitto'])) {
            $m = self::mqttSync($inst);
            if (!$m['ok']) return ['ok' => false, 'steps' => $steps, 'error' => $m['error']];
            $steps = array_merge($steps, $m['steps']);
        }
        return ['ok' => true, 'steps' => $steps];
    }

    /** First port of the per-domain websockets listeners in an app's container. */
    public const MQTT_FIRST_PORT = 9001;
    public const MQTT_LAST_PORT  = 9099;

    /**
     * The app's broker listeners, one per domain it serves (its main domain and every other —
     * TenantDomains): each a websockets listener with mount_point "<domain>/" (its devices see
     * only their own domain's topics, as if alone) and its own accounts in
     * data/mosquitto/<domain>/{passwd,acl}; plus 1883 on localhost for the app's own code, which
     * sees every domain's topics under their prefixes (accounts in data/mosquitto/_local/).
     * Each domain keeps its port (instance.ct_mqtt, JSON domain → port); its proxy file gets the
     * port so capricorn's /mqtt reaches it. An app without the broker installed has nothing to
     * sync — its proxy files lose any mqttport line. Run by system() and on every domain change.
     */
    public static function mqttSync(object $inst): array {
        $domains = array_values(array_unique(array_filter(array_merge([(string) $inst->ctDomain], TenantDomains::of($inst)))));
        [$c, $o] = self::ssh($inst, 'root', 'test -f /etc/sudoers.d/tiknix-mosquitto && command -v mosquitto >/dev/null && echo yes || echo no', null, 30);
        if ($c !== 0) return ['ok' => false, 'error' => "cannot ask {$inst->slug}'s container about its broker: " . trim((string) $o)];
        if (trim((string) $o) !== 'yes') {
            foreach ($domains as $d) { $r = ProxmoxDeploy::setMqttPort($d, null); if (!$r['ok']) return $r; }
            return ['ok' => true, 'steps' => ['no broker in this app — no MQTT routes']];
        }

        $had = json_decode((string) ($inst->ctMqtt ?? ''), true);
        $had = is_array($had) ? $had : [];
        $ports = [];
        foreach ($domains as $d) if (isset($had[$d])) $ports[$d] = (int) $had[$d];
        foreach ($domains as $d) {
            if (isset($ports[$d])) continue;
            $p = self::MQTT_FIRST_PORT;
            while (in_array($p, $ports, true)) $p++;
            if ($p > self::MQTT_LAST_PORT) return ['ok' => false, 'error' => "{$inst->slug} has more domains than MQTT ports (" . self::MQTT_FIRST_PORT . '–' . self::MQTT_LAST_PORT . ')'];
            $ports[$d] = $p;
        }

        $base = '/srv/app/data/mosquitto';
        $conf = "# tiknix: the app's broker, one listener per domain (core TenantHost::mqttSync — rewritten, do not edit)\n"
              . "per_listener_settings true\n\n"
              . "# the app's own code: every domain's topics, under their \"<domain>/\" prefixes\n"
              . "listener 1883 127.0.0.1\nallow_anonymous false\npassword_file {$base}/_local/passwd\nacl_file {$base}/_local/acl\n";
        $dirs = ['_local'];
        foreach ($ports as $d => $p) {
            $conf .= "\n# {$d}: wss://{$d}/mqtt\nlistener {$p}\nprotocol websockets\nmount_point {$d}/\nallow_anonymous false\n"
                   . "password_file {$base}/{$d}/passwd\nacl_file {$base}/{$d}/acl\n";
            $dirs[] = $d;
        }
        $mk = '';
        foreach ($dirs as $d) {
            $q = escapeshellarg("{$base}/{$d}");
            $mk .= "install -d -o app -g mosquitto -m 0750 {$q}; for f in passwd acl; do [ -f {$q}/\$f ] || install -o app -g mosquitto -m 0640 /dev/null {$q}/\$f; done; ";
        }
        $script = 'set -e; ' . $mk . 'cat > /etc/mosquitto/conf.d/tiknix-app.conf; systemctl restart mosquitto; sleep 1; '
                . 'systemctl is-active --quiet mosquitto || { journalctl -u mosquitto -n 8 --no-pager; exit 1; }; echo running';
        [$c, $o] = self::ssh($inst, 'root', $script, $conf, 120);
        if ($c !== 0) return ['ok' => false, 'error' => "{$inst->slug}'s broker did not start with its listeners: " . trim((string) $o)];

        foreach ($ports as $d => $p) { $r = ProxmoxDeploy::setMqttPort($d, $p); if (!$r['ok']) return $r; }
        $inst->ctMqtt = json_encode($ports);
        CoreDb::with(fn() => Bean::store($inst));
        $steps = [];
        foreach ($ports as $d => $p) $steps[] = "mqtt: wss://{$d}/mqtt → listener {$p} (topics under {$d}/)";
        return ['ok' => true, 'steps' => $steps];
    }

    /**
     * A checkpoint of the app as it is now, in its container — the ONE way one is taken (the
     * builder page's Save checkpoint, a plan's rollback point). Commits whatever is uncommitted
     * (what an agent changed in the terminal), tags HEAD `$tag` with `$description` as the
     * annotated tag's message (the checkpoint list shows it), and copies each database to
     * .aibuilder/backups/<tag>/ — ignored by apps, so user data is never committable.
     *
     * @param array{name:string,email:string} $author the member making the change — the commit's
     *        author and committer (required: the app's own app@<slug>.invalid says nobody did it)
     * @return array{ok:bool,tag?:string,committed?:bool,databases?:string,error?:string}
     */
    public static function checkpoint(object $inst, string $tag, string $description, array $author): array {
        if (!preg_match('/^checkpoint-[A-Za-z0-9._-]{1,100}$/', $tag)) return ['ok' => false, 'error' => "not a checkpoint name: {$tag}"];
        $description = trim(preg_replace('/\s+/', ' ', $description));
        if ($description === '') $description = 'Checkpoint';
        $git = self::gitAs($author);
        if ($git === null) return ['ok' => false, 'error' => "a checkpoint of {$inst->slug} needs the member making it (name and email) as its author"];
        $php = '$d = ".aibuilder/backups/' . $tag . '"; @mkdir($d, 0700, true); foreach (glob("database/*.db") ?: [] as $f) { '
             . '$s = new SQLite3($f, SQLITE3_OPEN_READONLY); $o = new SQLite3($d . "/" . basename($f)); '
             . 'if (!$s->backup($o)) { fwrite(STDERR, "backup of $f failed\n"); exit(1); } echo basename($f), " "; }';
        $script = 'set -e; cd /srv/app; '
            . 'if git rev-parse -q --verify ' . escapeshellarg("refs/tags/{$tag}") . ' >/dev/null; then echo "checkpoint ' . $tag . ' already exists" >&2; exit 3; fi; '
            . 'git add -A; '
            . 'if git diff --cached --quiet; then echo COMMITTED=0; else ' . $git . ' commit -q -m ' . escapeshellarg("checkpoint: {$description}") . '; echo COMMITTED=1; fi; '
            . $git . ' tag -a ' . escapeshellarg($tag) . ' -m ' . escapeshellarg($description) . '; '
            . 'echo "DATABASES=$(php -r ' . escapeshellarg($php) . ')"';
        [$code, $out] = self::ssh($inst, 'app', $script . ' 2>&1', null, 300);
        $out = (string) $out;
        if ($code !== 0) return ['ok' => false, 'error' => "could not checkpoint {$inst->slug}'s container (exit {$code}): " . trim($out)];
        preg_match('/^COMMITTED=(\d)/m', $out, $c);
        preg_match('/^DATABASES=(.*)$/m', $out, $d);
        return ['ok' => true, 'tag' => $tag, 'committed' => ($c[1] ?? '0') === '1', 'databases' => trim($d[1] ?? '')];
    }

    /**
     * Roll the app in its container back to checkpoint $tag, by $author (the member doing it).
     *
     *   1. refuse while a build task is in progress (a worktree under .aibuilder/wt);
     *   2. checkpoint the app as it is now (checkpoint-before-rollback-…) — the rollback is undone
     *      by rolling back to that;
     *   3. the code: every tracked file restored to the checkpoint's (files added since are
     *      removed), committed forward as "rollback: to <tag>" — history is kept, nothing is lost;
     *   4. the data: each database restored from .aibuilder/backups/<tag>/ when the checkpoint has
     *      a copy (manual and plan checkpoints do; a runtime update's does not — 'data' => false
     *      says so, and the data is left as it is);
     *   5. composer install when the dependencies differ, then the permission cache reset.
     *
     * @return array{ok:bool,tag?:string,before?:string,commit?:string,data?:bool,databases?:string,runtime?:string,error?:string}
     *         runtime: the runtime release reinstalled ('' = unchanged)
     */
    public static function rollback(object $inst, string $tag, array $author): array {
        if (!preg_match('/^checkpoint-[A-Za-z0-9._-]{1,100}$/', $tag)) return ['ok' => false, 'error' => "not a checkpoint name: {$tag}"];
        $git = self::gitAs($author);
        if ($git === null) return ['ok' => false, 'error' => "rolling {$inst->slug} back needs the member doing it (name and email) as the commit's author"];

        [$code, $out] = self::ssh($inst, 'app', 'cd /srv/app; git rev-parse -q --verify ' . escapeshellarg("refs/tags/{$tag}^{commit}") . ' >/dev/null || { echo "no checkpoint ' . $tag . '"; exit 3; }; '
            . 'if [ -n "$(ls -A .aibuilder/wt 2>/dev/null)" ]; then echo "a build task is in progress (.aibuilder/wt: $(ls .aibuilder/wt | tr \'\n\' \' \')) — let it finish or stop it first"; exit 4; fi', null, 30);
        if ($code !== 0) return ['ok' => false, 'error' => trim((string) $out) ?: "could not check {$inst->slug}'s container (exit {$code})"];

        $before = self::checkpoint($inst, 'checkpoint-before-rollback-' . date('Ymd-His'), "before rollback to {$tag}", $author);
        if (!$before['ok']) return ['ok' => false, 'error' => 'the app was not rolled back — saving its current state first failed: ' . $before['error']];
        $pre = $before['tag'];

        $restore = '$d = ".aibuilder/backups/' . $tag . '"; $n = []; foreach (glob("$d/*.db") ?: [] as $b) { '
                 . '$s = new SQLite3($b, SQLITE3_OPEN_READONLY); $o = new SQLite3("database/" . basename($b)); '
                 . 'if (!$s->backup($o)) { fwrite(STDERR, "restoring " . basename($b) . " failed\n"); exit(1); } $n[] = basename($b); } echo implode(" ", $n);';
        $script = 'set -e; cd /srv/app; '
            . 'git restore --source=' . escapeshellarg($tag) . ' --staged --worktree :/; '
            . 'if git diff --cached --quiet; then echo COMMITTED=0; else ' . $git . ' commit -q -m ' . escapeshellarg("rollback: to {$tag} (the state before is {$pre})") . '; echo COMMITTED=1; fi; '
            . 'echo "COMMIT=$(git rev-parse --short HEAD)"; '
            . 'if git diff --quiet ' . escapeshellarg($pre) . ' HEAD -- composer.json composer.lock; then :; else composer install --no-interaction --no-progress -q; '
            // .release (ignored by git) names the runtime that is running: re-pin it to the one now installed.
            . 'php -r ' . escapeshellarg('$p = json_decode((string) file_get_contents("vendor/composer/installed.json"), true)["packages"] ?? []; foreach ($p as $k) if (($k["name"] ?? "") === "tiknix/runtime") { file_put_contents(".release", $k["version"] . " " . ($k["source"]["reference"] ?? $k["dist"]["reference"] ?? "") . " " . date("c") . "\n"); echo "RUNTIME=", $k["version"], "\n"; exit(0); } fwrite(STDERR, "tiknix/runtime is not in vendor/composer/installed.json after composer install\n"); exit(1);') . '; fi; '
            . 'if [ -d ' . escapeshellarg(".aibuilder/backups/{$tag}") . ' ]; then echo "DATA=$(php -r ' . escapeshellarg($restore) . ')"; else echo NODATA; fi; '
            . '[ ! -f scripts/resetcache.php ] || php scripts/resetcache.php >/dev/null';
        [$code, $out] = self::ssh($inst, 'app', $script . ' 2>&1', null, 900);
        $out = (string) $out;
        if ($code !== 0) {
            return ['ok' => false, 'before' => $pre, 'error' => "the rollback of {$inst->slug} to {$tag} failed part-way (exit {$code}): " . trim($out)
                . " — the state before it is checkpoint {$pre}; roll back to that to undo whatever was done"];
        }
        preg_match('/^COMMIT=(\S+)/m', $out, $c);
        $data = (bool) preg_match('/^DATA=(.*)$/m', $out, $d);
        preg_match('/^RUNTIME=(\S+)/m', $out, $rt);
        return ['ok' => true, 'tag' => $tag, 'before' => $pre, 'commit' => $c[1] ?? '', 'data' => $data, 'databases' => trim($d[1] ?? ''), 'runtime' => $rt[1] ?? ''];
    }

    /**
     * `git -c user.name=… -c user.email=…` for the member making a change, or null when either is
     * missing. Every commit core makes in an app is authored by the member behind it.
     */
    public static function gitAs(array $author): ?string {
        $name  = trim((string) ($author['name'] ?? ''));
        $email = trim((string) ($author['email'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        return 'git -c user.name=' . escapeshellarg($name) . ' -c user.email=' . escapeshellarg($email);
    }

    /**
     * ['name' => …, 'email' => …] for a member — what checkpoint(), gitAs() and gitEnv() take.
     * Read from core's database whichever one is current. Throws when the member is not there
     * or has no email: a commit nobody can be named for is not made.
     */
    public static function author(int $memberId): array {
        $m = CoreDb::with(fn() => Bean::load('member', $memberId)->export(), []);
        if (empty($m['id'])) throw new \RuntimeException("no member #{$memberId} to author the commit" . (CoreDb::lastError() ? ' (' . CoreDb::lastError() . ')' : ''));
        $name = trim(trim((string) ($m['first_name'] ?? '')) . ' ' . trim((string) ($m['last_name'] ?? '')));
        if ($name === '') $name = (string) ($m['username'] ?? '');
        $email = (string) ($m['email'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException("member #{$memberId} has no name or email to author the commit");
        return ['name' => $name, 'email' => $email];
    }

    /**
     * `env GIT_AUTHOR_NAME=… GIT_AUTHOR_EMAIL=… GIT_COMMITTER_NAME=… GIT_COMMITTER_EMAIL=… ` for a
     * run in the container, so every commit it makes — the task's, the merge, the agent's own — is
     * the member's. [] = a run that commits nothing (a plan, an audit): no prefix, and the runtime
     * refuses to commit without one.
     */
    public static function gitEnv(array $author): string {
        if ($author === []) return '';
        if (self::gitAs($author) === null) throw new \InvalidArgumentException('a commit author needs a name and a valid email');
        $n = escapeshellarg(trim((string) $author['name']));
        $e = escapeshellarg(trim((string) $author['email']));
        return "env GIT_AUTHOR_NAME={$n} GIT_AUTHOR_EMAIL={$e} GIT_COMMITTER_NAME={$n} GIT_COMMITTER_EMAIL={$e} ";
    }

    /**
     * The app's builder terminal (runtime bin/terminal-bridge.php on <ip>:3990): its key — minted
     * once, kept on the instance (instance.terminal_key; the builder signs the browser's token
     * with it) and in the app (secure/terminal.key) — the crontab line that keeps it running when
     * the heartbeat is on, and the bridge started now. A minted key restarts a running bridge,
     * which read the old one; attached tabs drop and reconnect, the agents in tmux keep running.
     */
    public static function terminal(object $inst): array {
        if ((string) $inst->ctIp === '') return ['ok' => false, 'error' => "{$inst->slug} has no container address"];
        $steps = [];
        [$c, $o] = self::ssh($inst, 'app', 'test -f /srv/app/vendor/tiknix/runtime/bin/terminal-bridge.php', null, 30);
        if ($c !== 0) return ['ok' => false, 'error' => "{$inst->slug}'s runtime has no bin/terminal-bridge.php — update it first (tenant.php --clitool={$inst->slug} -- --update)"];

        $key = (string) $inst->terminalKey;
        $minted = strlen($key) < 64;
        if ($minted) { $key = bin2hex(random_bytes(32)); $inst->terminalKey = $key; Bean::store($inst); }
        [$c, $o] = self::ssh($inst, 'app', 'umask 077 && mkdir -p /srv/app/secure && cat > /srv/app/secure/terminal.key.new && mv /srv/app/secure/terminal.key.new /srv/app/secure/terminal.key', $key, 30);
        if ($c !== 0) return ['ok' => false, 'error' => "could not write secure/terminal.key in {$inst->ctIp}: {$o}"];
        $steps[] = $minted ? 'key minted and installed' : 'key installed (unchanged)';

        [$c, $o] = self::ssh($inst, 'root', 'if crontab -l -u app 2>/dev/null | grep -q pipeline-cron; then crontab -u app - && echo on; else cat >/dev/null; echo off; fi', self::CRONTAB, 30);
        if ($c !== 0) return ['ok' => false, 'error' => "crontab in {$inst->ctIp} failed: {$o}", 'steps' => $steps];
        $steps[] = trim($o) === 'on' ? 'crontab: heartbeat + terminal' : 'crontab: heartbeat off, so the terminal is not kept running (cutover switches both on)';

        $start = ($minted ? "pkill -f '^php vendor/tiknix/runtime/bin/terminal-bridge.php' ; sleep 1 ; " : '')
            . "cd /srv/app && setsid -f sh -c 'flock -n /tmp/aib-terminal.lock php vendor/tiknix/runtime/bin/terminal-bridge.php >> log/terminal-bridge.log 2>&1' </dev/null >/dev/null 2>&1 ; "
            . "for i in 1 2 3 4 5 6; do ss -ltn | grep -q ':3990 ' && { echo listening; exit 0; }; sleep 1; done; tail -3 log/terminal-bridge.log; exit 1";
        [$c, $o] = self::ssh($inst, 'app', $start, null, 60);
        if ($c !== 0) return ['ok' => false, 'error' => "the terminal bridge is not listening on {$inst->ctIp}:3990: " . trim($o), 'steps' => $steps];
        $steps[] = "bridge listening on {$inst->ctIp}:3990";
        return ['ok' => true, 'steps' => $steps];
    }

    public static function provision(object $inst, string $domain): array {
        if ((int) $inst->ctVmid <= 0) return ['ok' => false, 'error' => "{$inst->slug} has no container (create it first)"];
        $core = (string) parse_url((string) \Flight::get('app.baseurl'), PHP_URL_HOST);
        if ($core === '') return ['ok' => false, 'error' => '[app] baseurl in conf/config.ini names no host — the tenant cannot find core'];
        $coreIp = gethostbyname($core);
        if ($coreIp === $core) return ['ok' => false, 'error' => "{$core} does not resolve on core"];
        $env = [
            'APP_SLUG'       => (string) $inst->slug,
            'CORE_HOST'      => $core,
            'CORE_IP'        => $coreIp,
            'DEPLOY_TOKEN'   => GitHttp::deployToken($inst),
            'BUILDER_PUBKEY' => trim((string) file_get_contents(self::KEY . '.pub')),
            'APP_BASEURL'    => 'https://' . $domain,
            'APP_NAME'       => (string) ($inst->displayName ?: $inst->slug),
            'APP_KEY'        => bin2hex(random_bytes(32)),
            'APP_CRONTAB'    => self::CRONTAB,
        ];
        $script = '';
        foreach ($env as $k => $v) $script .= "export {$k}=" . escapeshellarg($v) . "\n";
        $script .= (string) file_get_contents(self::APP_SH);
        [$code, $out] = self::ssh($inst, 'root', 'bash -s', $script, 1800);
        if ($code !== 0) return ['ok' => false, 'exit' => $code, 'output' => $out, 'error' => "app.sh exited {$code} in {$inst->ctIp}"];
        $t = self::terminal($inst);
        if (!$t['ok']) return ['ok' => false, 'exit' => $code, 'output' => $out, 'error' => 'the app is up, its builder terminal is not: ' . $t['error']];
        $out .= "\nterminal: " . implode('; ', $t['steps'] ?? []);

        // Its seeded ROOT becomes the person who created the project — installed, no /install wizard; they come in from Tiknix
        // (the project's pages in the nav sign them in, /projects/open → the app's /auth/launch).
        {
            $owner = Bean::load('member', (int) $inst->memberId);
            $email = strtolower(trim((string) $owner->email));
            if (!$owner->id || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'exit' => $code, 'output' => $out, 'error' => "the project's owner (member #{$inst->memberId}) has no email to make its admin"];
            }
            [$c, $o] = self::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --claim-root=' . escapeshellarg($email), null, 60);
            if ($c !== 0) return ['ok' => false, 'exit' => $code, 'output' => $out, 'error' => 'the app is up, its owner account is not: ' . trim((string) $o)];
            $out .= "\nowner: " . trim((string) $o);
            // Its key to this control plane's broker (its connected stores: Shopify …).
            try { $out .= "\nbroker: " . BrokerService::ensureContainerConfig($inst, (int) $inst->memberId); }
            catch (\RuntimeException $e) { return ['ok' => false, 'exit' => $code, 'output' => $out, 'error' => 'the app is up, its broker key is not: ' . $e->getMessage()]; }
        }
        return ['ok' => true, 'exit' => $code, 'output' => $out, 'error' => ''];
    }

    /** Serve https://<domain> from the tenant (capricorn's proxy file; TLS per ProxmoxDeploy). */
    public static function publish(object $inst, string $domain): array {
        if ((string) $inst->ctIp === '') return ['ok' => false, 'error' => "{$inst->slug} has no container address"];
        $r = ProxmoxDeploy::writeProxy($domain, (string) $inst->ctIp);
        if (!$r['ok']) return $r;
        $inst->ctDomain = $domain;
        Bean::store($inst);
        return $r;
    }

    /**
     * Run a command in the tenant. `app` is the builder's user, `root` provisioning's.
     * @return array{0:int,1:string}  exit code and combined output
     */
    public static function ssh(object $inst, string $user, string $command, ?string $stdin = null, int $timeout = 600, ?string $stdinFile = null, ?string $stdoutFile = null): array {
        if (!in_array($user, ['app', 'root'], true)) throw new \InvalidArgumentException("tenant user must be app or root, not {$user}");
        $ip = (string) $inst->ctIp;
        if (!preg_match('/^10\.10\.10\.\d{1,3}$/', $ip)) throw new \RuntimeException("{$inst->slug} has no tenant address ({$ip})");
        $cmd = ['timeout', (string) $timeout, 'ssh', '-i', self::KEY,
            '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8', '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile=' . self::KNOWN, '-o', 'LogLevel=ERROR',
            "{$user}@{$ip}", $command];
        // ssh forwards LANG/LC_* (SendEnv); core's locale may not exist in the tenant. C.UTF-8 does.
        $env = array_merge(getenv(), ['LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8']);
        // $stdinFile streams a file (an archive of an app's data can be hundreds of MB).
        if ($stdinFile !== null && !is_readable($stdinFile)) throw new \RuntimeException("cannot read {$stdinFile}");
        $in = $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'];
        // $stdoutFile takes the remote command's stdout as a file (an archive streamed back);
        // stderr then comes back as the output string, so a failure still says why.
        $io = $stdoutFile !== null
            ? [0 => $in, 1 => ['file', $stdoutFile, 'w'], 2 => ['pipe', 'w']]
            : [0 => $in, 1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $p = proc_open($cmd, $io, $pipes, null, $env);
        if (!is_resource($p)) return [255, 'could not start ssh'];
        if ($stdinFile === null) {
            if ($stdin !== null) fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }
        $read = $stdoutFile !== null ? 2 : 1;
        $out = (string) stream_get_contents($pipes[$read]);
        fclose($pipes[$read]);
        return [proc_close($p), $out];
    }

    /**
     * The builder over SSH: ask the tenant to run one task (runtime AgentTask — a worktree on
     * task/<id>, the app's own agent and credentials, changes committed). The prompt travels on
     * stdin. Returns the tenant's JSON answer, or a refusal naming what failed on the way.
     */
    public static function task(object $inst, string $id, string $prompt, array $author, int $timeout = 1800, string $agent = ''): array {
        return self::runAndWait($inst, $id, '--agent-task=' . escapeshellarg($id) . self::agentArg($agent) . ' --timeout=' . (int) $timeout, $prompt, $timeout, $author);
    }

    /** The builder's planner in the tenant (clitool --agent-plan): the plan's JSON in 'plan'. */
    public static function plan(object $inst, string $id, string $request, int $memberId, int $timeout = 1800, string $agent = ''): array {
        return self::runAndWait($inst, $id, '--agent-plan=' . escapeshellarg($id) . ' --member=' . (int) $memberId . self::agentArg($agent) . ' --timeout=' . (int) $timeout, $request, $timeout, []);
    }

    /**
     * Run `clitool $args` in a tmux session IN the container (TenantRun, session tiknix-run-<id>)
     * and wait here for its JSON answer. The agent lives in the container: this waiter dying (a
     * core restart, a dropped SSH) does not stop it, and calling again with the same id while
     * that session is still running waits for it instead of starting another.
     */
    private static function runAndWait(object $inst, string $id, string $args, ?string $input, int $timeout, array $author): array {
        $session = 'tiknix-run-' . $id;
        if (!TenantRun::alive($inst, $session)) TenantRun::start($inst, $session, $id, $args, $input, $author);
        $deadline = time() + $timeout + 300;
        while (true) {
            sleep(5);
            try {
                $done = TenantRun::result($inst, $id);
                if ($done === null && !TenantRun::alive($inst, $session)) {
                    $done = TenantRun::result($inst, $id);   // its exit file is written before the session ends
                    if ($done === null) return ['ok' => false, 'status' => 'failed', 'error' => "{$session} ended in {$inst->slug}'s container without finishing (stopped, or the container restarted)"];
                }
            } catch (\RuntimeException $e) {
                error_log("ERROR TenantHost::runAndWait {$id}: " . $e->getMessage() . ' (still waiting)');
                $done = null;
            }
            if ($done !== null) break;
            if (time() > $deadline) return ['ok' => false, 'status' => 'failed', 'error' => "no answer from {$id} after {$timeout}s; it may still be running in {$inst->slug}'s container (tmux session {$session})"];
        }
        if ($done['result'] === null) return ['ok' => false, 'status' => 'failed', 'error' => "{$id} exited {$done['exit']} in the container with no answer: " . mb_substr($done['log'], -800)];
        return $done['result'];
    }

    /**
     * The builder's audit in the tenant (clitool --agent-audit): its agent drives the control
     * plane's browser at $browserMcp (a tunnel into the container — tunnelCommand()); the manifest
     * comes back in 'manifest'.
     */
    public static function audit(object $inst, string $id, string $brief, string $browserMcp, int $timeout = 1800, string $agent = ''): array {
        return self::runAndWait($inst, $id, '--agent-audit=' . escapeshellarg($id) . ' --browser-mcp=' . escapeshellarg($browserMcp)
            . self::agentArg($agent) . ' --timeout=' . (int) $timeout, $brief, $timeout, []);
    }

    /**
     * The command that makes 127.0.0.1:$port inside the container reach 127.0.0.1:$port HERE, for
     * as long as it runs (ssh -R; nothing is exposed beyond this machine and that container).
     */
    public static function tunnelCommand(object $inst, int $port): string {
        $ip = (string) $inst->ctIp;
        if (!preg_match('/^10\.10\.10\.\d{1,3}$/', $ip)) throw new \RuntimeException("{$inst->slug} has no tenant address ({$ip})");
        if ($port < 1024 || $port > 65535) throw new \InvalidArgumentException("not a port: {$port}");
        return implode(' ', array_map('escapeshellarg', ['ssh', '-i', self::KEY, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8',
            '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . self::KNOWN, '-o', 'LogLevel=ERROR',
            '-o', 'ExitOnForwardFailure=yes', '-o', 'ServerAliveInterval=30', '-N', '-R', "127.0.0.1:{$port}:127.0.0.1:{$port}", "app@{$ip}"]));
    }

    /** Merge task/<id> into the app — the merge commit is $author's (the member approving it). */
    public static function mergeTask(object $inst, string $id, array $author): array {
        if ($author === []) return ['ok' => false, 'status' => 'failed', 'error' => "merging task/{$id} needs the member doing it as the commit's author"];
        return self::taskCall($inst, '--agent-merge=' . escapeshellarg($id), null, 600, self::gitEnv($author));
    }
    public static function discardTask(object $inst, string $id): array { return self::taskCall($inst, '--agent-discard=' . escapeshellarg($id), null, 120); }

    /** --agent=NAME for one of the app's agents (its AI agents page); '' = the app's default. */
    public static function agentArg(string $agent): string {
        if ($agent === '') return '';
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $agent)) throw new \InvalidArgumentException("'{$agent}' is not an agent name");
        return ' --agent=' . escapeshellarg($agent);
    }

    private static function taskCall(object $inst, string $args, ?string $stdin, int $timeout, string $env = ''): array {
        [$code, $out] = self::ssh($inst, 'app', 'cd /srv/app && ' . $env . 'php scripts/clitool.php ' . $args, $stdin, $timeout);
        $json = json_decode(substr($out, (int) strpos($out, '{')), true);
        if (!is_array($json)) return ['ok' => false, 'status' => 'failed', 'error' => "the tenant answered (exit {$code}) with no JSON: " . mb_substr(trim($out), 0, 400)];
        return $json;
    }

    public static function destroy(object $inst): array {
        $vmid = (int) $inst->ctVmid;
        if ($vmid <= 0) return ['ok' => false, 'error' => "{$inst->slug} has no container"];
        if (in_array($vmid, self::PROTECTED, true)) return ['ok' => false, 'error' => "refusing to touch protected container {$vmid}"];
        $pve = ProxmoxService::fromConfig();
        if (!$pve) return ['ok' => false, 'error' => 'conf/proxmox.ini is not configured'];
        $node = $pve->node();
        $pve->stopCt($node, $vmid);
        $d = $pve->destroyCt($node, $vmid);
        if (!$d['ok']) return ['ok' => false, 'error' => "destroy {$vmid} failed: {$d['exit']}"];
        // Every name it was served under: its domain, the aliases, and the domains it serves as
        // sites of their own — a proxy file left behind routes a name to an address now unused.
        $names = array_merge([(string) $inst->ctDomain], explode(',', (string) $inst->ctAliases), TenantDomains::of($inst));
        foreach (array_unique(array_filter(array_map('trim', $names))) as $name) ProxmoxDeploy::removeProxy($name);
        self::forgetHostKey((string) $inst->ctIp);
        $inst->ctVmid = 0; $inst->ctIp = ''; $inst->ctDomain = ''; $inst->ctKind = '';
        Bean::store($inst);
        return ['ok' => true, 'step' => "container {$vmid} destroyed"];
    }
}
