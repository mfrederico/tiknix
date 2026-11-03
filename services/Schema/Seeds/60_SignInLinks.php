<?php
/** 60_SignInLinks.php — passwordless sign-in (lib/SignInLink.php): its table, typed, and its public pages; a hand-off's proven email. */
if (!$_tableCheck('signinlink')) {
    $b = $_dispensePadding('signinlink');
    $b->emailHash = str_repeat('x', 64); $b->ipHash = str_repeat('x', 64); $b->memberRef = 1; $b->tokenHash = str_repeat('x', 64);
    $b->landing = str_repeat('x', 60); $b->createdAt = '2026-01-01 00:00:00'; $b->expiresAt = '2026-01-01 00:00:00'; $b->usedAt = str_repeat('x', 40);   // text: '' is "not used yet"; a date-typed column would be widened by the first ''
    \app\Bean::store($b); $_defer($b);
}
$cols = \app\Bean::inspect('signinlink');
if (!isset($cols['join_email'])) \app\Bean::exec("ALTER TABLE signinlink ADD COLUMN join_email TEXT DEFAULT ''");   // a create-your-account link's address, cleared once used
\app\Bean::exec('CREATE INDEX IF NOT EXISTS index_signinlink_token ON signinlink (token_hash)');
\app\Bean::exec('CREATE INDEX IF NOT EXISTS index_signinlink_email ON signinlink (email_hash, created_at)');
\app\Bean::exec('CREATE INDEX IF NOT EXISTS index_signinlink_ip ON signinlink (ip_hash, created_at)');
\app\Bean::exec('CREATE INDEX IF NOT EXISTS index_signinlink_member ON signinlink (member_ref, created_at)');
$cols = \app\Bean::inspect('planhandoff');
if ($cols && !isset($cols['email_verified'])) \app\Bean::exec('ALTER TABLE planhandoff ADD COLUMN email_verified INTEGER DEFAULT 0');
if ($cols && !isset($cols['email'])) \app\Bean::exec('ALTER TABLE planhandoff ADD COLUMN email TEXT');
echo '  authcontrol: signin::* => ' . \app\PermissionCache::seedRule('signin', '*', 101, 'Passwordless sign-in by emailed link (public)') . "\n";
echo '  authcontrol: handoff::join => ' . \app\PermissionCache::seedRule('handoff', 'join', 101, 'Get-started hand-off: make a passwordless account for a proven email, then build (public; checks itself)') . "\n";
