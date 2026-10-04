<?php
/**
 * McpProbe — can this project's agents reach their MCP servers? Asked where the agents run.
 *
 * A project's agents live in its container, so a check made from the control plane proves
 * nothing about them. This sends a small reader (PHP on stdin — no app needs a newer runtime
 * for it) into the container, where it does what an agent's MCP client does first: `initialize`,
 * then `tools/list`, against
 *   - the app's own server — the one every build task is given (the runtime's
 *     mcptools/mcp-fastmcp.php over stdio, exactly as AgentTask launches it), and
 *   - every server in the project's .mcp.json (what the terminal's agent loads): an http one by
 *     POST with the headers the file gives it, a stdio one by starting its command.
 * Each answers {ok, tools, ms, error}: the tool count when it works, the server's own words when
 * it does not. No header, key or environment value leaves the container — only the outcome.
 */
namespace app;

class McpProbe {

    /** The name the app's own server is reported under. */
    public const OWN = 'tiknix (this app)';

    /** @return array<string,array{ok:bool,type:string,tools:?int,ms:int,error:string}> */
    public static function run(object $inst, int $timeout = 12): array {
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php /dev/stdin ' . max(3, min(30, $timeout)), self::READER, 120);
        $d = json_decode((string) $o, true);
        if ($c !== 0 || !is_array($d)) throw new \RuntimeException("could not test MCP in {$inst->slug}'s container: " . mb_substr(trim((string) $o), 0, 240));
        return $d;
    }

    private const READER = <<<'PHP'
<?php
$T = (int) $argv[1];
$init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'tiknix-mcp-probe', 'version' => '1']]];
$list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []];
// An HTML error page is reported by its <title> (its body is styles and markup, not a reason).
$short = function ($s) { $s = (string) $s; if (stripos(ltrim($s), '<') === 0) $s = preg_match('#<title[^>]*>(.*?)</title>#is', $s, $m) ? $m[1] . ' (an HTML page, not an MCP server)' : 'an HTML page, not an MCP server'; return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, 240); };

$stdio = function (string $cmd, array $env, ?string $cwd) use ($T, $init, $list, $short) {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env ? array_merge(getenv(), $env) : null);
    if (!is_resource($p)) return [false, null, 'could not start: ' . $short($cmd)];
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $ask = function (array $msg, int $id) use ($pipes, $T) {
        fwrite($pipes[0], json_encode($msg) . "\n"); fflush($pipes[0]);
        $buf = ''; $end = microtime(true) + $T;
        while (microtime(true) < $end) {
            $buf .= (string) fread($pipes[1], 65536);
            while (($nl = strpos($buf, "\n")) !== false) {
                $j = json_decode(substr($buf, 0, $nl), true); $buf = substr($buf, $nl + 1);
                if (is_array($j) && ($j['id'] ?? null) === $id) return $j;
            }
            usleep(50000);
        }
        return null;
    };
    $r = $ask($init, 1);
    if ($r === null) { $err = (string) stream_get_contents($pipes[2]); proc_terminate($p); return [false, null, 'no answer to initialize in ' . $T . ' s' . ($err !== '' ? ': ' . $short($err) : '')]; }
    if (isset($r['error'])) { proc_terminate($p); return [false, null, 'initialize refused: ' . $short($r['error']['message'] ?? json_encode($r['error']))]; }
    fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) . "\n");
    $r = $ask($list, 2);
    proc_terminate($p);
    if ($r === null) return [false, null, 'initialized, but no answer to tools/list in ' . $T . ' s'];
    if (isset($r['error'])) return [false, null, 'tools/list refused: ' . $short($r['error']['message'] ?? json_encode($r['error']))];
    return [true, count((array) ($r['result']['tools'] ?? [])), ''];
};

$http = function (string $url, array $headers) use ($T, $init, $list, $short) {
    $call = function (array $msg, array $extra) use ($url, $headers, $T) {
        $h = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
        foreach ($headers + $extra as $k => $v) $h[] = "{$k}: {$v}";
        $resp = []; $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $T,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$resp) { if (strpos($line, ':')) { [$k, $v] = explode(':', $line, 2); $resp[strtolower(trim($k))] = trim($v); } return strlen($line); }]);
        $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
        if ($body === false) return [0, null, $err, $resp];
        $j = json_decode((string) $body, true);
        if (!is_array($j) && preg_match_all('/^data:\s*(\{.*\})\s*$/m', (string) $body, $m)) foreach ($m[1] as $d) { $x = json_decode($d, true); if (is_array($x) && isset($x['id'])) $j = $x; }
        return [$code, is_array($j) ? $j : null, (string) $body, $resp];
    };
    [$code, $j, $raw, $rh] = $call($init, []);
    if ($code === 0) return [false, null, 'could not connect: ' . $short($raw)];
    if ($code >= 400 || $j === null) return [false, null, "HTTP {$code} to initialize: " . $short($j['error']['message'] ?? $j['message'] ?? $raw)];
    if (isset($j['error'])) return [false, null, 'initialize refused: ' . $short($j['error']['message'] ?? json_encode($j['error']))];
    $sid = isset($rh['mcp-session-id']) ? ['Mcp-Session-Id' => $rh['mcp-session-id']] : [];
    [$code, $j, $raw] = $call($list, $sid);
    if ($code >= 400 || $j === null) return [false, null, "initialized, but HTTP {$code} to tools/list: " . $short($j['error']['message'] ?? $j['message'] ?? $raw)];
    if (isset($j['error'])) return [false, null, 'tools/list refused: ' . $short($j['error']['message'] ?? json_encode($j['error']))];
    return [true, count((array) ($j['result']['tools'] ?? [])), ''];
};

$out = [];
$run = function (string $name, string $type, callable $f) use (&$out) {
    $t0 = microtime(true);
    try { [$ok, $tools, $err] = $f(); } catch (Throwable $e) { [$ok, $tools, $err] = [false, null, $e->getMessage()]; }
    $out[$name] = ['ok' => $ok, 'type' => $type, 'tools' => $tools, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'error' => $err];
};

// the app's own server, as a build task's agent is given it
$own = 'vendor/tiknix/runtime/mcptools/mcp-fastmcp.php';
$run('tiknix (this app)', 'stdio', fn() => is_file($own) ? $stdio('exec php ' . escapeshellarg($own), [], getcwd()) : [false, null, "{$own} is not in this app"]);

// the project's .mcp.json
$cfg = is_file('.mcp.json') ? json_decode((string) file_get_contents('.mcp.json'), true) : [];
if (is_file('.mcp.json') && !is_array($cfg)) $out['.mcp.json'] = ['ok' => false, 'type' => 'file', 'tools' => null, 'ms' => 0, 'error' => '.mcp.json is not valid JSON'];
foreach ((array) ($cfg['mcpServers'] ?? []) as $name => $s) {
    if (!is_array($s)) continue;
    $url = (string) ($s['url'] ?? '');
    if ($url !== '') $run((string) $name, (string) ($s['type'] ?? 'http'), fn() => $http($url, array_map('strval', (array) ($s['headers'] ?? []))));
    elseif (!empty($s['command'])) $run((string) $name, 'stdio', fn() => $stdio('exec ' . escapeshellarg((string) $s['command']) . ' ' . implode(' ', array_map('escapeshellarg', array_map('strval', (array) ($s['args'] ?? [])))), array_map('strval', (array) ($s['env'] ?? [])), getcwd()));
    else $out[(string) $name] = ['ok' => false, 'type' => '?', 'tools' => null, 'ms' => 0, 'error' => 'the entry has neither a url nor a command'];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
PHP;
}
