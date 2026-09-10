<?php
/**
 * 08_PendingSignup.php — a signup that has been started but has no account yet.
 *
 * Phase 3 of BILLING_PLAN.md. When card-on-file signup is switched on, /auth/doregister
 * writes one of these INSTEAD of creating a member. The member is created only after the
 * billing service confirms, server to server, that a card is really on file.
 *
 * That is the whole reason this table exists. The alternative — create the member, then
 * ask for a card — is how you end up with accounts that were never meant to exist, and
 * the browser's return from the hosted card form cannot be the thing that decides,
 * because the visitor controls that URL.
 *
 * The password arrives here ALREADY HASHED. This row is not an account, but it is one
 * verification away from becoming one, so it is treated as an account for storage
 * purposes.
 *
 * `token` is what the browser carries back; it is a lookup key, not a credential — it
 * grants nothing on its own, because the promotion still depends on what the billing
 * service says about the card.
 *
 * Rows expire. An abandoned signup must not hold an email address hostage forever, and a
 * table of half-finished attempts with card intent attached is not something to keep.
 */

use \RedBeanPHP\R;

// Padded sample to size every column; deferred so the builder trashes it. Dates are
// PADDED rather than written as dates: RedBean types a 'Y-m-d H:i:s' value NUMERIC, and
// NUMERIC can still widen — a widen SQLite performs by rebuilding the table. See
// 01_Member.php for the full account.
if (!$_tableCheck('pendingsignup')) {
    $s = R::dispense('pendingsignup');
    $s->token           = str_repeat('x', 64);   // opaque lookup key, url-safe hex
    $s->email           = str_repeat('x', 200);
    $s->password_hash   = str_repeat('x', 255);
    $s->first_name      = str_repeat('x', 100);
    $s->last_name       = str_repeat('x', 100);
    $s->username        = str_repeat('x', 80);

    // What the billing service calls this signup. Set once the tenant is registered, so
    // the completion step knows which tenant to ask about. String id from another system,
    // hence _eid — _id is reserved for RedBean's integer foreign keys.
    $s->billing_tenant_eid = str_repeat('x', 64);

    // 'awaiting_card' | 'completed' | 'expired'
    $s->status          = str_repeat('x', 32);

    // Why a signup ended the way it did — read when someone asks what happened to theirs.
    $s->note            = str_repeat('x', 255);

    $s->created_at      = str_repeat('x', 40);
    $s->expires_at      = str_repeat('x', 40);
    $s->completed_at    = str_repeat('x', 40);

    R::store($s);
    $_defer($s);
}

// Lookup is always by token or by email, and the email index is what stops two
// simultaneous signups racing for the same address.
R::exec("CREATE UNIQUE INDEX IF NOT EXISTS uk_pendingsignup_token ON pendingsignup (token)");
R::exec("CREATE INDEX IF NOT EXISTS idx_pendingsignup_email ON pendingsignup (email)");
R::exec("CREATE INDEX IF NOT EXISTS idx_pendingsignup_status ON pendingsignup (status)");
