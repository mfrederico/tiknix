<?php
/**
 * 16_PlanThreadsPerProject.php — build notices are one conversation per plan OF ONE PROJECT.
 *
 * lib/PlanNotifier keyed its "Build finished" / "Build plan failed" conversation on the plan
 * (or prompt) id alone. Those ids are per project — every project has its own workbench.db —
 * so each project's plan #32 joined the same conversation, owned by whichever project's
 * owner got there first. One held four projects' notices; fabian's landed in threads owned
 * by the operator and the reverse. (2026-09-24)
 *
 * thread.project_slug makes the key (type, id, project). Existing notice threads are split:
 * each message's project is read from its own text ("finished on <slug>", "planner for
 * <slug>"); the thread keeps the first project's messages, every other project gets its
 * own thread owned by that project's owner, seated as a participant. A project that no
 * longer exists has no owner to give it — its messages stay in the thread they are in,
 * and the seed says so.
 */
use \RedBeanPHP\R;

if ($_tableCheck('thread') && !array_key_exists('project_slug', R::inspect('thread'))) {
    R::exec("ALTER TABLE thread ADD COLUMN project_slug TEXT DEFAULT ''");
    R::exec('CREATE INDEX IF NOT EXISTS idx_thread_related_project ON thread (related_type, related_id, project_slug)');
    echo "  thread.project_slug added\n";
}

if (is_core_install() && $_tableCheck('thread') && $_tableCheck('message') && $_tableCheck('instance')) {
    $slugOf = function (string $body): string {
        return preg_match('/(?:finished on|planner for)\s+([a-z0-9][a-z0-9.-]*[a-z0-9])/i', $body, $m) ? strtolower($m[1]) : '';
    };
    $ownerOf = fn(string $slug): int => (int) R::getCell('SELECT member_id FROM instance WHERE slug = ?', [$slug]);
    $seat = function (int $threadId, int $memberId): void {
        if ($memberId <= 0) return;
        if ((int) R::getCell('SELECT COUNT(*) FROM threadmember WHERE thread_id = ? AND member_id = ?', [$threadId, $memberId]) > 0) return;
        $tm = R::dispense('threadmember');
        // History is already read: seating someone must not light up months of old notices.
        $tm->thread_id = $threadId; $tm->member_id = $memberId; $tm->role = 'owner';
        $tm->last_read_id = (int) R::getCell('SELECT MAX(id) FROM message WHERE thread_id = ?', [$threadId]);
        $tm->joined_at = date('Y-m-d H:i:s');
        R::store($tm);
    };
    // Recompute a thread's summary from the messages it now holds.
    $refresh = function (int $threadId): void {
        $last = R::getRow('SELECT body_plain, created_at FROM message WHERE thread_id = ? ORDER BY id DESC LIMIT 1', [$threadId]);
        R::exec('UPDATE thread SET message_count = (SELECT COUNT(*) FROM message WHERE thread_id = ?), last_preview = ?, last_message_at = ? WHERE id = ?',
            [$threadId, mb_substr(trim((string) ($last['body_plain'] ?? '')), 0, 200), (string) ($last['created_at'] ?? ''), $threadId]);
    };

    $threads = R::getAll("SELECT * FROM thread WHERE related_type IN ('plan', 'promptfail') AND (project_slug IS NULL OR project_slug = '') ORDER BY id");
    $split = 0; $moved = 0;
    foreach ($threads as $t) {
        $tid = (int) $t['id'];
        $groups = [];
        foreach (R::getAll('SELECT id, body_plain, subject FROM message WHERE thread_id = ? ORDER BY id', [$tid]) as $m) {
            $groups[$slugOf((string) $m['body_plain'])][] = $m;
        }
        $slugs = array_values(array_filter(array_keys($groups), fn($s) => $s !== ''));
        if (!$slugs) continue;   // nothing says which project: leave it, it cannot be keyed
        $keep = $slugs[0];
        R::exec('UPDATE thread SET project_slug = ? WHERE id = ?', [$keep, $tid]);
        $seat($tid, (int) $t['owner_member_id']);
        foreach (array_slice($slugs, 1) as $slug) {
            $owner = $ownerOf($slug);
            if ($owner <= 0) {
                echo "  thread #{$tid}: " . count($groups[$slug]) . " notice(s) from '{$slug}', which is no longer a project — no owner to give them, left in place\n";
                continue;
            }
            $existing = (int) R::getCell('SELECT id FROM thread WHERE related_type = ? AND related_id = ? AND project_slug = ?', [$t['related_type'], (int) $t['related_id'], $slug]);
            if ($existing <= 0) {
                $n = R::dispense('thread');
                foreach (['kind', 'related_type', 'related_id', 'status', 'last_direction'] as $col) $n->$col = $t[$col];
                $n->subject = (string) $groups[$slug][0]['subject'];
                $n->project_slug = $slug;
                $n->owner_member_id = $owner;
                $n->message_count = 0;
                $n->created_at = $t['created_at'];
                $n->updated_at = date('Y-m-d H:i:s');
                $existing = (int) R::store($n);
            }
            $ids = array_map(fn($m) => (int) $m['id'], $groups[$slug]);
            R::exec('UPDATE message SET thread_id = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$existing], $ids));
            $seat($existing, $owner);
            $refresh($existing);
            $moved += count($ids); $split++;
        }
        $refresh($tid);
    }
    if ($split) echo "  build notices: moved {$moved} notice(s) into {$split} per-project conversation(s)\n";
}
