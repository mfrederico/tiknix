<?php
/**
 * 48_LeftoverRules.php — permission rows for pages Tiknix no longer has (/usage, "The page is
 * gone"): each named a controller or a page nothing answers, and read on /permissions as a page
 * that exists. /auth/invite became /signup/invite and /admin/instances became /fleet (seed 24).
 */
foreach ([['mfred', 'index'], ['grocery', '*'], ['mcpconfig', 'index'], ['mcpregistry', '*'], ['mcpregistry', 'testconnection'], ['permissioncache', 'clear'],
          ['test', 'dbtest'], ['workbench', 'mondayrefresh'], ['admin', 'instances'], ['auth', 'invite'], ['leads', 'invite']] as [$control, $method]) {
    foreach (\app\Bean::find('authcontrol', 'LOWER(control) = ? AND LOWER(method) = ?', [$control, $method]) as $gone) {
        echo "  authcontrol: removed {$control}::{$method}\n";
        \app\Bean::trash($gone);
    }
}
\app\PermissionCache::clear();
