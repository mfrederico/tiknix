<?php
/**
 * How every runtime command finds its app: it runs FROM the app root. The app's shims
 * (scripts/clitool.php, scripts/resetcache.php, scripts/pipeline-cron.php) chdir there, and the
 * pipeline dispatcher and the update command cd there before they start a worker. A runtime
 * file cannot find the app from its own location — under vendor/, or a symlinked checkout of
 * the package, "the directory above mine" is not the app — so it does not try.
 */
$__app = getcwd();
if ($__app === false || !is_file($__app . '/vendor/autoload.php') || !is_file($__app . '/composer.json')) {
    fwrite(STDERR, 'ERROR: run this from the app root — ' . ($__app ?: '(no working directory)')
        . " has no composer.json + vendor/autoload.php. Use the app's scripts/clitool.php, or cd to the app first.\n");
    exit(1);
}
require_once $__app . '/vendor/autoload.php';
unset($__app);
