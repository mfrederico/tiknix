<?php
/** The plugin's classes are declared BEFORE the app's autoloader runs: an app that has it enabled boots its INSTALLED copy, and these tests would test the wrong code or crash redeclaring it. Run through tests/run.php. */
foreach (['Groups', 'Known', 'Settings', 'Inventory', 'Choice', 'Policy', 'Banner', 'Guard'] as $c) require_once dirname(__DIR__, 2) . "/consent/lib/{$c}.php";
foreach (explode(',', 'Tag') as $c) require_once dirname(__DIR__) . "/lib/{$c}.php";
$root = getenv('TIKNIX_ROOT') ?: getcwd();
if (!is_file($root . '/vendor/autoload.php')) { fwrite(STDERR, "no vendor/autoload.php in {$root}: run from an app root or set TIKNIX_ROOT\n"); exit(2); }
require $root . '/vendor/autoload.php';
