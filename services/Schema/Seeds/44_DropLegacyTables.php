<?php
/**
 * 44_DropLegacyTables.php — tables in the control plane's database that nothing uses any more.
 *
 * They are dropped, not left: an unused table that still holds rows answers a query that
 * should have failed. The audit of holistica's build read plan #1 from core's old
 * `workbenchtask` (the Builder kept its tasks here before each project got its own
 * workbench.db) and mailed the owner another task's title.
 *
 *   workbenchtask, tasklog, taskcomment, tasksnapshot,     the Builder's tasks before they moved
 *   workbenchtasklog, workbenchtaskcomment                 into each project (2026-08)
 *   shoporder, shopsubscription                            the Store sidecar, retired 2026-09-21
 *   grocerylist, groceryitem                               a demo; no code has named them since
 *
 * Children first (they reference workbenchtask). Idempotent: a table already gone is skipped.
 * The database as it stood is in secure/backups/tiknix-before-legacy-table-drop-20261005.db.
 */
use RedBeanPHP\R;

$have = array_flip(R::inspect());
foreach (['workbenchtasklog', 'workbenchtaskcomment', 'tasklog', 'taskcomment', 'tasksnapshot', 'workbenchtask',
          'shopsubscription', 'shoporder', 'groceryitem', 'grocerylist'] as $table) {
    if (!isset($have[$table])) continue;
    $rows = (int) R::getCell("SELECT COUNT(*) FROM `{$table}`");
    R::exec("DROP TABLE `{$table}`");
    echo "  dropped {$table} ({$rows} row(s))\n";
}
