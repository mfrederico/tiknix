#!/usr/bin/env php
<?php
/**
 * MCP Gateway Migration Script
 * Adds new columns and tables for MCP Gateway/Proxy functionality
 *
 * Usage: php database/migrate-mcp-gateway.php
 */

require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;

$app = new \app\Bootstrap();

echo "MCP Gateway Migration\n";
echo "=====================\n\n";

try {
    R::testConnection();
    echo "✓ Database connection successful\n\n";

    $migrations = [
        // mcpserver table - registry sync fields
        "ALTER TABLE mcpserver ADD COLUMN registry_id TEXT" => 'mcpserver.registry_id',
        "ALTER TABLE mcpserver ADD COLUMN registry_source TEXT DEFAULT 'local'" => 'mcpserver.registry_source',
        "ALTER TABLE mcpserver ADD COLUMN synced_at TEXT" => 'mcpserver.synced_at',

        // mcpserver table - gateway/proxy fields
        "ALTER TABLE mcpserver ADD COLUMN backend_auth_token TEXT" => 'mcpserver.backend_auth_token',
        "ALTER TABLE mcpserver ADD COLUMN backend_auth_header TEXT DEFAULT 'Authorization'" => 'mcpserver.backend_auth_header',
        "ALTER TABLE mcpserver ADD COLUMN tools_cache TEXT" => 'mcpserver.tools_cache',
        "ALTER TABLE mcpserver ADD COLUMN tools_cached_at TEXT" => 'mcpserver.tools_cached_at',
        "ALTER TABLE mcpserver ADD COLUMN is_proxy_enabled INTEGER DEFAULT 1" => 'mcpserver.is_proxy_enabled',
    ];

    echo "Adding columns to mcpserver table...\n";
    foreach ($migrations as $sql => $description) {
        try {
            R::exec($sql);
            echo "  ✓ Added: $description\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'duplicate column') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "  - Skipped (exists): $description\n";
            } else {
                echo "  ✗ Error: $description - " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\nCreating mcpusage table...\n";
    $createUsageTable = "
        CREATE TABLE IF NOT EXISTS mcpusage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            apikey_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            server_slug TEXT NOT NULL,
            tool_name TEXT NOT NULL,
            request_data TEXT,
            response_status TEXT,
            response_time_ms INTEGER,
            error_message TEXT,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ";
    try {
        R::exec($createUsageTable);
        echo "  ✓ Created mcpusage table\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - Skipped (exists): mcpusage table\n";
        } else {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    }

    echo "\nCreating indexes...\n";
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_mcpserver_registry ON mcpserver(registry_source)",
        "CREATE INDEX IF NOT EXISTS idx_mcpusage_apikey ON mcpusage(apikey_id)",
        "CREATE INDEX IF NOT EXISTS idx_mcpusage_member ON mcpusage(member_id)",
        "CREATE INDEX IF NOT EXISTS idx_mcpusage_server ON mcpusage(server_slug)",
        "CREATE INDEX IF NOT EXISTS idx_mcpusage_created ON mcpusage(created_at)",
    ];

    foreach ($indexes as $sql) {
        try {
            R::exec($sql);
            preg_match('/idx_\w+/', $sql, $matches);
            echo "  ✓ Created index: " . ($matches[0] ?? 'unknown') . "\n";
        } catch (\Exception $e) {
            echo "  - Skipped or error: " . $e->getMessage() . "\n";
        }
    }

    echo "\n";
    echo "========================================\n";
    echo "✓ MCP Gateway migration complete!\n";
    echo "========================================\n\n";

    echo "New features available:\n";
    echo "  • Backend authentication for proxied servers\n";
    echo "  • Tool caching for performance\n";
    echo "  • Usage logging and analytics\n";
    echo "  • Registry sync tracking\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
