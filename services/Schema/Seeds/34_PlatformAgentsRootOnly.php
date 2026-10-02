<?php
/**
 * 34_PlatformAgentsRootOnly.php — on the control plane the AI agents page holds TIKNIX's own
 * agents and keys (QA Testing's `qa` agent, the platform's Claude account): ROOT only.
 *
 * The runtime seeds agents::* at ADMIN for every install, which is right for an app (its
 * admins run its agents). Here it is the platform's money, so this install's rule is a
 * deliberate one — a row a person set, which the runtime's seedRule() then reports as `kept`
 * and never widens again.
 */

use \RedBeanPHP\R;

$_row = R::findOne('authcontrol', 'LOWER(control) = ? AND method = ?', ['agents', '*']);
if (!$_row) {
    $_row = R::dispense('authcontrol');
    $_row->control    = 'agents';
    $_row->method     = '*';
    $_row->validcount = 0;
    $_row->createdAt  = date('Y-m-d H:i:s');
}
$_was = $_row->id ? (int) $_row->level : null;
$_row->level       = 1;
$_row->description = "Tiknix's own AI agents and keys - platform tooling only (ROOT)";
$_row->updatedAt   = date('Y-m-d H:i:s');
R::store($_row);
// A method row is consulted before the wildcard: none may leave a door at ADMIN.
$_shadows = R::find('authcontrol', 'LOWER(control) = ? AND method != ? AND level > 1', ['agents', '*']);
foreach ($_shadows as $_s) { $_s->level = 1; R::store($_s); }
\app\PermissionCache::clear();
echo '  authcontrol: agents::* => ROOT' . ($_was === 1 ? ' (unchanged)' : ($_was === null ? ' (added)' : " (was {$_was})"))
   . (count($_shadows) ? ', ' . count($_shadows) . ' method row(s) raised to ROOT' : '') . "\n";
