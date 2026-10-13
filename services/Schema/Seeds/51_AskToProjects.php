<?php
/**
 * 51_AskToProjects.php — what was said in each project's Ask moves INTO the project: from the
 * platform's prompt log (promptlog.ask_*) to the project's own record (app\AskStore, in its board
 * database). Every message Ask has handled, and every request that became a plan, gets its item
 * there. Idempotent: a message already there is left as it is. The ask_* columns stay in the
 * prompt log as they were (nothing writes them any more).
 */
$have = array_column(\RedBeanPHP\R::getAll('PRAGMA table_info(promptlog)'), 'name');
if (!in_array('ask_state', $have, true)) { echo "  ask → projects: nothing to move (no ask columns)\n"; return; }
$rows = \RedBeanPHP\R::getAll("SELECT id, member_id, instance_tag, body, created_at, plan_uid, plan_ref, ask_state, ask_answer, ask_note, ask_noted_at
                               FROM promptlog WHERE source = 'decompose' AND instance_tag != '' AND ((ask_state IS NOT NULL AND ask_state != '') OR (plan_uid IS NOT NULL AND plan_uid != '')) ORDER BY id");
$moved = 0; $skipped = []; $dirs = [];
foreach ($rows as $r) {
    $slug = (string) (strstr((string) $r['instance_tag'], '.', true) ?: $r['instance_tag']);
    if (!isset($dirs[$slug])) {
        try { $d = \Model_Instance::dirForSlug($slug); $dirs[$slug] = is_dir($d . '/data') ? $d : ''; }
        catch (\Throwable $e) { $dirs[$slug] = ''; }
    }
    if ($dirs[$slug] === '') { $skipped[$slug] = ($skipped[$slug] ?? 0) + 1; continue; }   // the project is gone, or has no board
    $dir = $dirs[$slug];
    if (\app\AskStore::get($dir, (int) $r['id'])) continue;
    $parts = \app\AskTriage::splitAsked(trim(\app\GoalBrief::split((string) $r['body'])['goal']));
    $planned = trim((string) $r['plan_uid']) !== '';
    $state = $planned ? 'planned' : (in_array((string) $r['ask_state'], \app\AskStore::STATES, true) ? (string) $r['ask_state'] : 'build');
    \app\AskStore::ensure($dir, (int) $r['id'], (int) $r['member_id'], trim($parts['said']), $state === 'planned' ? 'build' : $state, (string) $r['created_at']);
    if (in_array($state, ['answer', 'unsure', 'failed', 'asking'], true)) \app\AskStore::mark($dir, (int) $r['id'], $state, (string) $r['ask_answer'], (string) $r['ask_note']);
    foreach ($parts['qa'] as $qa) \app\AskStore::addQa($dir, (int) $r['id'], $qa['q'], $qa['a']);
    if ($planned) \app\AskStore::planned($dir, (int) $r['id'], (int) $r['plan_ref']);
    elseif ($parts['qa']) \app\AskStore::mark($dir, (int) $r['id'], $state, (string) $r['ask_answer'], (string) $r['ask_note']);
    if (trim((string) ($r['ask_noted_at'] ?? '')) !== '') \app\AskStore::noted($dir, (int) $r['id']);
    $moved++;
}
echo "  ask → projects: {$moved} message(s) moved into " . count(array_filter($dirs)) . ' project(s)' . ($skipped ? '; left behind (project gone): ' . array_sum($skipped) : '') . "\n";
