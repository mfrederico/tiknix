#!/usr/bin/env php
<?php
/**
 * MCP Registry Database Initialization Script
 * Creates permissions for MCP Registry controller
 */

require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;

$app = new \app\Bootstrap();

echo "MCP Registry Initialization Script\n";
echo "===================================\n\n";

try {
    R::testConnection();
    echo "✓ Database connection successful\n\n";

    // Create permissions for MCP Registry
    echo "Creating MCP Registry permissions...\n";

    $permissions = [
        ['control' => 'mcpregistry', 'method' => '*', 'level' => 50, 'description' => 'MCP Server Registry management'],
        ['control' => 'mcpregistry', 'method' => 'index', 'level' => 50, 'description' => 'List all MCP servers'],
        ['control' => 'mcpregistry', 'method' => 'add', 'level' => 50, 'description' => 'Add new MCP server'],
        ['control' => 'mcpregistry', 'method' => 'edit', 'level' => 50, 'description' => 'Edit MCP server'],
        ['control' => 'mcpregistry', 'method' => 'fetchTools', 'level' => 50, 'description' => 'Fetch tools from remote server'],
        ['control' => 'mcpregistry', 'method' => 'api', 'level' => 101, 'description' => 'Public JSON API for MCP servers'],
    ];

    foreach ($permissions as $perm) {
        $existing = R::findOne('authcontrol', 'control = ? AND method = ?', [$perm['control'], $perm['method']]);
        if (!$existing) {
            $auth = R::dispense('authcontrol');
            $auth->control = $perm['control'];
            $auth->method = $perm['method'];
            $auth->level = $perm['level'];
            $auth->description = $perm['description'];
            $auth->createdAt = date('Y-m-d H:i:s');
            R::store($auth);
            echo "  ✓ Created: {$perm['control']}/{$perm['method']} (level {$perm['level']})\n";
        } else {
            echo "  - Already exists: {$perm['control']}/{$perm['method']}\n";
        }
    }

    // Seed with the Tiknix MCP server as first registry entry
    echo "\nSeeding initial MCP server entry...\n";

    $existing = R::findOne('mcpserver', 'slug = ?', ['tiknix-mcp']);
    if (!$existing) {
        $server = R::dispense('mcpserver');
        $server->name = 'Tiknix MCP Server';
        $server->slug = 'tiknix-mcp';
        $server->description = 'Built-in Tiknix MCP server with demo tools for AI integration.';
        $server->endpointUrl = '/mcp/message';
        $server->version = '1.0.0';
        $server->status = 'active';
        $server->author = 'Tiknix';
        $server->authorUrl = '';
        $server->tools = json_encode([
            ['name' => 'hello', 'description' => 'Returns a friendly greeting'],
            ['name' => 'echo', 'description' => 'Echoes back the provided message'],
            ['name' => 'get_time', 'description' => 'Returns current server date/time'],
            ['name' => 'add_numbers', 'description' => 'Adds two numbers together'],
            ['name' => 'list_users', 'description' => 'Lists system users (admin only)'],
            ['name' => 'list_mcp_servers', 'description' => 'Lists registered MCP servers']
        ]);
        $server->authType = 'bearer';
        $server->documentationUrl = '/mcp';
        $server->iconUrl = '';
        $server->tags = json_encode(['tiknix', 'built-in', 'demo']);
        $server->featured = 1;
        $server->sortOrder = 0;
        $server->createdAt = date('Y-m-d H:i:s');
        $server->createdBy = 1;
        R::store($server);
        echo "  ✓ Created: Tiknix MCP Server\n";
    } else {
        echo "  - Already exists: Tiknix MCP Server\n";
    }

    echo "\n";
    echo "========================================\n";
    echo "✓ MCP Registry initialization complete!\n";
    echo "========================================\n\n";

    echo "Access the registry at: /mcpregistry\n";
    echo "Public API available at: /mcpregistry/api\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
