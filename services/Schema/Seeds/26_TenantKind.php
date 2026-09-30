<?php
/**
 * 26_TenantKind.php — instance.ct_kind: what kind of container a project lives in.
 *
 *   'tenant'   its own container on the tiknix-app template (TenantHost::create; RUNTIME-SPLIT-MAP
 *              step 4/5): the app is there, the builder works there over SSH, and core keeps only
 *              the builder's records in _workspaces/<slug> (Model_Instance::dirOf).
 *   ''         a host clone of core (or no container at all).
 *
 * Padded TEXT ghost, guarded on existence; then the projects that already have a tenant
 * container (every ct_vmid > 0 once the old OCI containers were deleted, 2026-09-30) are
 * marked, once — a row that already says what it is is left alone.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(instance)'), 'name');

if (!in_array('ct_kind', $_cols, true)) {
    $kindGhost = R::dispense('instance');
    $kindGhost->slug = 'schema-ghost-ctkind';          // trashed at once; never routed
    $kindGhost->ctKind = str_repeat('x', 16);
    R::store($kindGhost);
    $_defer($kindGhost);
    unset($kindGhost);
    $_n = (int) R::exec("UPDATE instance SET ct_kind = 'tenant' WHERE ct_vmid > 0 AND (ct_kind IS NULL OR ct_kind = '') AND slug != 'schema-ghost-ctkind'");
    echo "  instance.ct_kind: {$_n} tenant(s) marked\n";
    unset($_n);
}

unset($_cols);
