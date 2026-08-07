<?php
/**
 * seed-lxc-hosting.php — authcontrol rows for the LXC hosting actions on the
 * Connections hub.
 *
 *   php scripts/seed-lxc-hosting.php && php scripts/resetcache.php
 *
 * LEVELS. Reading state is MEMBER; creating or restarting a container is ADMIN. That
 * split is deliberate: a deploy spends real hypervisor resources (a rootfs, two data
 * volumes, an address) and a restart interrupts a live tenant, so neither should be a
 * one-click action for every member while the path is this new. Relax lxcdeploy to 100
 * once tenants are self-serve and there is a quota to enforce.
 *
 * Note the sibling GitHub deploy actions (connections::deploy, resolveadd, …) have NO
 * authcontrol rows at all, so they fall through to the PUBLIC default and rely purely
 * on requireLogin()/ownedInstance() inside the controller. That works, but it means the
 * permission table does not describe the real policy — worth backfilling separately.
 *
 * Idempotent, and never widens an existing rule.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../bootstrap.php';

new app\Bootstrap('conf/config.ini');

use app\Bean;

$rows = [
    ['connections', 'lxcstatus',  100, 'Read this instance container state (safe to poll)'],
    ['connections', 'lxcdeploy',   50, 'Create or replace an instance container on the hypervisor'],
    ['connections', 'lxcrefresh',  50, 'Re-apply boot settings and restart an instance container'],
];

$added = $kept = 0;
foreach ($rows as [$control, $method, $level, $description]) {
    $bean = Bean::findOne('authcontrol', 'control = ? AND method = ?', [$control, $method]);
    if ($bean && $bean->id) {
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
    Bean::store($bean);
    $added++;
    echo "  ADD  {$control}::{$method} = {$level}\n";
}

echo "lxc hosting permissions: {$added} added, {$kept} already present\n";
echo "run `php scripts/resetcache.php` so the permission cache picks these up\n";
