<?php
/**
 * MCP Routes
 * Maps /mcp/* URLs to appropriate controllers
 */

use \Flight as Flight;

// MCP Message endpoint - main MCP protocol handler
// GET is for SSE stream (Streamable HTTP transport), POST for JSON-RPC requests
Flight::route('GET|POST /mcp/message', function() {
    $controller = new \app\Mcp();
    $controller->message([]);
});

// MCP Health check
Flight::route('GET /mcp/health', function() {
    $controller = new \app\Mcp();
    $controller->health([]);
});

// MCP Config endpoint
Flight::route('GET /mcp/config', function() {
    $controller = new \app\Mcp();
    $controller->config([]);
});

// /mcp/registry went with the Mcpregistry controller; MCP servers are configured in Agent Setup.
