<?php
/**
 * 01_Member.php — user accounts. Seeds the bootstrap admin + the system
 * public-user-entity (level 101), mirroring sql/schema.sql but via beans so it
 * works on any dialect.
 *
 * The admin keeps the default admin123 hash on purpose: Install::isInstalled()
 * treats that hash as "not yet installed", so a fresh deploy still routes
 * through the /install wizard to force a real password.
 */

use \RedBeanPHP\R;

// Pass 1 — padded sample to size columns; deferred (trashed after the build).
if (!$_tableCheck('member')) {
    $s = R::dispense('member');
    $s->email       = '__schema_seed_' . str_repeat('x', 200);
    $s->username    = '__schema_seed_' . str_repeat('x', 80);
    $s->password    = str_repeat('x', 255);
    $s->level       = 999;
    $s->status      = str_repeat('x', 32);
    $s->first_name  = str_repeat('x', 100);
    $s->last_name   = str_repeat('x', 100);
    $s->bio         = str_repeat('x', 2000);
    $s->avatar_url  = str_repeat('x', 500);
    // The Google subject id, under the _eid convention: it is a string id issued by
    // another system, and the _id suffix is reserved for RedBean's integer FKs. Named
    // google_id it could not be sized here at all — RedBean would have read it as a
    // foreign key to a bean type 'google' and a padded string would fail on strict
    // MySQL — which is exactly how it ended up in the same unsized state as
    // display_name below.
    $s->google_eid  = str_repeat('x', 128);
    $s->reset_token = str_repeat('x', 128);
    // Dates are PADDED, not written as dates. RedBean's SQLite writer pattern-matches
    // 'Y-m-d H:i:s' and types the column NUMERIC — and NUMERIC still has somewhere to
    // widen to, which is the whole hazard described below. Padding makes them TEXT,
    // the widest type the writer has, so no later value can ever force a rebuild.
    // SQLite stores dates as text regardless: it has no date storage class at all.
    $s->reset_expires = str_repeat('x', 40);
    $s->last_login  = str_repeat('x', 40);
    $s->login_count = 0;
    $s->created_at  = str_repeat('x', 40);
    $s->updated_at  = str_repeat('x', 40);

    // EVERY TEXT COLUMN MUST BE SIZED HERE, OR IT IS BORN FROM ITS FIRST VALUE.
    //
    // A column missing from this pass is created later by whatever writes it first, and
    // RedBean types it from that value. display_name was absent, something numeric landed
    // in it, and it became INTEGER. The first member to type a real name made RedBean
    // widen INTEGER -> TEXT; SQLite cannot ALTER a column, so RedBean rebuilt the table —
    // and the rebuild died on the reserved index name SQLite generates for the inline
    // UNIQUE columns, AFTER the DROP and BEFORE the rows were copied back. Six accounts on
    // mileage.tiknix.com became zero, and because SYSTEM_ADMIN_ID is an id rather than a
    // level, AUTOINCREMENT handed the next login id 1 and made an ordinary member root.
    //
    // The widen was not even needed: SQLite has type AFFINITY, not enforcement, and would
    // have stored the name in an INTEGER column quite happily. The rebuild was RedBean
    // applying MySQL reasoning to a database that does not work that way. Sizing the
    // column here means it is never asked to.
    $s->display_name         = str_repeat('x', 200);
    // Padded for the same reason as the other dates above.
    $s->invited_at           = str_repeat('x', 40);
    // Genuinely numeric flags — sized so the column exists with the right affinity from
    // the start rather than being invented by the first writer.
    $s->needs_password_setup = 0;
    $s->is_active            = 1;
    $s->email_verified       = 0;
    $s->invited_by           = 0;
    R::store($s);
    $_defer($s);
}

// Pass 2a — bootstrap admin (idempotent). admin123 hash == Install DEFAULT_HASH.
$admin = \app\Bean::findOne('member', 'username = ?', ['admin']);
if (!$admin) {
    $admin = R::dispense('member');
    $admin->email      = 'admin@example.com';
    $admin->username   = 'admin';
    $admin->password   = '$2y$10$jVz654DI7bX8e1Dh32O9suFcMW4x1V.0SrniJNpDyknwkzc6gM20a';
    $admin->level      = 1;
    $admin->status     = 'active';
    $admin->login_count = 0;
    $admin->created_at = date('Y-m-d H:i:s');
    R::store($admin);
}

// Pass 2b — system public-user-entity for unauthenticated requests (level 101).
$public = \app\Bean::findOne('member', 'username = ?', ['public-user-entity']);
if (!$public) {
    $public = R::dispense('member');
    $public->email      = 'public@localhost';
    $public->username   = 'public-user-entity';
    $public->password   = '';
    $public->level      = 101;
    $public->status     = 'system';
    $public->login_count = 0;
    $public->created_at = date('Y-m-d H:i:s');
    R::store($public);
}

// Schema is 100% bean-derived — no hand-declared indexes/constraints. RedBean
// indexes *_id columns by convention; there is no bean-native way to declare a
// standalone UNIQUE (username) or a plain lookup index (email, level), so those
// are not created at the DB level. Username uniqueness is enforced in the
// registration/auth flow before insert.
