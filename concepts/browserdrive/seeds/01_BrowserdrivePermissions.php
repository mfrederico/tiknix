<?php
/**
 * 01_BrowserdrivePermissions.php — the runner page and what it calls are for admins (the person whose browser runs the
 * steps); agents drive through the MCP tool browserdrive_drive, which the platform authenticates by API key.
 */

use app\PermissionCache;

foreach ([
    ['*', 50, 'Browser drive: the runner page and its calls (admins)'],
    ['client', 101, 'Browser drive: the Node client script (public; holds no secret)'],
] as [$method, $level, $note]) {
    echo "  browserdrive::{$method}  " . PermissionCache::seedRule('browserdrive', $method, $level, $note) . "\n";
}
