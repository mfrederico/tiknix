<?php
/**
 * 10_AccountClosure.php — the member.closed_at column for account closure.
 *
 * Member::closeaccount() soft-closes an account (status='closed') and stamps closed_at.
 * That column is created HERE, not on a live request: writing a 'Y-m-d H:i:s' value would
 * otherwise ADD the column mid-request (the fluid-schema-change ERROR) and, typed NUMERIC
 * from a date string, could later widen to TEXT and REBUILD the member table — the exact way
 * `member` was once emptied. Padded TEXT ghost, guarded on existence, exactly like 09.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(member)'), 'name');

if (!in_array('closed_at', $_cols, true)) {
    $g = R::dispense('member');
    $g->email     = 'schema-ghost-closure@invalid';   // trashed at once; never a real account
    $g->status    = 'ghost';
    $g->closed_at = str_repeat('x', 32);               // PADDED TEXT, not a date — see header
    R::store($g);
    $_defer($g);
    unset($g);
}

unset($_cols);
