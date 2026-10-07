<?php
namespace app;

/**
 * A browser lent to ONE app's agents. No browser lives in a container (an audit borrows one for
 * its own run, lib/AuditRunner.php); an app whose terminal sessions and build tasks need to LOOK
 * at its pages is lent one here: a Playwright MCP server on this machine, reached from inside the
 * container at 127.0.0.1:<port> over an SSH tunnel this machine holds open, and named `playwright`
 * in the app's .mcp.json so every agent of the app is given it (the runtime's AgentMcp).
 *
 * The browser is told the app's own addresses (--allowed-origins). That keeps an agent on its own
 * site; Playwright says itself that it is not a security boundary — the browser runs on THIS
 * machine, so lend one only to a project whose owner you trust with that.
 *
 * It is a tmux session (browser-<slug>) that nothing restarts: after this machine reboots, lend
 * it again. Not started for every app — each lent browser is a Chromium here when it is in use.
 */
class TenantBrowser {
    private const PACKAGE = '@playwright/mcp@0.0.83';   // the audit's (lib/AuditRunner.php)
    /**
     * Where an app's pages load their styles, fonts and scripts from (the runtime's layout and the
     * design system). A browser held to the app's own addresses alone renders every page UNSTYLED —
     * which an audit's design pass then reports as the page's fault.
     */
    public const PAGE_ASSETS = ['https://cdn.jsdelivr.net', 'https://fonts.googleapis.com', 'https://fonts.gstatic.com', 'https://code.jquery.com'];

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
        return array_map(fn($h) => 'https://' . $h, $hosts);
    }

    /**
     * Start (or leave running) the app's lent browser and name it in the app's .mcp.json.
     * @return array{ok:bool,steps?:string[],error?:string}
     */
    public static function lend(object $inst): array {
        if (!\Model_Instance::tenantRow($inst)) return ['ok' => false, 'error' => "{$inst->slug} does not live in its own container"];
        $npx = trim((string) shell_exec('bash -lc ' . escapeshellarg('command -v npx') . ' 2>/dev/null'));
        if ($npx === '') return ['ok' => false, 'error' => "npx is not on this machine's PATH — the lent browser (Playwright MCP) needs Node here"];
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
            $shots = "{$ws}/.aibuilder/browser-out";
            if (!is_dir($shots) && !@mkdir($shots, 0775, true)) return ['ok' => false, 'error' => "could not create {$shots}"];
            $browser = 'nice -n 10 ' . escapeshellarg($npx) . ' -y ' . self::PACKAGE . ' --headless --isolated --browser chromium --host 127.0.0.1 --port ' . $port
                     . ' --allowed-hosts ' . escapeshellarg("127.0.0.1:{$port}") . ' --allowed-origins ' . escapeshellarg(implode(';', array_merge($origins, self::PAGE_ASSETS)))
                     . ' --output-dir ' . escapeshellarg($shots);
            $tunnel = TenantHost::tunnelCommand($inst, $port);
            $path = escapeshellarg(dirname($npx));
            $qlog = escapeshellarg($log);
            $script = <<<BASH
#!/bin/bash
# Tiknix: the browser lent to {$inst->slug} (lib/TenantBrowser.php). Ends when the browser does.
export PATH={$path}:\$PATH
echo "[browser] {$inst->slug} on 127.0.0.1:{$port} \$(date)" > {$qlog}
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
            $steps[] = "started: tmux session {$session}, browser on 127.0.0.1:{$port} for " . implode(', ', $origins);
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
