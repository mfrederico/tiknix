<?php
/**
 * The app's web and CLI entry point. Everything it does is the runtime's (app\Front); this
 * file only loads, in the order that matters:
 *   1. the error page of last resort — FIRST, by path, before Composer, so that whatever
 *      breaks afterwards (the autoloader included) still ends in a real page;
 *   2. this app's Composer autoloader.
 */
require __DIR__ . '/../vendor/tiknix/runtime/lib/fatal-handler.php';
require __DIR__ . '/../vendor/autoload.php';
\app\Front::serve();
