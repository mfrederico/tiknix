<?php
/**
 * 27_TenantHosts.php — instance.ct_hosts: the other domains a project in its own container
 * serves as sites of their own (lib/TenantDomains.php; the app's side is app\Host), as JSON.
 *
 * A padded TEXT ghost, guarded on existence, so the column is created wide: a JSON list that
 * grows must never trigger RedBean's widen-by-rebuild on SQLite.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(instance)'), 'name');

if (!in_array('ct_hosts', $_cols, true)) {
    $hostsGhost = R::dispense('instance');
    $hostsGhost->slug = 'schema-ghost-cthosts';        // trashed at once; never routed
    $hostsGhost->ctHosts = str_repeat('x', 2000);
    R::store($hostsGhost);
    $_defer($hostsGhost);
    unset($hostsGhost);
    echo "  instance.ct_hosts: added\n";
}
