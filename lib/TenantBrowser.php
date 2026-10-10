<?php
namespace app;

/**
 * A browser lent to ONE app's agents. No browser lives in an app's container, and none runs on
 * this machine for an app: an app whose terminal sessions and build tasks need to LOOK at its
 * pages is lent one on the QA browser host (lib/QaHost.php — the one machine made to show pages
 * that owners wrote: its own container, an ordinary user, a firewall). A Playwright MCP server
 * runs there on 127.0.0.1:<port>, and this machine carries it to the app: one SSH connection to
 * the QA host that starts it and brings its port here, one to the app's container that takes the
 * port in. Inside the container it is 127.0.0.1:<port>, named `playwright` in the app's .mcp.json
 * so every agent of the app is given it (the runtime's AgentMcp). Nothing listens on a network:
 * no other app can reach this app's browser.
 *
 * The browser is told the app's own addresses (--allowed-origins): its sites, and its build
 * tasks' sandboxes — served on the container's own address while a browser is lent (sandboxArg),
 * which the QA host's firewall lets through on SANDBOX_PORTS and port 80 only (tenant/qa.sh).
 *
 * It is a tmux session (browser-<slug>) that nothing restarts: after this machine reboots, lend
 * it again. Not started for every app — each is a Chromium on the QA host while it is in use
 * (it closes itself after IDLE_MS without a call, and the next call opens it again).
 */
class TenantBrowser {
    /**
     * Where an app's pages load their styles, fonts and scripts from (the runtime's layout and the
     * design system). A browser held to the app's own addresses alone renders every page UNSTYLED —
     * which an audit's design pass then reports as the page's fault.
     */
    public const PAGE_ASSETS = ['https://cdn.jsdelivr.net', 'https://fonts.googleapis.com', 'https://fonts.gstatic.com', 'https://code.jquery.com'];

    /** A build task's sandbox ports (the runtime's AgentTask::SANDBOX_PORTS; tenant/qa.sh lets them through). */
    public const SANDBOX_PORTS = [41000, 41999];
    private const IDLE_MS = 300000;

    /** Is a browser lent to this app right now? */
    public static function lent(object $inst): bool {
        exec('tmux has-session -t ' . escapeshellarg('=' . self::session($inst)) . ' 2>/dev/null', $x, $code);
        return $code === 0;
    }

    /**
     * What a build task's command gains while a browser is lent: its sandbox served on the
     * container's own address, where that browser can open it. '' when none is lent — the
     * sandbox then stays on 127.0.0.1, as it does for every other app.
     */
    public static function sandboxArg(object $inst): string {
        return self::lent($inst) ? ' --sandbox-at=' . escapeshellarg((string) $inst->ctIp) : '';
    }

    /** Browsers lent at once. Each is a Chromium on the QA host while in use (it has 3 GB); past this a build runs without one. */
    public const MAX_LENT = 4;

    /** How many apps hold a lent browser now. */
    public static function lentCount(): int {
        exec("tmux list-sessions -F '#{session_name}' 2>/dev/null", $names);
        return count(array_filter($names, fn($n) => str_starts_with((string) $n, 'browser-')));
    }

    /**
     * A build is starting: lend its app a browser for as long as it runs, when one is free.
     * @return array{lent:bool,why:string} lent = THIS call lent it (so the build takes it back);
     *         false with the reason when the app already holds one, none is free, or it failed.
     */
    public static function lendForBuild(object $inst): array {
        if (self::lent($inst)) return ['lent' => false, 'why' => 'this app already holds a lent browser'];
        if (self::lentCount() >= self::MAX_LENT) {
            return ['lent' => false, 'why' => 'all ' . self::MAX_LENT . ' browsers are lent to other builds, so this build runs without one (its tasks list what they could not look at)'];
        }
        $r = self::lend($inst);
        return $r['ok'] ? ['lent' => true, 'why' => 'a browser on the QA host, for this build'] : ['lent' => false, 'why' => 'the browser could not be lent: ' . (string) ($r['error'] ?? '?')];
    }

    /**
     * The command that starts a Playwright MCP server on the QA host and carries its port to
     * 127.0.0.1:$port on THIS machine, for as long as the command runs. The folder on the QA host
     * ($dir, under /srv/qa) gets the allowed origins (a config file: the list can be long) and
     * the browser's output. Used for a lent browser and for an audit's.
     */
    public static function qaBrowserCommand(int $port, array $origins, string $dir, int $idleMs = 0): string {
        $qa = QaHost::state();
        if (!$qa || (string) $qa['provisioned_at'] === '') throw new \RuntimeException('the QA browser host is not set up (php scripts/qa-host.php --up) — every browser the platform runs for an app runs there');
        if (!preg_match('#^/srv/qa/[a-z]+/[A-Za-z0-9._-]+$#', $dir)) throw new \InvalidArgumentException("not a folder for a browser on the QA host: {$dir}");
        $config = json_encode(['network' => ['allowedOrigins' => array_values($origins)]], JSON_UNESCAPED_SLASHES);
        [$c, $o] = TenantHost::ssh((object) ['slug' => QaHost::HOSTNAME, 'ctIp' => $qa['ip']], 'app',
            'mkdir -p ' . escapeshellarg("{$dir}/out") . ' && cat > ' . escapeshellarg("{$dir}/config.json"), $config, 30);
        if ($c !== 0) throw new \RuntimeException('could not prepare the QA host for a browser: ' . trim((string) $o));
        $remote = 'cd /srv/qa/mcp && PLAYWRIGHT_BROWSERS_PATH=/srv/qa/browsers exec node node_modules/@playwright/mcp/cli.js'
                . ' --headless --isolated --browser chromium --host 127.0.0.1 --port ' . $port . ' --allowed-hosts ' . escapeshellarg("127.0.0.1:{$port}")
                . ' --config ' . escapeshellarg("{$dir}/config.json") . ($idleMs > 0 ? ' --idle-timeout ' . $idleMs : '') . ' --output-dir ' . escapeshellarg("{$dir}/out");
        // -tt: the browser there ends when this connection does (with no terminal it would outlive it).
        return implode(' ', array_map('escapeshellarg', ['ssh', '-tt', '-i', TenantHost::key(), '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8',
            '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . TenantHost::knownHosts(), '-o', 'LogLevel=ERROR',
            '-o', 'ExitOnForwardFailure=yes', '-o', 'ServerAliveInterval=30', '-L', "127.0.0.1:{$port}:127.0.0.1:{$port}", 'app@' . $qa['ip'], $remote])) . ' < /dev/null';
    }

    /** Bring what a browser on the QA host saved (screenshots) into $into here, and clear its folder there. */
    public static function collect(string $dir, string $into): void {
        $qa = QaHost::state();
        if (!$qa) throw new \RuntimeException('the QA browser host is not set up');
        if (!preg_match('#^/srv/qa/[a-z]+/[A-Za-z0-9._-]+$#', $dir)) throw new \InvalidArgumentException("not a browser folder on the QA host: {$dir}");
        if (!is_dir($into) && !@mkdir($into, 0775, true)) throw new \RuntimeException("could not create {$into}");
        $tar = tempnam(sys_get_temp_dir(), 'qashots');
        [$c, $o] = TenantHost::ssh((object) ['slug' => QaHost::HOSTNAME, 'ctIp' => $qa['ip']], 'app', 'cd ' . escapeshellarg("{$dir}/out") . ' && tar -cf - .', null, 120, null, $tar);
        if ($c !== 0) { @unlink($tar); throw new \RuntimeException('the screenshots could not be fetched from the QA host: ' . trim((string) $o)); }
        exec('tar -xf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($into) . ' 2>&1', $out, $code);
        @unlink($tar);
        if ($code !== 0) throw new \RuntimeException('the screenshots could not be unpacked: ' . implode(' ', $out));
        TenantHost::ssh((object) ['slug' => QaHost::HOSTNAME, 'ctIp' => $qa['ip']], 'app', 'find ' . escapeshellarg($dir) . ' -mindepth 1 -delete; rmdir ' . escapeshellarg($dir), null, 60);
    }

    public static function session(object $inst): string { return 'browser-' . $inst->slug; }
    /** One fixed port per app, the same here and in its container. */
    public static function port(object $inst): int { return 27000 + (int) $inst->id; }

    /** The app's own addresses, as origins. @return string[] */
    public static function origins(object $inst): array {
        $hosts = [(string) $inst->ctDomain];
        foreach (['ctHosts', 'ctAliases'] as $col) {
            $raw = trim((string) $inst->{$col});
            if ($raw === '') continue;
            $more = json_decode($raw, true);
            if (!is_array($more)) throw new \RuntimeException("{$inst->slug}: instance.{$col} is not a JSON list, so its addresses are unknown");
            foreach ($more as $k => $v) $hosts[] = is_string($k) ? $k : (is_array($v) ? (string) ($v['host'] ?? '') : (string) $v);
        }
        $hosts = array_values(array_unique(array_filter($hosts, fn($h) => (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $h))));
        if (!$hosts) throw new \RuntimeException("{$inst->slug} has no address to browse");
        $origins = array_map(fn($h) => 'https://' . $h, $hosts);
        // …and its build tasks' sandboxes, one origin per port they may take.
        if (!ProxmoxService::isTenantIp((string) $inst->ctIp)) throw new \RuntimeException("{$inst->slug} has no tenant address");
        for ($p = self::SANDBOX_PORTS[0]; $p <= self::SANDBOX_PORTS[1]; $p++) $origins[] = "http://{$inst->ctIp}:{$p}";
        return $origins;
    }

    /**
     * Start (or leave running) the app's lent browser and name it in the app's .mcp.json.
     * @return array{ok:bool,steps?:string[],error?:string}
     */
    public static function lend(object $inst): array {
        if (!\Model_Instance::tenantRow($inst)) return ['ok' => false, 'error' => "{$inst->slug} does not live in its own container"];
        $ws = TenantBuilder::workspace($inst);
        $port = self::port($inst);
        $session = self::session($inst);
        $origins = self::origins($inst);
        $steps = [];

        exec('tmux has-session -t ' . escapeshellarg('=' . $session) . ' 2>/dev/null', $x, $has);
        if ($has === 0) {
            $steps[] = "already lent: tmux session {$session}";
        } else {
            $sock = @fsockopen('127.0.0.1', $port, $e, $es, 0.3);
            if ($sock) { fclose($sock); return ['ok' => false, 'error' => "port {$port} on this machine is already in use and not by {$session}"]; }
            $log = "{$ws}/.aibuilder/browser.log";
            try { $browser = self::qaBrowserCommand($port, array_merge($origins, self::PAGE_ASSETS), '/srv/qa/lent/' . $inst->slug, self::IDLE_MS); }
            catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
            $tunnel = TenantHost::tunnelCommand($inst, $port);
            $path = escapeshellarg('/usr/bin');
            $qlog = escapeshellarg($log);
            $script = <<<BASH
#!/bin/bash
# Tiknix: the browser lent to {$inst->slug} (lib/TenantBrowser.php). Ends when the browser does.
export PATH={$path}:\$PATH
echo "[browser] {$inst->slug}: on the QA host, carried here on 127.0.0.1:{$port} \$(date)" > {$qlog}
{$browser} >> {$qlog} 2>&1 &
BROWSER=\$!
trap 'kill \$BROWSER 2>/dev/null' EXIT
for i in \$(seq 1 90); do curl -s -o /dev/null -m 2 http://127.0.0.1:{$port}/mcp && break; sleep 1; done
curl -s -o /dev/null -m 2 http://127.0.0.1:{$port}/mcp || { echo "[browser] ERROR the browser did not start" >> {$qlog}; exit 1; }
# the tunnel is re-opened whenever it drops (the container restarted, the network blinked)
while kill -0 \$BROWSER 2>/dev/null; do
  {$tunnel} >> {$qlog} 2>&1
  echo "[browser] tunnel closed (\$?) \$(date), re-opening" >> {$qlog}
  sleep 5
done
echo "[browser] ERROR the browser process ended \$(date)" >> {$qlog}
BASH;
            $file = "{$ws}/.aibuilder/browser-lend.sh";
            if (file_put_contents($file, $script) === false || !chmod($file, 0755)) return ['ok' => false, 'error' => "could not write {$file}"];
            exec('tmux new-session -d -s ' . escapeshellarg($session) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            if ($code !== 0) return ['ok' => false, 'error' => "tmux could not start {$session}: " . implode(' ', $out)];
            $steps[] = "started: tmux session {$session}, a browser on the QA host for " . implode(', ', array_filter($origins, fn($o) => str_starts_with($o, 'https://'))) . " and this app's task sandboxes ({$inst->ctIp}:" . self::SANDBOX_PORTS[0] . '-' . self::SANDBOX_PORTS[1] . ')';
        }

        // Reachable from inside the container? (the tunnel is up once the browser answers)
        $url = "http://127.0.0.1:{$port}/mcp";
        $probe = 'for i in $(seq 1 100); do c=$(curl -s -o /dev/null -m 2 -w "%{http_code}" ' . escapeshellarg($url) . '); [ "$c" != "000" ] && { echo "answered $c"; exit 0; }; sleep 1; done; echo "no answer"; exit 1';
        [$c, $o] = TenantHost::ssh($inst, 'app', $probe, null, 150);
        if ($c !== 0) return ['ok' => false, 'steps' => $steps, 'error' => "the browser is not reachable from inside {$inst->slug} at {$url} (" . trim((string) $o) . "); see {$ws}/.aibuilder/browser.log"];
        $steps[] = "reachable in the container at {$url} (" . trim((string) $o) . ')';

        // Name it in the app's .mcp.json: every agent of the app is given what is there.
        $set = '$f = "/srv/app/.mcp.json"; $c = is_file($f) ? json_decode((string) file_get_contents($f), true) : ["mcpServers" => []]; '
             . 'if (!is_array($c)) { fwrite(STDERR, "$f is not valid JSON\n"); exit(1); } '
             . '$want = ["type" => "http", "url" => ' . var_export($url, true) . ']; '
             . 'if ((($c["mcpServers"] ?? [])["playwright"] ?? null) == $want) { echo "unchanged"; exit(0); } '
             . '$c["mcpServers"]["playwright"] = $want; '
             . 'if (file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) { fwrite(STDERR, "could not write $f\n"); exit(1); } echo "written";';
        [$c, $o] = TenantHost::ssh($inst, 'app', 'php -r ' . escapeshellarg($set), null, 30);
        if ($c !== 0) return ['ok' => false, 'steps' => $steps, 'error' => "could not name the browser in {$inst->slug}'s .mcp.json: " . trim((string) $o)];
        $steps[] = "the app's .mcp.json: playwright " . trim((string) $o) . ' (agents started from now on have it)';
        return ['ok' => true, 'steps' => $steps];
    }

    /** Take the browser back: the session ends, and the app's .mcp.json no longer names it. */
    public static function takeBack(object $inst): array {
        $session = self::session($inst);
        $steps = [];
        exec('tmux kill-session -t ' . escapeshellarg('=' . $session) . ' 2>/dev/null', $x, $code);
        $steps[] = $code === 0 ? "ended: tmux session {$session}" : "no tmux session {$session} was running";
        $unset = '$f = "/srv/app/.mcp.json"; if (!is_file($f)) { echo "no .mcp.json"; exit(0); } $c = json_decode((string) file_get_contents($f), true); '
               . 'if (!is_array($c)) { fwrite(STDERR, "$f is not valid JSON\n"); exit(1); } '
               . 'if (!isset($c["mcpServers"]["playwright"])) { echo "not named"; exit(0); } unset($c["mcpServers"]["playwright"]); '
               . 'if (!$c["mcpServers"]) $c["mcpServers"] = new stdClass(); '
               . 'if (file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) { fwrite(STDERR, "could not write $f\n"); exit(1); } echo "removed";';
        [$c, $o] = TenantHost::ssh($inst, 'app', 'php -r ' . escapeshellarg($unset), null, 30);
        if ($c !== 0) return ['ok' => false, 'steps' => $steps, 'error' => "could not edit {$inst->slug}'s .mcp.json: " . trim((string) $o)];
        $steps[] = "the app's .mcp.json: playwright " . trim((string) $o);
        return ['ok' => true, 'steps' => $steps];
    }
}
