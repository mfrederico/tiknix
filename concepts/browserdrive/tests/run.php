#!/usr/bin/env php
<?php
/**
 * Runs this plugin's tests: php concepts/browserdrive/tests/run.php from an app root (TIKNIX_ROOT=… elsewhere). The plugin's
 * classes are declared first (bootstrap.php), then PHPUnit runs on the app's vendor.
 */
require __DIR__ . '/bootstrap.php';
$root = getenv('TIKNIX_ROOT') ?: getcwd();
if (!defined('PHPUNIT_COMPOSER_INSTALL')) define('PHPUNIT_COMPOSER_INSTALL', $root . '/vendor/autoload.php');
$_SERVER['argv'] = array_merge([$_SERVER['argv'][0]], array_slice($_SERVER['argv'], 1), [__DIR__]);
PHPUnit\TextUI\Command::main();
