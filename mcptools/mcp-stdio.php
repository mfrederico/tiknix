#!/usr/bin/env php
<?php
/**
 * mcp-stdio.php — stdio MCP server exposing tiknix codebase-introspection tools
 * to the in-jail AI Builder / worktree agent.
 *
 * The jail blocks loopback + private networks, so an HTTP MCP endpoint is
 * unreachable from inside; Claude Code launches THIS as a subprocess and talks
 * newline-delimited JSON-RPC over stdin/stdout. Reuses the existing ToolLoader,
 * scoped to the introspection tools (workbench tools get added later).
 *
 * Registered per instance via .mcp.json:
 *   { "mcpServers": { "tiknix": { "command": "php", "args": ["mcptools/mcp-stdio.php"] } } }
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

// Tools this server exposes (deterministic, no auth needed — read-only introspection).
$ALLOW = ['codebase_map', 'describe', 'whatprovides', 'submit_plan'];
$loader = new \app\mcptools\ToolLoader($root . '/mcptools');

$send   = function (array $msg) { fwrite(STDOUT, json_encode($msg) . "\n"); };
$result = fn($id, $res) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $res];
$error  = fn($id, $code, $m) => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $m]];

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $req = json_decode($line, true);
    if (!is_array($req)) continue;

    $id     = $req['id'] ?? null;
    $method = $req['method'] ?? '';
    $params = $req['params'] ?? [];

    // Notifications (no id) get no response.
    if ($id === null && strpos($method, 'notifications/') === 0) continue;

    switch ($method) {
        case 'initialize':
            $send($result($id, [
                'protocolVersion' => $params['protocolVersion'] ?? '2024-11-05',
                'capabilities'    => ['tools' => new \stdClass()],
                'serverInfo'      => ['name' => 'tiknix-introspect', 'version' => '0.1.0'],
            ]));
            break;

        case 'ping':
            $send($result($id, new \stdClass()));
            break;

        case 'tools/list':
            $defs = array_values(array_filter($loader->getDefinitions(), fn($d) => in_array($d['name'], $ALLOW, true)));
            $send($result($id, ['tools' => $defs]));
            break;

        case 'tools/call':
            $name = $params['name'] ?? '';
            $args = $params['arguments'] ?? [];
            if (!in_array($name, $ALLOW, true) || !$loader->has($name)) {
                $send($error($id, -32601, "Unknown tool: {$name}"));
                break;
            }
            try {
                $text = $loader->execute($name, is_array($args) ? $args : []);
                $send($result($id, ['content' => [['type' => 'text', 'text' => $text]]]));
            } catch (\Throwable $e) {
                $send($result($id, ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true]));
            }
            break;

        default:
            if ($id !== null) $send($error($id, -32601, "Method not found: {$method}"));
    }
}
