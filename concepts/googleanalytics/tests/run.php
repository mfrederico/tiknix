#!/usr/bin/env php
<?php
/**
 * Runs this plugin's tests. Not `vendor/bin/phpunit`: that script loads the app's autoloader first, and an app that has
 * this plugin installed boots its INSTALLED copy at autoload time — the tests would then test the wrong code. Here the
 * plugin's classes are declared first (bootstrap.php), then PHPUnit runs on the app's vendor.
 *
 *   php concepts/googleanalytics/tests/run.php [phpunit options]           from the app root
 *   TIKNIX_ROOT=/path/to/a/tiknix php tests/run.php                  from the catalog
 */
require __DIR__ . '/bootstrap.php';
$root = getenv('TIKNIX_ROOT') ?: getcwd();
if (!defined('PHPUNIT_COMPOSER_INSTALL')) define('PHPUNIT_COMPOSER_INSTALL', $root . '/vendor/autoload.php');
$_SERVER['argv'] = array_merge([$_SERVER['argv'][0]], array_slice($_SERVER['argv'], 1), [__DIR__]);
PHPUnit\TextUI\Command::main();
