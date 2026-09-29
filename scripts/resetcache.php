<?php
// The runtime's resetcache command, run from this app's root. The command itself lives in the
// runtime (runtime/bin/resetcache.php); this file is the app's door to it.
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';
require \app\Paths::runtime() . '/bin/resetcache.php';
