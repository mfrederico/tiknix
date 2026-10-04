<?php
/**
 * 24_SplitRoutes.php — permission rows for routes that moved in the runtime split
 * (RUNTIME-SPLIT-MAP.md step 2). Control plane only (this seed is core's):
 *   helpdesk::*  the member support desk, split out of Contact (was contact::ask, members)
 *   signup::*    invitation acceptance and card-on-file completion, split out of Auth (public)
 *   fleet::*     the per-instance unattended-build page, split out of Admin (admins)
 *   permissions::*  rows of the deleted duplicate of /admin/permissions are removed
 *   help::*, docs::*  the platform's help centre and documentation, moved from the runtime's
 *                seed 02 when the controllers came back to the control plane (public)
 */
echo '  authcontrol: helpdesk::* => ' . \app\PermissionCache::seedRule('helpdesk', '*', 100, 'Member support desk (signed-in members)') . "\n";
// /signup/invite and /signup/complete: public, as /auth/invite and /auth/complete were.
echo '  authcontrol: signup::* => ' . \app\PermissionCache::seedRule('signup', '*', 101, 'Platform sign-up: invitations and card-on-file completion (public)') . "\n";
// /fleet — the per-instance unattended-build switch, moved from /admin/instances (admins).
echo '  authcontrol: fleet::* => ' . \app\PermissionCache::seedRule('fleet', '*', 50, 'Every project: unattended builds (admins)') . "\n";
echo '  authcontrol: help::* => ' . \app\PermissionCache::seedRule('help', '*', 101, 'Help pages') . "\n";
echo '  authcontrol: docs::* => ' . \app\PermissionCache::seedRule('docs', '*', 101, 'Documentation') . "\n";
// /permissions was a second, view-less copy of /admin/permissions (the one that works); the
// controller is gone, so are its rows.
$__gone = \app\Bean::find('authcontrol', 'control = ?', ['permissions']);
foreach ($__gone as $__row) \app\Bean::trash($__row);
echo '  authcontrol: permissions::* (removed controller) => ' . count($__gone) . " row(s) deleted\n";
\app\PermissionCache::clear();
// The MCP tool editor and the hooks editor write PHP that runs on the server; both
// controllers demand ROOT in every method, and the table said 100 / 50. Tighten the rows to
// what the code enforces (never widens anything), so the table is the truth a reader and the
// role sweep can trust.
$__tight = 0;
foreach (\app\Bean::find('authcontrol', 'control IN (?, ?) AND level > 1', ['mcptools', 'hooks']) as $__row) {
    $__row->level = 1;
    $__row->description = 'Tightened to root: the controller requires root in every method';
    \app\Bean::store($__row);
    $__tight++;
}
echo "  authcontrol: mcptools/hooks rows tightened to root => {$__tight}\n";
\app\PermissionCache::clear();
