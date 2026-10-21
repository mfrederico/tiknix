<?php
/**
 * app-watch.php — every ten minutes (cron): notice an app that has gone quiet, and start its container
 * again when it stopped by itself (app\AppWatch). Prints only what it did; ERROR lines go to stderr.
 */
chdir(dirname(__DIR__));   // cron and background launches start elsewhere; the app boots from its root
require dirname(__DIR__) . '/vendor/autoload.php';
require_once \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap();

$lock = fopen(dirname(__DIR__) . '/log/app-watch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);   // the last run is still at it (a restart waits for the app)

foreach (\app\AppWatch::run() as $line) {
    if (str_starts_with($line, 'ERROR')) fwrite(STDERR, date('c') . " {$line}\n");
    else echo date('c') . " {$line}\n";
}
