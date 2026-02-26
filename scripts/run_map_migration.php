#!/usr/bin/env php
<?php
/**
 * Run Map Permissions Migration
 * Temporary script to insert authcontrol entries for the Map feature
 *
 * Usage: php run_map_migration.php
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

// Load bootstrap
require_once __DIR__ . '/../bootstrap.php';

use \RedBeanPHP\R as R;

// Colors for output
define('GREEN', "\033[0;32m");
define('YELLOW', "\033[1;33m");
define('BLUE', "\033[0;34m");
define('NC', "\033[0m"); // No Color

echo BLUE . "\n=== Map Permissions Migration ===\n" . NC;

try {
    // Initialize application
    $app = new \app\Bootstrap('conf/config.ini');

    echo "\nAdding Map feature permissions...\n\n";

    // Map USA page - public access
    $entry1 = R::dispense('authcontrol');
    $entry1->control = 'map';
    $entry1->method = 'usa';
    $entry1->level = 101;
    $entry1->description = 'USA Map - Public access';
    $entry1->createdAt = date('Y-m-d H:i:s');
    R::store($entry1);
    echo GREEN . "✓ Added: map::usa (level 101)" . NC . "\n";

    // State details AJAX endpoint - public access
    $entry2 = R::dispense('authcontrol');
    $entry2->control = 'map';
    $entry2->method = 'statedetails';
    $entry2->level = 101;
    $entry2->description = 'State Details API - Public access';
    $entry2->createdAt = date('Y-m-d H:i:s');
    R::store($entry2);
    echo GREEN . "✓ Added: map::statedetails (level 101)" . NC . "\n";

    echo "\n" . BLUE . "=== Migration Complete ===" . NC . "\n";
    echo "Now run: " . YELLOW . "php scripts/resetcache.php" . NC . "\n\n";

} catch (\Exception $e) {
    echo "\n" . "\033[0;31m" . "Error: " . $e->getMessage() . NC . "\n\n";
    exit(1);
}
