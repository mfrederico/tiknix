<?php
/**
 * BoardDigest — a project's work list, written out for a reader that is not a person: what was
 * asked, the plan each request became, every task with where it stands, what its agent last
 * said, why it failed, what the check found. The list is the record of the project; Ask reads it
 * here (AskTriage hands it to the agent that answers), so "why did the import fail?" is answered
 * from what happened and not from guessing at the code.
 *
 * Read from the project's board database without selecting it (PDO, read-only), newest work
 * last, kept to a budget: the last few requests in full, older ones as a line each.
 */

namespace app;

class BoardDigest {
    /** Characters, about: this is read by a model on every question. */
    public const BUDGET = 7000;

    private const DONE = ['merged', 'resolved', 'completed'];

    /**
     * What the board can be asked to do right now, and to what — for reading a typed message as an
     * action on the list (AskTriage) and for checking the target it names:
     *   plans   the recent requests a follow-up could be about        [id => title]
     *   retry   tasks that stopped and can be tried again             [id => title]
     *   fix     problems Tiknix found, not yet being fixed             [id => title]
     *   undo    the newest request, when it is finished and has a restore point  {id, title, checkpoint} | null
     * @return array{plans:array<int,string>,retry:array<int,string>,fix:array<int,string>,undo:?array}
     */
    public static function targets(string $dir): array {
        $out = ['plans' => [], 'retry' => [], 'fix' => [], 'undo' => null];
        $db = rtrim($dir, '/') . '/data/workbench.db';
        if (!is_file($db)) return $out;
        $pdo = new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'workbenchtask'")->fetchColumn()) return $out;
        $cols = array_column($pdo->query('PRAGMA table_info(workbenchtask)')->fetchAll(), 'name');
        $col = fn(string $c) => in_array($c, $cols, true) ? $c : "'' AS {$c}";
        $roots = $pdo->query('SELECT id, title, status, plan_status, ' . $col('source') . ', ' . $col('plan_checkpoint') . ' FROM workbenchtask WHERE parent_task_id IS NULL ORDER BY id DESC LIMIT 10')->fetchAll();
        foreach ($roots as $i => $r) {
            $found = (string) $r['source'] === 'detected_error';
            if ($found) { if (in_array((string) $r['status'], ['pending', 'failed'], true)) $out['fix'][(int) $r['id']] = (string) $r['title']; continue; }
            if (trim((string) $r['plan_status']) === '') continue;
            $out['plans'][(int) $r['id']] = (string) $r['title'];
            if ($i === 0 && (string) $r['plan_status'] === 'done' && trim((string) $r['plan_checkpoint']) !== '') {
                $out['undo'] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'checkpoint' => (string) $r['plan_checkpoint']];
            }
        }
        if ($out['plans']) {
            $ids = array_keys($out['plans']);
            $q = $pdo->prepare("SELECT id, title FROM workbenchtask WHERE status IN ('failed', 'conflict') AND parent_task_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC LIMIT 8');
            $q->execute($ids);
            foreach ($q->fetchAll() as $t) $out['retry'][(int) $t['id']] = (string) $t['title'];
        }
        return $out;
    }

    /** targets(), as the few lines the first reading of a message is given. */
    public static function targetsText(array $t, bool $planning): string {
        $l = [];
        foreach (array_reverse($t['plans'], true) as $id => $title) $l[] = "- request #{$id}: " . mb_strimwidth($title, 0, 90, '…');
        foreach ($t['retry'] as $id => $title) $l[] = "- task #{$id} STOPPED (can be tried again): " . mb_strimwidth($title, 0, 90, '…');
        foreach ($t['fix'] as $id => $title) $l[] = "- problem #{$id} that Tiknix found (not fixed yet): " . mb_strimwidth($title, 0, 90, '…');
        if ($t['undo']) $l[] = "- the newest finished request, which can be undone: #{$t['undo']['id']}";
        if ($planning) $l[] = '- a request is being planned right now (it can be stopped)';
        return implode("\n", $l);
    }

    public static function markdown(string $dir, int $items = 10): string {
        $db = rtrim($dir, '/') . '/data/workbench.db';
        if (!is_file($db)) return '';
        $pdo = new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'workbenchtask'")->fetchColumn()) return '';
        $cols = array_column($pdo->query('PRAGMA table_info(workbenchtask)')->fetchAll(), 'name');
        $col = fn(string $c) => in_array($c, $cols, true) ? $c : "'' AS {$c}";
        $roots = array_reverse($pdo->query('SELECT id, title, status, plan_status, ' . $col('source') . ', ' . $col('plan_goal') . ', ' . $col('audit_status') . ', ' . $col('audit_failures') . ', '
            . $col('progress_message') . ', ' . $col('error_message') . ', created_at FROM workbenchtask WHERE parent_task_id IS NULL ORDER BY id DESC LIMIT ' . max(1, $items))->fetchAll());
        if (!$roots) return '';
        $hasLog = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'tasklog'")->fetchColumn();
        $said = $hasLog ? $pdo->prepare("SELECT message FROM tasklog WHERE task_id = ? AND message LIKE 'Agent output (tail):%' ORDER BY id DESC LIMIT 1") : null;
        $kidsQ = $pdo->prepare('SELECT id, title, status, ' . $col('error_message') . ', ' . $col('progress_message') . ', ' . $col('asked_json') . ', ' . $col('related_files') . ' FROM workbenchtask WHERE parent_task_id = ? ORDER BY id');
        $one = fn(string $s, int $n) => mb_strlen($s = trim((string) preg_replace('/\s+/', ' ', $s))) > $n ? mb_substr($s, 0, $n) . '…' : $s;

        $blocks = [];
        foreach ($roots as $r) {
            $kidsQ->execute([(int) $r['id']]);
            $kids = $kidsQ->fetchAll();
            $found = (string) $r['source'] === 'detected_error';
            $state = $found ? 'a problem Tiknix found, ' . (string) $r['status'] : (trim((string) $r['plan_status']) !== '' ? (string) $r['plan_status'] : (string) $r['status']);
            $goal = trim(AskTriage::splitAsked(trim(GoalBrief::split((string) $r['plan_goal'])['goal']))['said']);
            $b = '### #' . (int) $r['id'] . ' ' . $one((string) $r['title'], 110) . " — {$state}" . ' (' . substr((string) $r['created_at'], 0, 16) . ')';
            if ($goal !== '' && !$found) $b .= "\nAsked: “" . $one($goal, 260) . '”';
            if (trim((string) $r['progress_message']) !== '') $b .= "\nNow: " . $one((string) $r['progress_message'], 200);
            if (trim((string) $r['error_message']) !== '' && !$kids) $b .= "\nWhy: " . $one((string) $r['error_message'], 300);
            if (trim((string) $r['audit_status']) !== '') $b .= "\nThe check afterwards: " . (string) $r['audit_status'] . ((int) $r['audit_failures'] > 0 ? ', ' . (int) $r['audit_failures'] . ' thing(s) failed' : '');
            foreach ($kids as $k) {
                $st = (string) $k['status'];
                $line = '- #' . (int) $k['id'] . " [{$st}] " . $one((string) $k['title'], 100);
                if (!in_array($st, self::DONE, true)) {
                    if (trim((string) $k['error_message']) !== '') $line .= "\n  why: " . $one((string) $k['error_message'], 320);
                    elseif (trim((string) $k['progress_message']) !== '') $line .= "\n  now: " . $one((string) $k['progress_message'], 240);
                } elseif (trim((string) $k['error_message']) !== '') {
                    $line .= ' — ' . $one((string) $k['error_message'], 60);   // "merged into the app as abc123"
                }
                $files = array_slice(array_values(array_filter(array_map('strval', (array) (json_decode((string) $k['related_files'], true) ?: [])))), 0, 6);
                if ($files) $line .= "\n  files: " . implode(', ', $files);
                foreach ((array) (json_decode((string) $k['asked_json'], true) ?: []) as $qa) {
                    $line .= "\n  it asked its person: " . $one((string) ($qa['q'] ?? ''), 200) . (trim((string) ($qa['a'] ?? '')) !== '' ? ' — answered: ' . $one((string) $qa['a'], 160) : ' — not answered yet');
                }
                if ($said) {
                    $said->execute([(int) $k['id']]);
                    if (($m = $said->fetchColumn()) !== false) {
                        // the END of what it said (its summary and handoff), not the middle of it
                        $words = trim((string) preg_replace('/\s+/', ' ', substr((string) $m, 20)));
                        $n = in_array($st, self::DONE, true) ? 240 : 500;
                        $line .= "\n  its agent's last words: " . (mb_strlen($words) > $n ? '…' . mb_substr($words, -$n) : $words);
                    }
                }
                $b .= "\n" . $line;
            }
            $blocks[] = $b;
        }
        // Newest in full; older ones give way to a line each until it fits.
        for ($i = 0; $i < count($blocks) - 1 && mb_strlen(implode("\n\n", $blocks)) > self::BUDGET; $i++) {
            $blocks[$i] = (string) strtok($blocks[$i], "\n");
        }
        $out = implode("\n\n", $blocks);
        return mb_strlen($out) > self::BUDGET * 1.5 ? '…' . mb_substr($out, -(int) (self::BUDGET * 1.5)) : $out;
    }
}
