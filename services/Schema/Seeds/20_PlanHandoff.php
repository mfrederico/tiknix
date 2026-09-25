<?php
/**
 * 20_PlanHandoff.php — the `planhandoff` table (lib/PlanHandoff.php) and the /handoff routes.
 *
 * A plan the Get-started wizard hands over waits here, behind a random token, until the
 * visitor signs in and claims it as a project. Sized by a probe row so RedBean never widens
 * a column later (a widen rebuilds the SQLite table and drops its rows); the plan body and
 * the JSON blobs are TEXT.
 *
 * Routes: receive (an instance's broker key), state (the token) and claim (a visitor with
 * no account yet) are PUBLIC-reachable and self-authenticate; create is a signed-in member.
 */
use \RedBeanPHP\R;

if (!$_tableCheck('planhandoff')) {
    $h = R::dispense('planhandoff');
    $h->token               = str_repeat('x', 48);
    $h->status              = str_repeat('x', 16);      // offered | claimed
    $h->name                = str_repeat('x', 60);
    $h->plan_md             = str_repeat('x', 20000);
    $h->plan_sha            = str_repeat('x', 64);
    $h->blueprint_json      = str_repeat('x', 8000);
    $h->brief_json          = str_repeat('x', 8000);
    $h->resume_url          = str_repeat('x', 255);
    $h->source_instance_ref = 1;
    $h->member_ref          = 1;
    $h->instance_ref        = 1;
    $h->created_at          = str_repeat('x', 40);
    $h->claimed_at          = str_repeat('x', 40);
    R::store($h);
    $_defer($h);
    echo "  planhandoff table created\n";
}
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_planhandoff_token ON planhandoff (token)');
R::exec('CREATE INDEX IF NOT EXISTS idx_planhandoff_instance_ref ON planhandoff (instance_ref)');

foreach ([
    ['receive', 101, 'Get-started hand-off: an instance offers a plan (broker key)'],
    ['state',   101, 'Get-started hand-off: where a plan stands (token)'],
    ['claim',   101, 'Get-started hand-off: a visitor claims a plan (sign in / register first)'],
    ['create',  100, 'Get-started hand-off: a member turns a claimed plan into a project'],
] as [$method, $level, $desc]) {
    echo "  authcontrol: handoff::{$method} => " . \app\PermissionCache::seedRule('handoff', $method, $level, $desc) . "\n";
}
