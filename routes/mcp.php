<?php
/**
 * MCP Routes
 * Maps /mcp/* URLs to appropriate controllers
 */

use \Flight as Flight;

// MCP Message endpoint - main MCP protocol handler
Flight::route('POST /mcp/message', function() {
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

// MCP Registry routes - maps /mcp/registry/* to Mcpregistry controller
Flight::route('GET /mcp/registry', function() {
    $controller = new \app\Mcpregistry();
    $controller->index([]);
});

Flight::route('GET /mcp/registry/api', function() {
    $controller = new \app\Mcpregistry();
    $controller->api([]);
});

Flight::route('GET /mcp/registry/add', function() {
    $controller = new \app\Mcpregistry();
    $controller->add([]);
});

Flight::route('POST /mcp/registry/add', function() {
    $controller = new \app\Mcpregistry();
    $controller->add([]);
});

Flight::route('GET /mcp/registry/edit', function() {
    $controller = new \app\Mcpregistry();
    $controller->edit([]);
});

Flight::route('POST /mcp/registry/edit', function() {
    $controller = new \app\Mcpregistry();
    $controller->edit([]);
});

Flight::route('GET /mcp/registry/delete', function() {
    $controller = new \app\Mcpregistry();
    $controller->delete([]);
});

Flight::route('GET /mcp/registry/logs', function() {
    $controller = new \app\Mcpregistry();
    $controller->logs([]);
});

Flight::route('GET|POST /mcp/registry/testConnection', function() {
    $controller = new \app\Mcpregistry();
    $controller->testConnection([]);
});

Flight::route('GET|POST /mcp/registry/fetchTools', function() {
    $controller = new \app\Mcpregistry();
    $controller->fetchTools([]);
});

Flight::route('POST /mcp/registry/fixSlug', function() {
    $controller = new \app\Mcpregistry();
    $controller->fixSlug([]);
});

Flight::route('POST /mcp/registry/fixApiKey', function() {
    $controller = new \app\Mcpregistry();
    $controller->fixApiKey([]);
});

Flight::route('POST /mcp/registry/startServer', function() {
    $controller = new \app\Mcpregistry();
    $controller->startServer([]);
});
