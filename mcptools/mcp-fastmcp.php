#!/usr/bin/env php
<?php
/**
 * mcp-fastmcp.php — fastmcphp-backed stdio MCP server (codebase introspection).
 *
 * Functional equivalent of mcp-stdio.php, but the JSON-RPC plumbing
 * (initialize / tools/list / tools/call framing, schema encoding) is handled by
 * fastmcphp instead of the hand-rolled loop. The tool bodies stay identical:
 * each fastmcphp tool delegates to the existing app\mcptools\*Tool::execute(),
 * so output is byte-for-byte the same and Introspector remains the one source
 * of truth.
 *
 * Selected by the provisioner (aibuilder-provision.php) as the "tiknix" MCP
 * server whenever fastmcphp is vendored (vendor/fastmcphp present); otherwise
 * the dependency-free mcp-stdio.php is used. Requires the upstream fastmcphp
 * change that makes react/http optional (require → require-dev/suggest) so it
 * can be `composer require --dev`'d without dragging psr/http-message ^1.0 into
 * tiknix's tree; the stdio transport itself never touches react/http.
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

// tiknix autoloader — provides app\mcptools\{Introspector, *Tool}, and fastmcphp
// itself once it's a (dev) dependency. In production the provisioner only picks
// this server when vendor/fastmcphp exists, so this is the live path.
require_once $root . '/vendor/autoload.php';

// Dev-only fallback: a sibling fastmcphp checkout, so this server can be run and
// tested locally before fastmcphp is vendored into tiknix.
if (!class_exists(Fastmcphp::class)) {
    $sibling = dirname($root) . '/fastmcphp/vendor/autoload.php';
    if (is_file($sibling)) require_once $sibling;
}
if (!class_exists(Fastmcphp::class)) {
    fwrite(STDERR, "fastmcphp not found. Run: composer require --dev fastmcphp/fastmcphp\n");
    exit(1);
}

// Register tiknix's local tools via the shared builder (same registration path
// the HTTP Mcp gateway uses). Scoped to the read-only introspection + plan tools
// — the same allow-list as mcp-stdio.php, so the two servers are interchangeable.
$loader = new ToolLoader($root . '/mcptools');
$mcp = LocalMcpServer::build($loader, [
    'name'    => 'tiknix-introspect',
    'version' => '0.1.0',
    'instructions' => 'Deterministic, read-only introspection of this tiknix codebase. '
        . 'Call codebase_map first to orient, then describe(name) / whatprovides(concept) to drill down.',
], ['codebase_map', 'describe', 'whatprovides', 'submit_plan']);

// Force the pure-PHP blocking loop (fgets on STDIN) instead of the default
// swoole coroutine path. In a jail with (open)swoole loaded, the coroutine
// loop's feof(STDIN) check does not reliably detect pipe EOF, so the process
// lingers after Claude Code closes stdin to signal shutdown. Blocking mode
// exits cleanly on EOF — the correct lifecycle for a stdio subprocess.
$mcp->run(transport: new StdioTransport(useSwoole: false));
