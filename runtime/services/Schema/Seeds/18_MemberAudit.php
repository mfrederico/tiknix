<?php
/**
 * 18_MemberAudit.php — who changed a billable setting on a MEMBER, and when.
 *
 * free_projects is the first: raising it gives someone projects they are not billed for
 * (ProjectQuota::freeCapFor), which is money not collected. "Who granted these, when, and
 * why" has to be answerable later, so it is a trail — the same reasoning, and the same
 * shape, as instanceaudit (06); a separate table only because the subject is a member,
 * not an instance.
 *
 * _ref, not _id: two columns point at member (whose setting, and who changed it). Two
 * member_id-style FKs on one table is exactly what RedBean cannot name apart; and a trail
 * must outlive the rows it mentions. Indexed explicitly, as _ref columns get no index.
 */

use \RedBeanPHP\R;

// Padded sample to size every column (dates included) — see 06_InstanceAudit.php for why a
// later widen would rebuild the table.
if (!$_tableCheck('memberaudit')) {
    $s = R::dispense('memberaudit');
    $s->member_ref = 0;                        // whose setting
    $s->by_ref     = 0;                        // who changed it
    $s->field      = str_repeat('x', 64);
    $s->old_value  = str_repeat('x', 255);
    $s->new_value  = str_repeat('x', 255);
    $s->note       = str_repeat('x', 500);
    $s->created_at = str_repeat('x', 40);
    R::store($s);
    $_defer($s);
    R::exec('CREATE INDEX IF NOT EXISTS idx_memberaudit_member ON memberaudit (member_ref, field)');
}
