<?php
/**
 * 19_InstancePlan.php — the instance.plan column: which PRICED KIND a project is.
 *
 *   'project'   an app the member runs themselves     ($49/mo past the free allowance)
 *   'client'    an app built for somebody else        ($99/mo; hand-off, client preview)
 *
 * NULL/empty reads as 'project' (ProjectQuota::kindOf), so nothing existing changes
 * price by growing this column. Whether a project is FREE is not stored here: the free
 * allowance is the member's oldest projects, worked out at read time (ProjectQuota), so a
 * deleted first project hands the allowance to the next one without a migration.
 *
 * Padded TEXT ghost, guarded on existence — the pattern of 09_InstanceIsolation.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(instance)'), 'name');

if (!in_array('plan', $_cols, true)) {
    $planGhost = R::dispense('instance');
    $planGhost->slug = 'schema-ghost-plan';           // trashed at once; never routed
    $planGhost->plan = str_repeat('x', 16);
    R::store($planGhost);
    $_defer($planGhost);
    unset($planGhost);
}

unset($_cols);
