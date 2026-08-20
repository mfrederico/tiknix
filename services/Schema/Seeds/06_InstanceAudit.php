<?php
/**
 * 06_InstanceAudit.php — who changed a billable setting on an instance, and when.
 *
 * auto_triage is the first: turning it on lets the control plane launch headless agent
 * builds against that instance without anyone pressing anything, which is a spend
 * decision made on a client's behalf. "Who enabled this, and from when" has to be
 * answerable later, so it is a row rather than a log line.
 *
 * A TRAIL, not a current-state stamp. Two columns on instance would say who set the
 * value it has now and lose the fact that it was on for three weeks in between — which
 * is exactly the question a billing dispute asks. The latest row also answers the
 * current-state question, so one mechanism serves both.
 *
 * `field` is deliberately general. This is not speculative scope: every per-instance
 * setting worth charging for wants the same trail, and a second table that differed
 * only in its column name would be the divergence, not the reuse.
 */

use \RedBeanPHP\R;

// Pass 1 — padded sample to size columns; deferred (trashed after the build).
//
// EVERY column is sized here, including the dates. RedBean's SQLite writer types a
// 'Y-m-d H:i:s' value NUMERIC, and NUMERIC still has room to widen — a widen SQLite
// cannot do as an ALTER, so it rebuilds the table, and that rebuild is what emptied
// member on mileage. Padded text is the widest type the writer has, so nothing a
// later write contains can force one. See 01_Member.php for the full account.
if (!$_tableCheck('instanceaudit')) {
    $s = R::dispense('instanceaudit');
    // NULL, not 0. These are *_id columns, so RedBean emits real foreign keys to
    // instance and member — and a sample row pointing at id 0 fails the constraint it
    // just created, which aborts the seed. NULL types the column INTEGER just the same
    // and references nothing.
    $s->instance_id = null;                    // real FK: bean type 'instance' exists
    $s->member_id   = null;                    // who made the change
    $s->field       = str_repeat('x', 64);
    $s->old_value   = str_repeat('x', 255);
    $s->new_value   = str_repeat('x', 255);
    $s->note        = str_repeat('x', 500);
    $s->created_at  = str_repeat('x', 40);
    R::store($s);
    $_defer($s);
}
