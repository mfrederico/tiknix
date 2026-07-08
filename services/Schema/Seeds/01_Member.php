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
    // NOTE: google_id is intentionally omitted. RedBean treats *_id columns as
    // integer foreign keys, so padding it with a string fails on strict MySQL.
    // It's a nullable OAuth field; the column is created on first Google login.
    $s->reset_token = str_repeat('x', 128);
    $s->reset_expires = date('Y-m-d H:i:s');
    $s->last_login  = date('Y-m-d H:i:s');
    $s->login_count = 0;
    $s->created_at  = date('Y-m-d H:i:s');
    $s->updated_at  = date('Y-m-d H:i:s');
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

try {
    R::exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_member_username ON member (username)');
    R::exec('CREATE INDEX IF NOT EXISTS idx_member_email ON member (email)');
    R::exec('CREATE INDEX IF NOT EXISTS idx_member_level ON member (level)');
} catch (\Exception $e) { /* indexes may already exist */ }
