<?php
/**
 * The plugin's classes are declared BEFORE the app's autoloader runs: an app that has this plugin installed boots it at
 * autoload time (its lib/app.php or controlplane.php registers providers), which would load the INSTALLED copy's classes
 * and leave these tests testing the wrong code. The libs need nothing from the app to be declared.
 *
 *   vendor/bin/phpunit --bootstrap concepts/messaging/tests/bootstrap.php concepts/messaging/tests      (from the app root)
 *   TIKNIX_ROOT=/path/to/a/tiknix vendor/bin/phpunit …                                                   (from the catalog)
 */
foreach (['Client', 'Providers', 'Sync', 'Reach', 'Campaigns', 'Digest', 'Runner'] as $c) require_once dirname(__DIR__) . "/lib/{$c}.php";
$root = getenv('TIKNIX_ROOT') ?: getcwd();
if (!is_file($root . '/vendor/autoload.php')) { fwrite(STDERR, "no vendor/autoload.php in {$root}: run from an app root or set TIKNIX_ROOT\n"); exit(2); }
require $root . '/vendor/autoload.php';
