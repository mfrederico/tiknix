<?php
/**
 * 30_LentConnections.php — instance.lent_connections: which of Tiknix's own connections are lent
 * to the project's app (lib/TenantShare.php), as JSON {type: {state, at}}.
 *
 * A padded TEXT ghost, so the column is created wide (a NUMERIC column RedBean later widens is
 * a table rebuild on SQLite). The first share wrote `sharedConnections` instead — a camelCase
 * NUMERIC column, because RedBean reads a `shared…` property its own way; its value moves here
 * and the column goes.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(instance)'), 'name');

if (!in_array('lent_connections', $_cols, true)) {
    $lentGhost = R::dispense('instance');
    $lentGhost->slug = 'schema-ghost-lent';           // trashed at once; never routed
    $lentGhost->lentConnections = str_repeat('x', 2000);
    R::store($lentGhost);
    $_defer($lentGhost);
    unset($lentGhost);
    echo "  instance.lent_connections: added\n";
}

if (in_array('sharedConnections', $_cols, true)) {
    R::exec("UPDATE instance SET lent_connections = sharedConnections WHERE sharedConnections IS NOT NULL AND sharedConnections <> '' AND (lent_connections IS NULL OR lent_connections = '')");
    R::exec('ALTER TABLE instance DROP COLUMN sharedConnections');
    echo "  instance.sharedConnections: moved to lent_connections, dropped\n";
}
