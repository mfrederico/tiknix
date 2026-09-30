<?php
/**
 * TenantHost — an app's own container on the Proxmox node (RUNTIME-SPLIT-MAP.md step 4).
 *
 *   create()     a system container from Proxmox's stock ubuntu-24.04-standard template on
 *                the internal NAT'd bridge (10.10.10.<vmid>), core's tenant SSH key
 *                authorised for root
 *   provision()  tenant/provision.sh over SSH, as root: PHP 8.5 + nginx, the `app` user, the
 *                app cloned from core's git endpoint and built — the "image" is that script
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

    /** Proxmox's stock template (on the node's `local` template storage). */
    const TEMPLATE   = 'local:vztmpl/ubuntu-24.04-standard_24.04-2_amd64.tar.zst';
    const ROOTFS     = 'local-lvm';
    const ROOTFS_GB  = 8;
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
    const PROVISION  = __DIR__ . '/../tenant/provision.sh';
    /** Container ids never touched: 101 is the control plane itself. */
    const PROTECTED  = [101];

    /** The instance row for a slug, or a refusal naming why it cannot be a tenant. */
    public static function instance(string $slug): array {
        $inst = Bean::findOne('instance', 'slug = ?', [$slug]);
        if (!$inst || !$inst->id) return ['ok' => false, 'error' => "no instance '{$slug}' in the registry"];
        if ((string) $inst->status !== 'active') return ['ok' => false, 'error' => "instance '{$slug}' is {$inst->status}, not active"];
        return ['ok' => true, 'inst' => $inst];
    }

    public static function create(object $inst): array {
        if ((int) $inst->ctVmid > 0) return ['ok' => false, 'error' => "{$inst->slug} already has container {$inst->ctVmid} ({$inst->ctIp})"];
        $pve = ProxmoxService::fromConfig();
        if (!$pve) return ['ok' => false, 'error' => 'conf/proxmox.ini is not configured'];
        $pub = @file_get_contents(self::KEY . '.pub');
        if ($pub === false || trim($pub) === '') return ['ok' => false, 'error' => 'core has no tenant key at ' . self::KEY . '.pub (ssh-keygen -t ed25519 -f ' . self::KEY . ')'];
        $node = $pve->node();
        $vmid = $pve->nextId();
        if ($vmid <= 0 || in_array($vmid, self::PROTECTED, true)) return ['ok' => false, 'error' => "Proxmox offered vmid {$vmid}"];
        if ($vmid >= 255) return ['ok' => false, 'error' => "vmid {$vmid} does not fit the 10.10.10.<vmid> address plan"];
        $ip = self::SUBNET . $vmid;
        $r = $pve->createCt($node, $vmid, self::TEMPLATE, [
            'hostname'        => substr(preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $inst->slug)), 0, 60),
            'description'     => 'tiknix app ' . $inst->slug . ' (TenantHost)',
            'rootfs'          => self::ROOTFS . ':' . self::ROOTFS_GB,
            'memory'          => self::MEMORY_MB,
            'swap'            => self::SWAP_MB,
            'cores'           => self::CORES,
            'net0'            => 'name=eth0,bridge=' . self::BRIDGE . ',ip=' . $ip . '/24,gw=' . self::GATEWAY,
            'nameserver'      => self::DNS,
            'features'        => 'nesting=1',
            'ssh-public-keys' => trim($pub),
            'onboot'          => 1,
        ]);
        if (!$r['ok']) return ['ok' => false, 'error' => "create {$vmid} failed: {$r['exit']}" . ($r['log'] !== '' ? "\n{$r['log']}" : '')];
        $inst->ctVmid = $vmid; $inst->ctIp = $ip;
        Bean::store($inst);
        $s = $pve->startCt($node, $vmid);
        if (!$s['ok']) return ['ok' => false, 'error' => "start {$vmid} failed: {$s['exit']}" . ($s['log'] !== '' ? "\n{$s['log']}" : '')];
        $w = self::waitForSsh($ip, 120);
        if (!$w['ok']) return $w;
        return ['ok' => true, 'vmid' => $vmid, 'ip' => $ip, 'step' => "container {$vmid} at {$ip}, sshd answering after {$w['seconds']}s"];
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
     * Run provision.sh in the tenant as root. The settings reach it as `export` lines ahead
     * of the script on stdin — never on a command line, where the deploy token would sit in
     * a process list.
     */
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
        ];
        $script = '';
        foreach ($env as $k => $v) $script .= "export {$k}=" . escapeshellarg($v) . "\n";
        $script .= (string) file_get_contents(self::PROVISION);
        [$code, $out] = self::ssh($inst, 'root', 'bash -s', $script, 1800);
        return ['ok' => $code === 0, 'exit' => $code, 'output' => $out,
                'error' => $code === 0 ? '' : "provision.sh exited {$code} in {$inst->ctIp}"];
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
    public static function ssh(object $inst, string $user, string $command, ?string $stdin = null, int $timeout = 600): array {
        if (!in_array($user, ['app', 'root'], true)) throw new \InvalidArgumentException("tenant user must be app or root, not {$user}");
        $ip = (string) $inst->ctIp;
        if (!preg_match('/^10\.10\.10\.\d{1,3}$/', $ip)) throw new \RuntimeException("{$inst->slug} has no tenant address ({$ip})");
        $cmd = ['timeout', (string) $timeout, 'ssh', '-i', self::KEY,
            '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8', '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile=' . self::KNOWN, '-o', 'LogLevel=ERROR',
            "{$user}@{$ip}", $command];
        // ssh forwards LANG/LC_* (SendEnv); core's locale may not exist in the tenant. C.UTF-8 does.
        $env = array_merge(getenv(), ['LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8']);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
        if (!is_resource($p)) return [255, 'could not start ssh'];
        if ($stdin !== null) fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($p), $out];
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
        $inst->ctVmid = 0; $inst->ctIp = ''; $inst->ctDomain = '';
        Bean::store($inst);
        return ['ok' => true, 'step' => "container {$vmid} destroyed"];
    }
}
