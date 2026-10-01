<?php
/**
 * 31_CtAliasesText.php — instance.ct_aliases is TEXT (a comma list, lib/TenantCarry.php).
 *
 * On tiknix.com the column was born INTEGER: the first store that touched it stored null. The
 * cutover then writes "serenity-denver.tiknix.com" into it, and RedBean widens INTEGER → TEXT
 * by rebuilding the table on SQLite — the rebuild that has emptied tables here before. So an
 * INTEGER column holding nothing is dropped and re-made wide with a padded TEXT ghost; one
 * holding values is a fault this seed will not guess about.
 */

use \RedBeanPHP\R;

$_types = array_column(R::getAll('PRAGMA table_info(instance)'), 'type', 'name');

if (isset($_types['ct_aliases']) && strtoupper($_types['ct_aliases']) !== 'TEXT') {
    $_held = (int) R::getCell("SELECT COUNT(*) FROM instance WHERE ct_aliases IS NOT NULL AND ct_aliases <> ''");
    if ($_held > 0) {
        throw new \RuntimeException("instance.ct_aliases is {$_types['ct_aliases']} and {$_held} row(s) hold a value — "
            . 'move them by hand before this seed makes the column TEXT (services/Schema/Seeds/31_CtAliasesText.php)');
    }
    R::exec('ALTER TABLE instance DROP COLUMN ct_aliases');
    echo "  instance.ct_aliases: was {$_types['ct_aliases']} and empty, dropped\n";
    unset($_types['ct_aliases']);
}

if (!isset($_types['ct_aliases'])) {
    $aliasGhost = R::dispense('instance');
    $aliasGhost->slug = 'schema-ghost-ctaliases';     // trashed at once; never routed
    $aliasGhost->ctAliases = str_repeat('x', 2000);
    R::store($aliasGhost);
    $_defer($aliasGhost);
    unset($aliasGhost);
    echo "  instance.ct_aliases: added as TEXT\n";
}
