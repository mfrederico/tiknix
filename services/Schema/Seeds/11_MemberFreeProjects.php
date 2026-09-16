<?php
/**
 * 11_MemberFreeProjects.php — the member.free_projects column.
 *
 * Per-member FREE-project allowance an admin can set (ProjectQuota::freeCapFor). 0/unset
 * means "use the global FREE_CAP". Created here so writing it never adds the column on a
 * live request. It only ever holds a small integer, so an INTEGER ghost is enough — no
 * widen risk to guard against (unlike the date columns in 09/10). Guarded on existence.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(member)'), 'name');

if (!in_array('free_projects', $_cols, true)) {
    $g = R::dispense('member');
    $g->email         = 'schema-ghost-freeprojects@invalid';   // trashed at once; never a real account
    $g->status        = 'ghost';
    $g->free_projects = 0;
    R::store($g);
    $_defer($g);
    unset($g);
}

unset($_cols);
