#!/usr/bin/env php
<?php
/**
 * Permission rows for the contact/support routes.
 *
 * Most of these already existed; contact::status had no row, and contact::delete carried
 * an auto-generated one. Stating the whole set in one place is the point: the form is
 * public on purpose and the queue is not, and a route with no row is a row waiting to be
 * invented at the ADMIN default by whatever touches it first — which for /contact or
 * /contact/submit would quietly shut the public support form.
 *
 * Idempotent. seedRule() adds a missing row, corrects one the framework invented at the
 * default, and KEEPS one a person deliberately set — which is why the result is printed
 * rather than swallowed.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

require_once __DIR__ . '/../bootstrap.php';

// bootstrap.php only DEFINES Bootstrap; constructing it is what registers the autoloader
// and opens the database. Without this line every app\ class is "not found".
new app\Bootstrap('conf/config.ini');

use \app\PermissionCache;

$rules = [
    // The whole point of a support form is that someone who cannot log in can reach it.
    ['contact', 'index',   101, 'Support form (public)'],
    ['contact', 'submit',  101, 'Support form submission (public)'],
    // The queue and everything that acts on it.
    ['contact', 'admin',    50, 'Support message queue'],
    ['contact', 'view',     50, 'Read a support message'],
    ['contact', 'respond',  50, 'Reply to a support message'],
    ['contact', 'status',   50, 'Change a support message status'],
    ['contact', 'delete',   50, 'Delete a support message'],
];

$counts = [];
foreach ($rules as [$control, $method, $level, $desc]) {
    $result = PermissionCache::seedRule($control, $method, $level, $desc);
    $counts[$result] = ($counts[$result] ?? 0) + 1;
    printf("  %-16s %-8s -> %-3d  %s\n", $control . '::' . $method, '', $level, $result);
}

echo "\n";
foreach ($counts as $what => $n) {
    echo "  {$what}: {$n}\n";
}
if (!empty($counts['kept'])) {
    echo "\n  'kept' means somebody set that rule deliberately and it was left alone.\n";
}

PermissionCache::clear();
echo "\nPermission cache cleared.\n";
