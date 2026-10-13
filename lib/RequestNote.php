<?php
/**
 * RequestNote — the line a finished request leaves in its project's notebook (the `built`
 * document): what was asked, what was built, in which files, and what the owner decided on the
 * way (the answers they gave when the planner or a task asked). And the line an UNDONE request
 * leaves: that it was built and taken out again. Every later plan and task reads the notebook,
 * so the project knows its own history — including what is no longer there.
 *
 * Written from the project's record (its board, read without selecting it), never by a model:
 * it says what the record says.
 */

namespace app;

class RequestNote {
    private static function pdo(string $dir): ?\PDO {
        $db = rtrim($dir, '/') . '/data/workbench.db';
        if (!is_file($db)) return null;
        return new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
    }

    private static function cut(string $s, int $n): string {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
    }

    /** What one plan of the project is, from its record: ['asked', 'titles', 'files', 'decided', 'follows'] or null. */
    private static function of(\PDO $pdo, int $planId): ?array {
        $cols = array_column($pdo->query('PRAGMA table_info(workbenchtask)')->fetchAll(), 'name');
        $col = fn(string $c) => in_array($c, $cols, true) ? $c : "'' AS {$c}";
        $p = $pdo->prepare('SELECT id, title, ' . $col('plan_goal') . ' FROM workbenchtask WHERE id = ? AND parent_task_id IS NULL'); $p->execute([$planId]);
        if (!($plan = $p->fetch())) return null;
        $k = $pdo->prepare("SELECT title, status, " . $col('related_files') . ', ' . $col('asked_json') . " FROM workbenchtask WHERE parent_task_id = ? ORDER BY id"); $k->execute([$planId]);
        $parts = AskTriage::splitAsked(trim(GoalBrief::split((string) $plan['plan_goal'])['goal']));
        $asked = trim($parts['said']);
        if (($cut = strpos($asked, "\n\n" . PlanExecutor::ATTACHED_HEAD)) !== false) $asked = trim(substr($asked, 0, $cut));
        $titles = []; $files = []; $decided = [];
        foreach ($parts['qa'] as $qa) $decided[] = [$qa['q'], $qa['a']];
        foreach ($k->fetchAll() as $t) {
            if (!in_array((string) $t['status'], ['merged', 'resolved', 'completed'], true)) continue;
            $titles[] = trim((string) $t['title']);
            foreach ((array) (json_decode((string) $t['related_files'], true) ?: []) as $f) $files[(string) $f] = true;
            foreach ((array) (json_decode((string) $t['asked_json'], true) ?: []) as $qa) if (trim((string) ($qa['a'] ?? '')) !== '') $decided[] = [(string) ($qa['q'] ?? ''), (string) $qa['a']];
        }
        $follows = 0;
        if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'askmessage'")->fetchColumn()) {
            $f = $pdo->prepare('SELECT follows_ref FROM askmessage WHERE plan_ref = ?'); $f->execute([$planId]);
            $follows = (int) $f->fetchColumn();
        }
        return ['asked' => $asked !== '' ? $asked : (string) $plan['title'], 'title' => (string) $plan['title'], 'titles' => $titles, 'files' => array_keys($files), 'decided' => $decided, 'follows' => $follows];
    }

    /** The line for a request that has just finished ('' when the plan is not in the record or built nothing). */
    public static function built(string $dir, int $planId): string {
        $pdo = self::pdo($dir);
        $r = $pdo ? self::of($pdo, $planId) : null;
        if (!$r || !$r['titles']) return '';
        $s = '“' . self::cut($r['asked'], 110) . '”' . ($r['follows'] > 0 ? " (a follow-up to request #{$r['follows']})" : '')
           . ' — built: ' . self::cut(implode('; ', $r['titles']), 120) . '.';
        if ($r['files']) $s .= ' In ' . implode(', ', array_slice($r['files'], 0, 4)) . (count($r['files']) > 4 ? ' and ' . (count($r['files']) - 4) . ' more' : '') . '.';
        // what the owner decided on the way is the part nobody can read out of the code
        foreach (array_slice($r['decided'], 0, 2) as [$q, $a]) $s .= ' Decided: ' . self::cut($q, 60) . ' → ' . self::cut($a, 90) . '.';
        return $s;
    }

    /**
     * The line for a request that was UNDONE — the app was put back to the restore point taken
     * before it ($checkpoint, which the plan names). The undo also takes back the line that said
     * it was built (the notebook is in the app's code), so without this the project would have
     * no memory that it was ever tried.
     */
    public static function undone(string $dir, string $checkpoint): string {
        $pdo = self::pdo($dir);
        if (!$pdo || $checkpoint === '') return '';
        $cols = array_column($pdo->query('PRAGMA table_info(workbenchtask)')->fetchAll(), 'name');
        if (!in_array('plan_checkpoint', $cols, true)) return '';
        $q = $pdo->prepare('SELECT id FROM workbenchtask WHERE plan_checkpoint = ? AND parent_task_id IS NULL ORDER BY id DESC LIMIT 1'); $q->execute([$checkpoint]);
        $r = ($id = (int) $q->fetchColumn()) > 0 ? self::of($pdo, $id) : null;
        if (!$r) return '';
        return 'UNDONE — “' . self::cut($r['asked'], 110) . '” was built (' . self::cut(implode('; ', $r['titles']) ?: $r['title'], 110)
             . ') and then taken out again by its owner: none of it is in the app now. Do not build on it; if it is asked for again, build it afresh.';
    }
}
