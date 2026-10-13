<?php
/**
 * 48_LeftoverRules.php — permission rows for pages Tiknix no longer has (/usage, "The page is
 * gone"): each named a controller or a page nothing answers, and read on /permissions as a page
 * that exists. /auth/invite became /signup/invite and /admin/instances became /fleet (seed 24).
 *
 * Also the Connections pages only an app itself has (as seed 37): the bucket's access pages and
 * the test text. The runtime seeds their rows for every install; this hub is its own controller
 * and sends people to the app's page (/projects/open?to=/connections/storage).
 */
foreach ([['mfred', 'index'], ['grocery', '*'], ['mcpconfig', 'index'], ['mcpregistry', '*'], ['mcpregistry', 'testconnection'], ['permissioncache', 'clear'],
          ['test', 'dbtest'], ['workbench', 'mondayrefresh'], ['admin', 'instances'], ['auth', 'invite'], ['leads', 'invite'],
          ['connections', 'storage'], ['connections', 'storageapply'], ['connections', 'storagecheck'], ['connections', 'smstest']] as [$control, $method]) {
    foreach (\app\Bean::find('authcontrol', 'LOWER(control) = ? AND LOWER(method) = ?', [$control, $method]) as $gone) {
        echo "  authcontrol: removed {$control}::{$method}\n";
        \app\Bean::trash($gone);
    }
}
\app\PermissionCache::clear();
