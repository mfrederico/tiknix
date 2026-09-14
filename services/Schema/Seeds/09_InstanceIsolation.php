<?php
/**
 * 09_InstanceIsolation.php — per-instance isolation state columns on `instance`.
 *
 * Isolation is applied asynchronously: the web tier enqueues a request to a spool and a
 * root systemd worker (capricorn isolation-worker.sh) does the privileged uid/pool work and
 * writes the result back here — so the projects UI can say "finishing setup" vs "isolated"
 * instead of leaving a member wondering whether provisioning worked, and a stuck one can be
 * surfaced rather than spinning forever.
 *
 * Columns:
 *   isolation_state  pending | active | failed   (NULL = not applicable / predates feature)
 *   isolated_at      last state change           (PADDED TEXT — see below)
 *   host             node the instance lives on  (multi-node placement; 'local' today)
 *
 * Declared with a padded GHOST, exactly like 07_Billing. RedBean types a column from the
 * first value it sees, and a 'Y-m-d H:i:s' string is typed NUMERIC, which can WIDEN to TEXT
 * later — and SQLite cannot ALTER a column type, so a widen REBUILDS the table, which is how
 * `member` once got emptied. Padded text is the widest type the writer has, so nothing
 * written afterward can force a rebuild. isolated_at is therefore padded TEXT, not a date.
 *
 * GUARDED on column existence: an install that already has these columns (e.g. core, where
 * they were created before this seed existed) must be LEFT ALONE — re-storing the padded
 * ghost into an existing NUMERIC isolated_at would itself trigger the widen/rebuild this
 * seed exists to prevent. Only a fresh table (no isolation_state column) gets the ghost.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(instance)'), 'name');

if (!in_array('isolation_state', $_cols, true)) {
    $isoGhost = R::dispense('instance');
    $isoGhost->slug            = 'schema-ghost-isolation';   // trashed at once; never routed
    $isoGhost->isolation_state = str_repeat('x', 16);
    $isoGhost->isolated_at     = str_repeat('x', 32);        // PADDED TEXT, not a date — see header
    $isoGhost->host            = str_repeat('x', 64);
    R::store($isoGhost);
    $_defer($isoGhost);
    unset($isoGhost);
}

unset($_cols);
