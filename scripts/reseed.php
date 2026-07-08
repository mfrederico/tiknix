#!/usr/bin/env php
<?php
/**
 * reseed.php — build/refresh the database schema by running the numbered bean
 * seeds in services/Schema/Seeds/.
 *
 * Connects through Bootstrap, so it honours DB_DSN (SQLite locally, MySQL /
 * Postgres on a deploy) exactly like the app. RedBean emits dialect-correct DDL,
 * so the SAME seeds initialize any backend — no schema.sql dialect juggling.
 *
 * Idempotent: safe to run repeatedly (seeds check before creating).
 *
 * Usage:
 *   php scripts/reseed.php            # seed / top-up the configured database
 *   php scripts/reseed.php --fresh    # drop all tables first, then seed
 *   DB_DSN=mysql://user@host/db php scripts/reseed.php
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

chdir(dirname(__DIR__));
require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;
use app\services\Schema\WorkspaceSchemaBuilder;

$fresh = in_array('--fresh', $argv, true);

$app = new \app\Bootstrap(); // connects (DB_DSN-aware)

if (!R::testConnection()) {
    fwrite(STDERR, "reseed: database connection failed\n");
    exit(1);
}

$dbType = R::getDatabaseAdapter()->getDatabase()->getDatabaseType();
echo "reseed: connected ({$dbType})\n";

if ($fresh) {
    echo "reseed: --fresh — dropping existing tables\n";
    foreach (R::inspect() as $table) {
        try { R::exec("DROP TABLE IF EXISTS " . $table); echo "  dropped {$table}\n"; }
        catch (\Exception $e) { fwrite(STDERR, "  warn dropping {$table}: " . $e->getMessage() . "\n"); }
    }
}

echo "reseed: running seeds…\n";
$results = (new WorkspaceSchemaBuilder())->build();
foreach ($results as $file => $status) {
    echo "  {$file}: {$status}\n";
}

// Refresh the permission cache so the new authcontrol rows take effect.
if (class_exists('\app\PermissionCache')) {
    \app\PermissionCache::clear();
    echo "reseed: permission cache cleared\n";
}

R::close();
echo "reseed: done — login admin / admin123 (change immediately)\n";
