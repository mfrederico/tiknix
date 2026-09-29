<?php
/**
 * 24_SplitRoutes.php — permission rows for routes that moved in the runtime split
 * (RUNTIME-SPLIT-MAP.md step 2). Control plane only (this seed is core's):
 *   helpdesk::*  the member support desk, split out of Contact (was contact::ask, members)
 *   signup::*    invitation acceptance and card-on-file completion, split out of Auth (public)
 *   fleet::*     the per-instance unattended-build page, split out of Admin (admins)
 */
echo '  authcontrol: helpdesk::* => ' . \app\PermissionCache::seedRule('helpdesk', '*', 100, 'Member support desk (signed-in members)') . "\n";
// /signup/invite and /signup/complete: public, as /auth/invite and /auth/complete were.
echo '  authcontrol: signup::* => ' . \app\PermissionCache::seedRule('signup', '*', 101, 'Platform sign-up: invitations and card-on-file completion (public)') . "\n";
// /fleet — the per-instance unattended-build switch, moved from /admin/instances (admins).
echo '  authcontrol: fleet::* => ' . \app\PermissionCache::seedRule('fleet', '*', 50, 'Every project: unattended builds (admins)') . "\n";
