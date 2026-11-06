<?php
/** The plugin's classes are declared BEFORE the app's autoloader runs: an app with it installed boots its INSTALLED copy, and
 *  these tests would test the wrong code. Run through tests/run.php from an app root (or TIKNIX_ROOT=…). */
require_once dirname(__DIR__) . '/lib/Session.php';
$root = getenv('TIKNIX_ROOT') ?: getcwd();
if (!is_file($root . '/vendor/autoload.php')) { fwrite(STDERR, "no vendor/autoload.php in {$root}: run from an app root or set TIKNIX_ROOT\n"); exit(2); }
require $root . '/vendor/autoload.php';
