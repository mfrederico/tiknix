<?php
/**
 * 29_Deploy.php — the Deploy page (controls/Deploy.php): a project's domains, and where its
 * code is exported to (rsync over SSH, an SSH command).
 *
 *   deploytarget   one export target of a project: instance_ref (the instance row; _ref, no foreign key — a project is hard-deleted), driver ('rsync' | 'ssh'),
 *                  config_json (host, user, port, path / command — not secrets; the SSH key
 *                  is the driver's, sealed in the project's store), and the last run.
 *                  A padded TEXT ghost, so no column is ever widened by a table rebuild.
 *
 * The page's routes are MEMBER level: the controller shows a project to anyone on it and
 * lets only its owner change or run anything. The Connections domain routes the page
 * replaced (seed 28, removed) are dropped.
 */

use \RedBeanPHP\R;

if (!$_tableCheck('deploytarget')) {
    $g = R::dispense('deploytarget');
    $g->instance_ref = 0;
    $g->driver       = str_repeat('x', 32);
    $g->label        = str_repeat('x', 200);
    $g->config_json  = str_repeat('x', 4000);
    $g->last_run_at  = date('Y-m-d H:i:s');
    $g->last_ok      = 0;
    $g->last_message = str_repeat('x', 2000);
    $g->created_at   = date('Y-m-d H:i:s');
    $g->updated_at   = date('Y-m-d H:i:s');
    R::store($g);
    $_defer($g);
    unset($g);
    echo "  deploytarget: created\n";
}

echo '  authcontrol: deploy::* => ' . \app\PermissionCache::seedRule('deploy', '*', 100, "Deploy: a project's domains and export targets (viewing: anyone on it; changes: its owner)") . "\n";
foreach (['domains', 'domainadd', 'domainremove'] as $__m) {
    foreach (\app\Bean::find('authcontrol', 'control = ? AND method = ?', ['connections', $__m]) as $__r) {
        \app\Bean::trash($__r);
        echo "  authcontrol: removed connections::{$__m} (moved to deploy)\n";
    }
}
