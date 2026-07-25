<?php
/**
 * seed-git-endpoint.php — authcontrol rows for the read-only git smart-HTTP endpoint.
 *
 *   php scripts/seed-git-endpoint.php && php scripts/resetcache.php
 *
 * Level 101 (PUBLIC) is deliberate and mirrors controls/Mcp.php: a git client must be
 * able to REACH the endpoint in order to authenticate, and git only sends credentials
 * after a 401 challenge. The controller gates every request on the target instance's
 * deploy token and serves git-upload-pack only, so there is no push path.
 *
 * Idempotent — safe to run on every deploy.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../bootstrap.php';

use app\Bean;
use RedBeanPHP\R;

// bootstrap.php only defines the class; instantiating it wires up the DB connection.
new app\Bootstrap('conf/config.ini');

// ONLY the wildcard row is meaningful here. The router parses /git/<slug>.git/info/refs
// as class=git, method="<slug>.git", so the permission lookup is against the SLUG — a
// per-method row could never match. controls/Git.php picks it up via _fallback.
$rows = [
    ['git', '*', 101, 'Git smart-HTTP endpoint (self-gated by per-instance deploy token)'],
];

$added = $kept = 0;
foreach ($rows as [$control, $method, $level, $description]) {
    $bean = R::findOne('authcontrol', 'control = ? AND method = ?', [$control, $method]);
    if ($bean && $bean->id) {
        // Never silently widen an existing rule — report instead.
        if ((int) $bean->level !== $level) {
            echo "  NOTE {$control}::{$method} exists at level {$bean->level}, expected {$level} — left as-is\n";
        }
        $kept++;
        continue;
    }
    $bean              = Bean::dispense('authcontrol');
    $bean->control     = $control;
    $bean->method      = $method;
    $bean->level       = $level;
    $bean->description = $description;
    $bean->validcount  = 0;
    $bean->linkorder   = 0;
    $bean->createdAt   = date('Y-m-d H:i:s');
    R::store($bean);
    $added++;
    echo "  ADD  {$control}::{$method} = {$level}\n";
}

echo "git endpoint permissions: {$added} added, {$kept} already present\n";
echo "run `php scripts/resetcache.php` so the permission cache picks these up\n";
