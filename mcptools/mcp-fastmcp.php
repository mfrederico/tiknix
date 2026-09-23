#!/usr/bin/env php
<?php
/**
 * mcp-fastmcp.php — THE stdio MCP server (what .mcp.json launches for the jailed agent).
 *
 * fastmcphp does the protocol — initialize / tools/list / tools/call framing, schema
 * encoding — and each registered tool delegates to app\mcptools\*Tool::execute(), the
 * same classes the HTTP gateway (controls/Mcp.php → LocalMcpServer::build) serves. One
 * registration path, two transports.
 *
 * There is no fallback server. fastmcphp is a hard composer requirement of every
 * install; if vendor/fastmcphp is missing this process says so (log/mcp-<date>.log,
 * stderr) and exits 1 rather than running anything else. A dependency-free
 * mcp-stdio.php with a hand-rolled JSON-RPC loop used to sit beside this file "in
 * case" — nothing ever selected it, and a second implementation of the protocol that
 * nobody runs is a divergence waiting to be believed. Removed 2026-09-23.
 *
 * Test:
 *   echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | php mcptools/mcp-fastmcp.php
 *   echo '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'  | php mcptools/mcp-fastmcp.php
 *   echo '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"codebase_map","arguments":{}}}' | php mcptools/mcp-fastmcp.php
 */

declare(strict_types=1);

use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Transport\StdioTransport;
use app\mcptools\ToolLoader;
use app\mcptools\LocalMcpServer;

$root = dirname(__DIR__);

/**
 * DEBUG LOG — because a stdio MCP server fails invisibly.
 *
 * When this process dies the client can report only "disconnected". It cannot say more:
 * stdout IS the JSON-RPC channel, so nothing may be printed there, and stderr is usually
 * discarded by whatever launched it. That leaves no evidence anywhere, which is exactly
 * why "it keeps failing" has been unanswerable.
 *
 * Always on, one line per lifecycle event, in log/mcp-<date>.log. It records the two
 * facts that explain nearly every failure of this thing: the working directory it was
 * started in — the configured script path is RELATIVE, so it only resolves from the
 * project root — and which PHP binary ran it.
 */
$mcpLog = static function (string $level, string $msg) use ($root): void {
    $dir = $root . '/log';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
    @file_put_contents(
        $dir . '/mcp-' . date('Y-m-d') . '.log',
        sprintf("[%s] %-5s pid=%d %s\n", date('Y-m-d H:i:s'), $level, getmypid(), $msg),
        FILE_APPEND
    );
};

$mcpLog('start', sprintf('cwd=%s php=%s argv=%s',
    getcwd() ?: '?', PHP_VERSION, json_encode(array_slice($argv ?? [], 1))));

/**
 * A fatal — a parse error, a method that vanished under a mid-edit deploy, exhausted
 * memory — otherwise ends this process with nothing written anywhere at all. This is the
 * single most useful line in the file when something has gone wrong.
 */
register_shutdown_function(static function () use ($mcpLog): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $mcpLog('FATAL', sprintf('%s at %s:%d', $e['message'], $e['file'], (int) $e['line']));
        return;
    }
    $mcpLog('end', 'stopped cleanly (stdin closed or the loop returned)');
});

/** An uncaught exception dies the same silent death. */
set_exception_handler(static function (\Throwable $t) use ($mcpLog): void {
    $mcpLog('FATAL', sprintf('uncaught %s: %s at %s:%d',
        get_class($t), $t->getMessage(), $t->getFile(), $t->getLine()));
    exit(1);
});

// tiknix autoloader — provides app\mcptools\{Introspector, *Tool}, and fastmcphp
// itself once it's a (dev) dependency. In production the provisioner only picks
// this server when vendor/fastmcphp exists, so this is the live path.
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $mcpLog('FATAL', 'no vendor/autoload.php at ' . $autoload . ' — run composer install');
    fwrite(STDERR, "vendor/autoload.php missing — run composer install\n");
    exit(1);
}
require_once $autoload;

// Dev-only fallback: a sibling fastmcphp checkout, so this server can be run and
// tested locally before fastmcphp is vendored into tiknix.
if (!class_exists(Fastmcphp::class)) {
    $sibling = dirname($root) . '/fastmcphp/vendor/autoload.php';
    if (is_file($sibling)) require_once $sibling;
}
if (!class_exists(Fastmcphp::class)) {
    $mcpLog('FATAL', 'fastmcphp not found (vendor/fastmcphp missing?)');
    fwrite(STDERR, "fastmcphp not found (vendor/fastmcphp). It is a hard requirement: run composer install in the project root.\n");
    exit(1);
}

// Register tiknix's local tools via the shared builder (same registration path
// the HTTP Mcp gateway uses), scoped to StdioAllowList: what is safe to hand to a
// caller with no identity.
try {
    $loader = new ToolLoader($root . '/mcptools');
    $mcp = LocalMcpServer::build($loader, [
        'name'    => 'tiknix-introspect',
        'version' => '0.1.0',
        'instructions' => 'Deterministic, read-only introspection of this tiknix codebase. '
            . 'Call reuse_digest FIRST when adding a feature — it is the inventory of what '
            . 'already exists. codebase_map orients; describe(name) / whatprovides(concept) '
            . 'drill down; check_redbean / check_flightphp / validate_php verify the result.',
    ], \app\mcptools\StdioAllowList::names());
} catch (\Throwable $t) {
    // Registration reads mcptools/ from disk, so one broken tool file kills the server
    // before it ever speaks — indistinguishable from "never started" to a client.
    $mcpLog('FATAL', 'tool registration failed: ' . $t->getMessage()
        . ' at ' . $t->getFile() . ':' . $t->getLine());
    throw $t;
}

$mcpLog('ready', 'tools=codebase_map,describe,whatprovides,submit_plan — entering stdio loop');

// Force the pure-PHP blocking loop (fgets on STDIN) instead of the default
// swoole coroutine path. In a jail with (open)swoole loaded, the coroutine
// loop's feof(STDIN) check does not reliably detect pipe EOF, so the process
// lingers after Claude Code closes stdin to signal shutdown. Blocking mode
// exits cleanly on EOF — the correct lifecycle for a stdio subprocess.
$mcp->run(transport: new StdioTransport(useSwoole: false));
