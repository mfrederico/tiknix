<?php
/**
 * Backfill the reason onto plans that stalled BEFORE the orchestrator learned to
 * record one. Read-only unless --apply.
 */
require '/var/www/html/default/tiknix/vendor/autoload.php';
require '/var/www/html/default/tiknix/bootstrap.php';
new app\Bootstrap();

$db    = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
if (!is_file($db)) { fwrite(STDERR, "usage: explain-stall.php <workbench.db> [--apply]\n"); exit(2); }

$p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$all = $p->query('SELECT id,status,plan_status,title,depends_on,parent_task_id,progress_message FROM workbenchtask')
         ->fetchAll(PDO::FETCH_ASSOC);
$by = [];
foreach ($all as $r) $by[(int) $r['id']] = $r;

$ok = ['merged', 'resolved'];

foreach ($all as $plan) {
    if (($plan['plan_status'] ?? '') !== 'stalled') continue;
    $pid = (int) $plan['id'];

    $subs = array_values(array_filter($all, fn($r) => (int) $r['parent_task_id'] === $pid));
    $blocked = [];
    foreach ($subs as $t) {
        if ($t['status'] !== 'pending') continue;
        $why = [];
        foreach ((json_decode((string) $t['depends_on'], true) ?: []) as $d) {
            $dep = $by[(int) $d] ?? null;
            if (!$dep) { $why[] = "#{$d} (no such task in this plan)"; continue; }
            if (in_array($dep['status'], $ok, true)) continue;
            $why[] = sprintf('#%d %s — %s', (int) $dep['id'], $dep['status'],
                mb_substr(trim(preg_replace('/\s+/', ' ', (string) $dep['title'])), 0, 48));
        }
        if ($why) $blocked[] = ['task' => (int) $t['id'],
                                'title' => mb_substr(trim((string) $t['title']), 0, 48),
                                'blockers' => $why];
    }

    $roots = app\PlanExecutor::rootCauses($blocked);
    $msg = $roots
        ? 'Stalled: ' . count($blocked) . ' subtask(s) cannot start. Fix ' . implode('; ', $roots) . '.'
        : 'Stalled: ' . count($blocked) . ' subtask(s) cannot start and no cause could be identified.';

    printf("\n  PLAN #%d — %s\n    %s\n", $pid, mb_substr((string) $plan['title'], 0, 56), $msg);
    foreach ($blocked as $b) printf("      #%-3d %-42s <- %s\n", $b['task'], $b['title'], implode(', ', $b['blockers']));

    if ($apply) {
        // The column is added BEFORE preparing, not caught around executing: SQLite
        // resolves column names at prepare time, so a try/catch around execute()
        // never sees the error at all.
        $cols = array_column($p->query('PRAGMA table_info(workbenchtask)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('blocked_json', $cols, true)) {
            $p->exec('ALTER TABLE workbenchtask ADD COLUMN blocked_json TEXT');
        }
        $st = $p->prepare('UPDATE workbenchtask SET progress_message = ?, blocked_json = ?, updated_at = ? WHERE id = ?');
        $st->execute([$msg, json_encode($blocked, JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s'), $pid]);
        echo "      written\n";
    }
}
echo $apply ? "\n  applied\n" : "\n  dry run — pass --apply\n";
