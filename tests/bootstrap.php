<?php
/**
 * PHPUnit bootstrap — Composer's autoloader plus this install's concept (plugin) autoloader.
 *
 * The suites run on a scratch database, so the plugins this install has switched on have to
 * come from somewhere else: concepts.lock, a file in the repository, which is exactly what
 * Concepts::boot() reads. Before the lock file, every test of app code that used a plugin
 * had to require_once the plugin's files by hand, and the CLAUDE.md drift test composed
 * with no plugins and failed on every install that had one.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';
\app\Concepts::boot(dirname(__DIR__));
