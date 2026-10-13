<?php
/**
 * AskStore — what was said in a project's Ask, kept WITH THE PROJECT: one row a message in the
 * project's own board database (data/workbench.db, beside its plans and tasks), from the moment
 * it is typed. A question and its answer, a message it could not tell was one, the planner's
 * question before a plan exists, a request and the plan it became — each is an item of the same
 * list the tasks are in, so the board can show them and anything that reads the project's record
 * (an agent answering, a search, a chatbot later) reads one place.
 *
 * They used to live in the platform's prompt log (core's promptlog.ask_*): the conversation of a
 * project was not in the project. The prompt log remains what it is — the platform's journal of
 * what members typed, and the queue of requests waiting to be planned — and a message here names
 * its entry there (prompt_ref), which is how the planner's pipeline still finds it.
 *
 * PDO, not Bean, on purpose: this is read and written from the platform's own scripts (whose
 * database is core's) and from the Builder (whose selected database is a project's), and a row
 * written through whichever connection happened to be selected has gone to the wrong database
 * before. The table is declared here — every column TEXT or INTEGER from the start.
 */

namespace app;

class AskStore {
    public const STATES = ['thinking', 'answer', 'unsure', 'failed', 'asking', 'build', 'planned'];

    /** @var array<string,\PDO> */
    private static array $open = [];

    /** The project's board database ($dir is its builder folder), with the table there. */
    public static function db(string $dir): \PDO {
        $file = rtrim($dir, '/') . '/data/workbench.db';
        if (isset(self::$open[$file])) return self::$open[$file];
        if (!is_dir(dirname($file))) throw new \RuntimeException("AskStore: {$dir} has no data folder — the project has no board here");
        $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS askmessage (
            id INTEGER PRIMARY KEY AUTOINCREMENT, prompt_ref INTEGER NOT NULL UNIQUE, member_ref INTEGER NOT NULL,
            said TEXT NOT NULL, state TEXT NOT NULL, answer TEXT, note TEXT, noted_at TEXT, qa_json TEXT,
            plan_ref INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS askmessage_member ON askmessage (member_ref, id)');
        return self::$open[$file] = $pdo;
    }

    /** For tests and long-lived processes that move between copies of a database. */
    public static function forget(): void { self::$open = []; }

    private static function now(): string { return date('Y-m-d H:i:s'); }

    /** The message for a prompt-log entry, or null. */
    public static function get(string $dir, int $promptRef): ?array {
        $q = self::db($dir)->prepare('SELECT * FROM askmessage WHERE prompt_ref = ?');
        $q->execute([$promptRef]);
        return $q->fetch() ?: null;
    }

    /** Make the item for something just typed (no-op when it is there). $at: when it was said. */
    public static function ensure(string $dir, int $promptRef, int $memberRef, string $said, string $state = 'thinking', string $at = ''): void {
        if ($promptRef <= 0 || $memberRef <= 0) throw new \InvalidArgumentException('AskStore::ensure needs the prompt and the member');
        if (!in_array($state, self::STATES, true)) throw new \InvalidArgumentException("not an ask state: {$state}");
        $at = $at !== '' ? $at : self::now();
        self::db($dir)->prepare('INSERT OR IGNORE INTO askmessage (prompt_ref, member_ref, said, state, answer, note, qa_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$promptRef, $memberRef, $said, $state, '', '', '[]', $at, $at]);
    }

    /**
     * Where a message stands. 'build' (sent on to be planned) keeps the answer it had, so a
     * stopped planner can put the conversation back; every other state sets answer and note.
     */
    public static function mark(string $dir, int $promptRef, string $state, string $answer = '', string $note = ''): void {
        if (!in_array($state, self::STATES, true)) throw new \InvalidArgumentException("not an ask state: {$state}");
        $db = self::db($dir);
        if ($state === 'build' || $state === 'planned') {
            $n = $db->prepare('UPDATE askmessage SET state = ?, updated_at = ? WHERE prompt_ref = ?');
            $n->execute([$state, self::now(), $promptRef]);
        } else {
            $n = $db->prepare('UPDATE askmessage SET state = ?, answer = ?, note = ?, noted_at = NULL, updated_at = ? WHERE prompt_ref = ?');
            $n->execute([$state, $answer, $note, self::now(), $promptRef]);
        }
        if ($n->rowCount() === 0) throw new \RuntimeException("AskStore: no message for prompt #{$promptRef} in {$dir}");
    }

    /** It became this plan of the project (plan-ingest): the item and its plan name each other. */
    public static function planned(string $dir, int $promptRef, int $planId): void {
        self::db($dir)->prepare("UPDATE askmessage SET state = 'planned', plan_ref = ?, updated_at = ? WHERE prompt_ref = ?")->execute([$planId, self::now(), $promptRef]);
    }

    /** The planner's question was answered: kept with the message, question and answer together. */
    public static function addQa(string $dir, int $promptRef, string $q, string $a): void {
        $row = self::get($dir, $promptRef);
        if (!$row) throw new \RuntimeException("AskStore: no message for prompt #{$promptRef}");
        $qa = json_decode((string) $row['qa_json'], true);
        if (!is_array($qa)) $qa = [];
        $qa[] = ['q' => $q, 'a' => $a, 'at' => self::now()];
        self::db($dir)->prepare("UPDATE askmessage SET qa_json = ?, state = 'build', answer = '', updated_at = ? WHERE prompt_ref = ?")
            ->execute([json_encode($qa, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::now(), $promptRef]);
    }

    public static function noted(string $dir, int $promptRef): void {
        self::db($dir)->prepare('UPDATE askmessage SET noted_at = ?, updated_at = ? WHERE prompt_ref = ?')->execute([self::now(), self::now(), $promptRef]);
    }

    /**
     * One member's messages in this project, newest $limit, oldest first. $states: only those.
     * @return list<array<string,mixed>>
     */
    public static function of(string $dir, int $memberRef, array $states = [], int $limit = 20, int $beforePrompt = 0): array {
        if (!is_file(rtrim($dir, '/') . '/data/workbench.db')) return [];
        $sql = 'SELECT * FROM askmessage WHERE member_ref = ?'; $args = [$memberRef];
        if ($states) { $sql .= ' AND state IN (' . implode(',', array_fill(0, count($states), '?')) . ')'; $args = array_merge($args, array_values($states)); }
        if ($beforePrompt > 0) { $sql .= ' AND prompt_ref < ?'; $args[] = $beforePrompt; }
        $q = self::db($dir)->prepare($sql . ' ORDER BY prompt_ref DESC LIMIT ' . max(1, $limit));
        $q->execute($args);
        return array_reverse($q->fetchAll());
    }

    /** Everyone's, for the board and for what reads the project's record: newest $limit, oldest first. */
    public static function all(string $dir, int $limit = 30): array {
        if (!is_file(rtrim($dir, '/') . '/data/workbench.db')) return [];
        return array_reverse(self::db($dir)->query('SELECT * FROM askmessage ORDER BY prompt_ref DESC LIMIT ' . max(1, $limit))->fetchAll());
    }
}
