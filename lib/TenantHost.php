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
        $pve = ProxmoxService::fromConfig();
        if (!$pve) return ['ok' => false, 'error' => 'conf/proxmox.ini is not configured'];
        $node = $pve->node();
        $tpl = self::currentTemplate($pve, $node);
        if (!$tpl['ok']) return $tpl;
        $vmid = self::freeVmid($pve);
        if (!$vmid['ok']) return $vmid;
        $vmid = $vmid['vmid'];
        $ip = self::SUBNET . $vmid;
        $host = substr(preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $inst->slug)), 0, 60);
        $c = $pve->cloneCt($node, $tpl['vmid'], $vmid, ['hostname' => $host, 'full' => 0,
            'description' => 'tiknix app ' . $inst->slug . ' (linked clone of ' . $tpl['name'] . ')']);
        if (!$c['ok']) return ['ok' => false, 'error' => "clone of {$tpl['name']} to {$vmid} failed: {$c['exit']}" . ($c['log'] !== '' ? "\n{$c['log']}" : '')];
        $inst->ctVmid = $vmid; $inst->ctIp = $ip;
        Bean::store($inst);
        $cfg = $pve->setCtConfig($node, $vmid, self::ctParams($host, $ip) + ['onboot' => 1]);
        if (($cfg['error'] ?? '') !== '') return ['ok' => false, 'error' => "configuring {$vmid} failed: {$cfg['error']}"];
        $s = $pve->startCt($node, $vmid);
        if (!$s['ok']) return ['ok' => false, 'error' => "start {$vmid} failed: {$s['exit']}" . ($s['log'] !== '' ? "\n{$s['log']}" : '')];
        self::forgetHostKey($ip);
        $w = self::waitForSsh($ip, 120);
        if (!$w['ok']) return $w;
        return ['ok' => true, 'vmid' => $vmid, 'ip' => $ip, 'step' => "container {$vmid} at {$ip}: linked clone of {$tpl['name']}, sshd answering after {$w['seconds']}s"];
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
     * The minute heartbeat (tenant/app.sh). A new app gets it here; an app carried from a host
     * clone gets it at cutover — a staging copy must not run the live app's schedule.
     */
    public static function heartbeat(object $inst, bool $on): array {
        $cmd = $on
            ? "printf '%s\\n' '* * * * * cd /srv/app && php scripts/pipeline-cron.php >> log/pipeline-cron.log 2>&1' | crontab -u app - && crontab -l -u app"
            : 'crontab -r -u app 2>/dev/null; crontab -l -u app 2>&1 | head -1';
        [$c, $o] = self::ssh($inst, 'root', $cmd, null, 60);
        return ['ok' => $c === 0, 'step' => 'heartbeat ' . ($on ? 'on' : 'off') . ': ' . trim($o), 'error' => $c === 0 ? '' : "crontab in {$inst->ctIp} failed: {$o}"];
    }

    public static function provision(object $inst, string $domain, string $branch = 'main'): array {
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
            'APP_BRANCH'     => $branch,
            'APP_HEARTBEAT'  => $branch === 'main' ? 'on' : 'off',
        ];
        $script = '';
        foreach ($env as $k => $v) $script .= "export {$k}=" . escapeshellarg($v) . "\n";
        $script .= (string) file_get_contents(self::APP_SH);
        [$code, $out] = self::ssh($inst, 'root', 'bash -s', $script, 1800);
        return ['ok' => $code === 0, 'exit' => $code, 'output' => $out,
                'error' => $code === 0 ? '' : "app.sh exited {$code} in {$inst->ctIp}"];
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
    public static function ssh(object $inst, string $user, string $command, ?string $stdin = null, int $timeout = 600, ?string $stdinFile = null): array {
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
        $p = proc_open($cmd, [0 => $in, 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
        if (!is_resource($p)) return [255, 'could not start ssh'];
        if ($stdinFile === null) {
            if ($stdin !== null) fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($p), $out];
    }

    /**
     * The builder over SSH: ask the tenant to run one task (runtime AgentTask — a worktree on
     * task/<id>, the app's own agent and credentials, changes committed). The prompt travels on
     * stdin. Returns the tenant's JSON answer, or a refusal naming what failed on the way.
     */
    public static function task(object $inst, string $id, string $prompt, int $timeout = 1800): array {
        return self::taskCall($inst, '--agent-task=' . escapeshellarg($id) . ' --timeout=' . (int) $timeout, $prompt, $timeout + 120);
    }

    public static function mergeTask(object $inst, string $id): array   { return self::taskCall($inst, '--agent-merge=' . escapeshellarg($id), null, 600); }
    public static function discardTask(object $inst, string $id): array { return self::taskCall($inst, '--agent-discard=' . escapeshellarg($id), null, 120); }

    private static function taskCall(object $inst, string $args, ?string $stdin, int $timeout): array {
        [$code, $out] = self::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php ' . $args, $stdin, $timeout);
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
        if ((string) $inst->ctDomain !== '') ProxmoxDeploy::removeProxy((string) $inst->ctDomain);
        self::forgetHostKey((string) $inst->ctIp);
        $inst->ctVmid = 0; $inst->ctIp = ''; $inst->ctDomain = '';
        Bean::store($inst);
        return ['ok' => true, 'step' => "container {$vmid} destroyed"];
    }
}
