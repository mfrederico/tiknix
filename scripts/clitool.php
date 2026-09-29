<?php
// The runtime's clitool command, run from this app's root. The command itself lives in the
// runtime (vendor/tiknix/runtime/bin/clitool.php); this file is the app's door to it.
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';
require \app\Paths::runtime() . '/bin/clitool.php';
