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
        $inst->ctVmid = $vmid; $inst->ctIp = $ip; $inst->ctKind = 'tenant';
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
     * The app's crontab while its heartbeat is on — the ONE definition: tenant/app.sh gets it as
     * APP_CRONTAB, heartbeat() writes it at cutover. Its schedule (pipeline-cron) and its builder
     * terminal (bin/terminal-bridge.php, kept running: flock admits one, the next minute restarts it).
     */
    const CRONTAB = "* * * * * cd /srv/app && php scripts/pipeline-cron.php >> log/pipeline-cron.log 2>&1\n"
        . "* * * * * cd /srv/app && flock -n /tmp/aib-terminal.lock php vendor/tiknix/runtime/bin/terminal-bridge.php >> log/terminal-bridge.log 2>&1\n";

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

    /**
     * The minute heartbeat (tenant/app.sh). A new app gets it here; an app carried from a host
     * clone gets it at cutover — a staging copy must not run the live app's schedule.
     */
    public static function heartbeat(object $inst, bool $on): array {
        [$c, $o] = $on
            ? self::ssh($inst, 'root', 'crontab -u app - && crontab -l -u app', self::CRONTAB, 60)
            : self::ssh($inst, 'root', 'crontab -r -u app 2>/dev/null; crontab -l -u app 2>&1 | head -1', null, 60);
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
            'APP_CRONTAB'    => self::CRONTAB,
            // A carried app's seeds are written for ITS data, which TenantCarry::data() copies in
            // next and builds against; on the template's empty database they can only fail
            // (Serenity's migrations of its own live rows).
            'APP_CARRIED'    => $branch === TenantCarry::BRANCH ? '1' : '0',
        ];
        $script = '';
        foreach ($env as $k => $v) $script .= "export {$k}=" . escapeshellarg($v) . "\n";
        $script .= (string) file_get_contents(self::APP_SH);
        [$code, $out] = self::ssh($inst, 'root', 'bash -s', $script, 1800);
        if ($code !== 0) return ['ok' => false, 'exit' => $code, 'output' => $out, 'error' => "app.sh exited {$code} in {$inst->ctIp}"];
        $t = self::terminal($inst);
        if (!$t['ok']) return ['ok' => false, 'exit' => $code, 'output' => $out, 'error' => 'the app is up, its builder terminal is not: ' . $t['error']];
        $out .= "\nterminal: " . implode('; ', $t['steps'] ?? []);

        // A NEW app (a carried one brings its own members): its seeded ROOT becomes the person
        // who created the project — installed, no /install wizard; they come in from Tiknix
        // (the project's pages in the nav sign them in, /projects/open → the app's /auth/launch).
        if ($branch === 'main') {
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
    public static function task(object $inst, string $id, string $prompt, int $timeout = 1800, string $agent = ''): array {
        return self::runAndWait($inst, $id, '--agent-task=' . escapeshellarg($id) . self::agentArg($agent) . ' --timeout=' . (int) $timeout, $prompt, $timeout);
    }

    /** The builder's planner in the tenant (clitool --agent-plan): the plan's JSON in 'plan'. */
    public static function plan(object $inst, string $id, string $request, int $memberId, int $timeout = 1800, string $agent = ''): array {
        return self::runAndWait($inst, $id, '--agent-plan=' . escapeshellarg($id) . ' --member=' . (int) $memberId . self::agentArg($agent) . ' --timeout=' . (int) $timeout, $request, $timeout);
    }

    /**
     * Run `clitool $args` in a tmux session IN the container (TenantRun, session tiknix-run-<id>)
     * and wait here for its JSON answer. The agent lives in the container: this waiter dying (a
     * core restart, a dropped SSH) does not stop it, and calling again with the same id while
     * that session is still running waits for it instead of starting another.
     */
    private static function runAndWait(object $inst, string $id, string $args, ?string $input, int $timeout): array {
        $session = 'tiknix-run-' . $id;
        if (!TenantRun::alive($inst, $session)) TenantRun::start($inst, $session, $id, $args, $input);
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
            . self::agentArg($agent) . ' --timeout=' . (int) $timeout, $brief, $timeout);
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

    public static function mergeTask(object $inst, string $id): array   { return self::taskCall($inst, '--agent-merge=' . escapeshellarg($id), null, 600); }
    public static function discardTask(object $inst, string $id): array { return self::taskCall($inst, '--agent-discard=' . escapeshellarg($id), null, 120); }

    /** --agent=NAME for one of the app's agents (its AI agents page); '' = the app's default. */
    public static function agentArg(string $agent): string {
        if ($agent === '') return '';
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $agent)) throw new \InvalidArgumentException("'{$agent}' is not an agent name");
        return ' --agent=' . escapeshellarg($agent);
    }

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
