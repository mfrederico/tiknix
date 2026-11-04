<?php
/**
 * 61_ChangeRequests.php — changes asked for on a project's own site (lib/SiteChanges.php): the `changerequest`
 * table, typed (dates and decisions as text, every id an INTEGER), the broker routes an app sends them through, and
 * the team's pages.
 */
if (!$_tableCheck('changerequest')) {
    $inst = (int) \app\Bean::getCell('SELECT MIN(id) FROM instance');
    if ($inst <= 0) throw new \RuntimeException('61_ChangeRequests: there is no project yet, so the changerequest table cannot be made.');
    $b = $_dispensePadding('changerequest');
    $b->instanceId = $inst; $b->appRef = 999999999; $b->site = str_repeat('x', 120);
    $b->fromName = str_repeat('x', 120); $b->fromEmail = str_repeat('x', 200); $b->levelName = str_repeat('x', 40);
    $b->said = str_repeat('x', 4000); $b->page = str_repeat('x', 500); $b->browser = str_repeat('x', 300);
    $b->errorsJson = str_repeat('x', 3000); $b->shotsJson = str_repeat('x', 2000); $b->status = str_repeat('x', 20);
    $b->threadRef = 999999999; $b->promptRef = 999999999; $b->decidedRef = 999999999;
    $b->decidedAt = str_repeat('x', 19); $b->createdAt = str_repeat('x', 19); $b->updatedAt = str_repeat('x', 19);
    \app\Bean::store($b); $_defer($b);
    echo "  changerequest: made\n";
} else {
    echo "  changerequest: unchanged\n";
}
\app\Bean::exec('CREATE UNIQUE INDEX IF NOT EXISTS changerequest_app ON changerequest (instance_id, site, app_ref)');
\app\Bean::exec('CREATE INDEX IF NOT EXISTS changerequest_status ON changerequest (instance_id, status)');
\app\Bean::exec('CREATE INDEX IF NOT EXISTS changerequest_prompt ON changerequest (prompt_ref)');
echo '  authcontrol: brokerinfo::change => ' . \app\PermissionCache::seedRule('brokerinfo', 'change', 101, "Someone on a project's site asks its builders for a change (broker key)") . "\n";
echo '  authcontrol: brokerinfo::changes => ' . \app\PermissionCache::seedRule('brokerinfo', 'changes', 101, "Where a site's change requests are, for its askers (broker key)") . "\n";
echo '  authcontrol: brokerinfo::changereply => ' . \app\PermissionCache::seedRule('brokerinfo', 'changereply', 101, 'An asker adds to their change request from the site (broker key)') . "\n";
echo '  authcontrol: changerequests::* => ' . \app\PermissionCache::seedRule('changerequests', '*', 100, "Change requests from the sites of a member's projects (each checks the project)") . "\n";
