<?php
/**
 * 33_HandoffProgress.php — what a Get-started hand-off does after its claim, now that a project
 * lives in its own container (lib/PlanHandoff.php):
 *
 *   planhandoff.progress       setting-up | plan-committed | waiting-agent | planning | failed
 *   planhandoff.progress_note  the reason, for the claim page
 *   planhandoff.decompose      1 = plan Phase 1 once PLAN.md is in
 *   planhandoff.finished_at    when PLAN.md was committed
 *   handoff::phaseone          a member starts Phase 1 after connecting the app's agent
 *
 * and instance.ct_mqtt (TenantHost::mqttSync: domain → broker listener port, JSON).
 * Padded TEXT ghosts, guarded on existence, so each column is born TEXT — a column RedBean later
 * widens is rebuilt, and the rebuild drops the rows.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(planhandoff)'), 'name');
if ($_cols && !in_array('progress', $_cols, true)) {
    $g = R::dispense('planhandoff');
    $g->token         = 'schema-ghost-' . bin2hex(random_bytes(17));
    $g->status        = 'ghost';
    $g->progress      = str_repeat('x', 32);
    $g->progress_note = str_repeat('x', 2000);
    $g->decompose     = 1;
    $g->finished_at   = str_repeat('x', 40);
    R::store($g);
    $_defer($g);
    echo "  planhandoff: progress, progress_note, decompose, finished_at added\n";
}

$_icols = array_column(R::getAll('PRAGMA table_info(instance)'), 'name');
if (!in_array('ct_mqtt', $_icols, true)) {
    $g = R::dispense('instance');
    $g->slug   = 'schema-ghost-ctmqtt';    // trashed at once; never routed
    $g->ctMqtt = str_repeat('x', 2000);
    R::store($g);
    $_defer($g);
    echo "  instance.ct_mqtt: added\n";
}

echo "  authcontrol: handoff::phaseone => "
   . \app\PermissionCache::seedRule('handoff', 'phaseone', 100, 'Get-started hand-off: start Phase 1 once the app has an agent') . "\n";
