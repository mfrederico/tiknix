#!/usr/bin/env php
<?php
/**
 * Database Initialization Script
 * Runs sql/schema.sql to initialize a fresh database
 *
 * Usage: php database/init.php [--fresh]
 *   --fresh: Drop existing database and create new one
 */

require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;

$app = new \app\Bootstrap();

echo "Tiknix Database Initialization\n";
echo "==============================\n\n";

// Check for --fresh flag
$fresh = in_array('--fresh', $argv);

try {
    R::testConnection();
    echo "✓ Database connection successful\n\n";

    $dbType = R::getDatabaseAdapter()->getDatabase()->getDatabaseType();
    echo "Database type: " . ucfirst($dbType) . "\n";

    if ($dbType === 'sqlite') {
        $schemaFile = __DIR__ . '/../sql/schema.sql';

        if (!file_exists($schemaFile)) {
            throw new Exception("Schema file not found: $schemaFile");
        }

        if ($fresh) {
            echo "\n⚠️  Fresh install requested - clearing existing data...\n";
            // Get all tables
            $tables = R::inspect();
            foreach ($tables as $table) {
                R::exec("DROP TABLE IF EXISTS $table");
                echo "  Dropped: $table\n";
            }
        }

        echo "\nRunning schema.sql...\n";

        // Read and execute schema
        $sql = file_get_contents($schemaFile);

        // Split by semicolon and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $executed = 0;
        foreach ($statements as $statement) {
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }
            try {
                R::exec($statement);
                $executed++;
            } catch (Exception $e) {
                // Ignore "already exists" errors for CREATE TABLE IF NOT EXISTS
                if (strpos($e->getMessage(), 'already exists') === false &&
                    strpos($e->getMessage(), 'UNIQUE constraint failed') === false) {
                    echo "  Warning: " . $e->getMessage() . "\n";
                }
            }
        }

        echo "  ✓ Executed $executed statements\n";

    } else {
        // For MySQL, use the mysql command
        echo "\nFor MySQL/MariaDB, run:\n";
        echo "  mysql -u user -p database < sql/schema.sql\n";
        exit(0);
    }

    // Clear permission cache
    \app\PermissionCache::clear();
    echo "\n✓ Permission cache cleared\n";

    echo "\n";
    echo "========================================\n";
    echo "✓ Database initialization complete!\n";
    echo "========================================\n\n";

    echo "Default login:\n";
    echo "  Username: admin\n";
    echo "  Password: admin123\n";
    echo "  ** CHANGE THIS PASSWORD IMMEDIATELY **\n\n";

    echo "Access points:\n";
    echo "  Home:        /\n";
    echo "  Admin:       /admin\n";
    echo "  API Keys:    /apikeys\n";
    echo "  MCP Server:  /mcp\n";
    echo "  MCP Registry:/mcpregistry\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
