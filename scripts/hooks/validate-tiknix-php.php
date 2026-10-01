<?php
// The runtime's code-standards hook (Write|Edit, wired in .claude/settings.json). The hook
// itself lives in the runtime (vendor/tiknix/runtime/bin/hooks/validate-tiknix-php.php) so
// every app enforces the same rules; this file is the app's door to it.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require \app\Paths::runtime() . '/bin/hooks/validate-tiknix-php.php';
