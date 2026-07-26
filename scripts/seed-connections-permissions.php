<?php
/**
 * seed-connections-permissions.php — backfill the authcontrol rows the Connections
 * GitHub actions never had.
 *
 *   php scripts/seed-connections-permissions.php && php scripts/resetcache.php
 *
 * WHY THIS EXISTS: a route with no authcontrol row falls through to the PUBLIC default,
 * so these eight endpoints were REACHABLE by anyone. They were not exploitable — every
 * one calls requireLogin() and all but `branches` also validate CSRF, and each resolves
 * its target through ownedInstance() — but the permission table described something
 * other than the real policy. That gap is the dangerous part: the next method added
 * beside them inherits public reachability silently, and an audit of authcontrol would
 * report these routes as intentionally open.
 *
 * MEMBER (100) for all of them, matching their siblings (add/connect/repos/...): these
 * are the instance owner's own actions, and ownedInstance() already restricts each to
 * an instance the caller owns. This TIGHTENS the effective policy (public -> member)
 * without changing behaviour for any legitimate caller.
 *
 * Deliberately NOT touched: connections::handoff stays 101, and githubwebhook keeps its
 * existing row — an inbound webhook cannot present a member session.
 *
 * Idempotent, and never widens an existing rule.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../bootstrap.php';

new app\Bootstrap('conf/config.ini');

use app\Bean;
use RedBeanPHP\R;

const LEVEL_MEMBER = 100;

$rows = [
    ['branches',      'List branches of the connected repo'],
    ['createrepo',    'Create a new GitHub repository and connect it'],
    ['deploy',        'Deploy a mapped domain into /hosted'],
    ['disconnect',    'Remove a connector connection'],
    ['resolveadd',    'Add a custom-domain deploy target'],
    ['resolveremove', 'Remove a custom-domain deploy target'],
    ['resolveverify', 'Verify DNS for a custom-domain deploy target'],
    ['test',          'Test a connector connection'],
];

$added = $kept = 0;
foreach ($rows as [$method, $description]) {
    $bean = R::findOne('authcontrol', 'control = ? AND method = ?', ['connections', $method]);
    if ($bean && $bean->id) {
        if ((int) $bean->level !== LEVEL_MEMBER) {
            echo "  NOTE connections::{$method} exists at level {$bean->level} — left as-is\n";
        }
        $kept++;
        continue;
    }
    $bean              = Bean::dispense('authcontrol');
    $bean->control     = 'connections';
    $bean->method      = $method;
    $bean->level       = LEVEL_MEMBER;
    $bean->description = $description;
    $bean->validcount  = 0;
    $bean->linkorder   = 0;
    $bean->createdAt   = date('Y-m-d H:i:s');
    R::store($bean);
    $added++;
    echo "  ADD  connections::{$method} = " . LEVEL_MEMBER . "\n";
}

echo "connections permissions: {$added} added, {$kept} already present\n";
echo "run `php scripts/resetcache.php` so the permission cache picks these up\n";
