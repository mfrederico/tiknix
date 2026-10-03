<?php
/**
 * 37_RemoveSocialAndPublish.php — the rows left behind by features removed on 2026-10-03:
 *
 *   - the public social showcase (/social/<slug>, table socialpage, scripts/sync-social-feeds)
 *     — it published a feed from a connection token read on this host, and a project's
 *     connections live in its container now;
 *   - the GitHub publish→PR flow and hosted/LXC deploy routes on Connections (publishing is a
 *     pipeline in the project; Deploy is controls/Deploy.php);
 *   - the Connections routes that only an app itself has (instanceconnect*, broker, test,
 *     publishfeed, pipelinerun) and the Agentsetup tool/hook actions no form posted to.
 *
 * Idempotent: a row that is already gone prints nothing.
 */

use RedBeanPHP\R;

$__gone = [
    'social'     => ['*'],
    'connections'=> ['setup', 'status', 'add', 'repos', 'createrepo', 'branches', 'resolveadd', 'resolveverify', 'resolveremove',
                     'deploy', 'lxcstatus', 'lxcdeploy', 'lxcrefresh', 'publish', 'test', 'publishfeed', 'pipelinerun', 'broker',
                     'instanceconnect', 'instanceconnectkey', 'instancedisconnect'],
    'agentsetup' => ['storeTool', 'updateTool', 'deleteTool', 'storeHook', 'updateHook', 'deleteHook', 'saveHookConfig',
                     'storetool', 'updatetool', 'deletetool', 'storehook', 'updatehook', 'deletehook', 'savehookconfig'],
];
foreach ($__gone as $__c => $__methods) {
    foreach ($__methods as $__m) {
        $__row = \app\Bean::findOne('authcontrol', 'control = ? AND method = ?', [$__c, $__m]);
        if ($__row && $__row->id) { \app\Bean::trash($__row); echo "  authcontrol: removed {$__c}::{$__m} (the route is gone)\n"; }
    }
}

// The showcase's table: empty on every install that ever had it (nothing was published).
if (in_array('socialpage', R::inspect(), true)) {
    $__n = (int) R::getCell('SELECT COUNT(*) FROM socialpage');
    if ($__n === 0) { R::exec('DROP TABLE socialpage'); echo "  socialpage: dropped (empty)\n"; }
    else echo "  socialpage: kept — {$__n} row(s) in it; drop it by hand once they are not wanted\n";
}
\app\PermissionCache::clear();
