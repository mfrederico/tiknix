<?php
/**
 * PromptLog — a durable record of every prompt a member writes.
 *
 * The prompts ARE the build history. A plan's subtasks are the planner's answer; the
 * terminal's diffs are the agent's answer. Without the questions, there is no account of
 * how any of this was built — and until now the questions were the one thing nothing kept:
 *
 *   - the decompose goal went to .aibuilder/plan-goal.md, which the NEXT decompose
 *     overwrites, and was only copied onto a plan if the planner survived to be ingested.
 *     A planner that died took the ask with it.
 *   - a task description lived only on that task, so it vanished from view the moment you
 *     started the next one.
 *   - terminal prompts were never captured anywhere tiknix could read.
 *
 * So this records at SUBMIT time, not at success time: what you asked for is worth keeping
 * whether or not the machine managed to do it. Arguably it is worth MORE when it failed.
 *
 * PRIVACY: people type secrets into prompts — "change the admin password to X" is a real
 * entry from this instance's own history. Every read here is therefore scoped to one
 * member and there is deliberately NO cross-member view, not even for admins. An admin who
 * needs someone's prompt can ask them for it.
 *
 * Storage is CORE's db even when the caller is a sidecar, because a member's prompt log
 * spans every project they work on, while an instance's workbench.db only knows its own.
 */

namespace app;

use RedBeanPHP\R;

class PromptLog {

    /** Where a prompt came from. Kept small and honest — each is a real, distinct surface. */
    public const SOURCE_DECOMPOSE = 'decompose';   // the goal you typed, then hit "Decompose into plan"
    public const SOURCE_TASK      = 'task';        // the description you typed, then hit "Create Task"
    public const SOURCE_TERMINAL  = 'terminal';    // what you typed into the Builder's Terminal tab

    /** Why the last write failed, if it did. Read by callers so a failure is shown, not eaten. */
    private static string $lastError = '';

    public static function lastError(): string { return self::$lastError; }

    public static function sources(): array {
        return [
            self::SOURCE_DECOMPOSE => 'Decomposed into a plan',
            self::SOURCE_TASK      => 'Created as a task',
            self::SOURCE_TERMINAL  => 'Typed in the Terminal',
        ];
    }

    /** Core's registry db — the one place a member's whole prompt history can live. */
    private static function coreDb(): string {
        return dirname(__DIR__) . '/database/tiknix.db';
    }

    /**
     * Record a prompt. Safe to call from core OR from a sidecar: it opens core's db as a
     * named connection and restores whatever database the caller was on, so it can never
     * leave a sidecar's RedBean pointed at core — the mistake that put plans in the wrong
     * database twice.
     *
     * Never throws. A prompt log that can break the action it is logging is worse than no
     * prompt log, so failures are reported to the app log and swallowed HERE and nowhere
     * else. Returns the new id, or 0 if it could not be written.
     */
    public static function record(array $p): int {
        $body = trim((string) ($p['body'] ?? ''));
        $memberId = (int) ($p['member_id'] ?? 0);
        if ($body === '' || $memberId <= 0) return 0;

        $source = (string) ($p['source'] ?? self::SOURCE_TASK);
        if (!isset(self::sources()[$source])) $source = self::SOURCE_TASK;

        $restore = null;
        try {
            $core = self::coreDb();
            if (!is_file($core)) { self::warn('no core db at ' . $core); return 0; }

            $restore = R::getDatabaseAdapter() ? 'default' : null;
            if (!R::hasDatabase('promptlog')) R::addDatabase('promptlog', 'sqlite:' . $core);
            R::selectDatabase('promptlog');
            R::freeze(false);

            $row = R::dispense('promptlog');
            $row->memberId    = $memberId;
            $row->source      = $source;
            $row->title       = mb_substr(trim((string) ($p['title'] ?? '')), 0, 200);
            $row->body        = $body;
            $row->instanceTag = mb_substr(trim((string) ($p['instance_tag'] ?? '')), 0, 120);
            // NULL, never 0. RedBean's fluid mode reads a `*_id` column as a foreign key and
            // writes a real constraint against `instance(id)` — so a literal 0 is not "no
            // instance", it is a reference to a row that cannot exist, and the insert fails.
            $iid = (int) ($p['instance_id'] ?? 0);
            $row->instanceId  = $iid > 0 ? $iid : null;
            // What the prompt BECAME, when we know. Deliberately NOT named *_id: these are
            // primary keys in the INSTANCE's own workbench.db, not in core, so letting
            // RedBean infer a foreign key here would point it at a `plan` table that does
            // not exist in this database and reject every write. A cross-database id is a
            // reference we resolve ourselves, never a constraint the engine can enforce.
            $row->planRef     = (int) ($p['plan_id'] ?? 0) ?: null;
            $row->taskRef     = (int) ($p['task_id'] ?? 0) ?: null;
            // Dedup key for harvested prompts (terminal), empty for ones we were handed.
            $row->extKey      = mb_substr(trim((string) ($p['ext_key'] ?? '')), 0, 120);
            $row->createdAt   = (string) ($p['created_at'] ?? date('Y-m-d H:i:s'));
            $id = (int) R::store($row);

            return $id;
        } catch (\Throwable $e) {
            // Remember it as well as logging it. A silent 0 here is how this page came to
            // say "No prompts recorded yet" while ten writes in a row were failing — the
            // emptiest possible screen and the most misleading. Callers surface it.
            self::$lastError = $e->getMessage();
            self::warn('could not record a prompt: ' . $e->getMessage());
            return 0;
        } finally {
            if ($restore) { try { R::selectDatabase($restore); } catch (\Throwable $e) {} }
        }
    }

    /** Attach the plan a decompose produced, so the log links to what it became. */
    public static function linkPlan(int $promptId, int $planId): void {
        if ($promptId <= 0 || $planId <= 0) return;
        $restore = null;
        try {
            $restore = R::getDatabaseAdapter() ? 'default' : null;
            if (!R::hasDatabase('promptlog')) R::addDatabase('promptlog', 'sqlite:' . self::coreDb());
            R::selectDatabase('promptlog');
            $row = R::load('promptlog', $promptId);
            if ($row->id) { $row->planRef = $planId; R::store($row); }
        } catch (\Throwable $e) {
            self::warn('could not link prompt ' . $promptId . ' to plan ' . $planId . ': ' . $e->getMessage());
        } finally {
            if ($restore) { try { R::selectDatabase($restore); } catch (\Throwable $e) {} }
        }
    }

    /**
     * One member's prompts, newest first. $source filters to a single surface.
     *
     * Reads on core's default connection — this is only ever called from core, where the
     * ORM is already pointed at the registry.
     */
    public static function forMember(int $memberId, string $source = '', int $limit = 200): array {
        if ($memberId <= 0) return [];
        try {
            $sql = 'member_id = ?';
            $args = [$memberId];
            if ($source !== '' && isset(self::sources()[$source])) { $sql .= ' AND source = ?'; $args[] = $source; }
            $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit));
            return array_values(R::find('promptlog', $sql, $args));
        } catch (\Throwable $e) {
            // A missing table means nothing has been logged yet — that is an empty list,
            // not an error worth shouting about. Anything else is.
            if (!preg_match('/no such table/i', $e->getMessage())) self::warn('read failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Per-source counts for the filter chips. */
    public static function countsForMember(int $memberId): array {
        $out = ['' => 0] + array_fill_keys(array_keys(self::sources()), 0);
        foreach (self::forMember($memberId, '', 1000) as $row) {
            $s = (string) $row->source;
            if (isset($out[$s])) $out[$s]++;
            $out['']++;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Terminal harvest
    // -----------------------------------------------------------------------

    /**
     * Import what a member typed into the Terminal.
     *
     * We do NOT capture keystrokes at the PTY. The agent already writes a structured
     * transcript of every turn to its own config dir (the one app\AgentState binds), so the
     * prompts are already recorded, in order, with timestamps — reading those is both
     * exact and free, where tapping the terminal stream would give us arrow keys and
     * backspaces to reassemble.
     *
     * Two filters matter and both are about honesty:
     *   - only sessions rooted at an INSTANCE dir, never at .aibuilder/wt/task-NN. Those
     *     are build agents, and their "prompts" are scaffolding tiknix wrote itself. Listing
     *     them as things you typed would bury your handful of real prompts under hundreds
     *     of machine ones.
     *   - skip the machine turns inside an interactive session too (the planner and audit
     *     runs share this directory), matched on the exact scaffolding they open with.
     *
     * Idempotent: each turn carries a uuid, stored as extKey, so re-harvesting adds nothing.
     *
     * Returns ['added' => n, 'failed' => n, 'error' => string]. It reports failures rather
     * than just counting successes, because "imported 0" and "could not import 10" look
     * identical from the outside and mean opposite things.
     */
    public static function harvestTerminal(int $memberId, string $engine = 'claude'): array {
        $res = ['added' => 0, 'failed' => 0, 'error' => ''];
        if ($memberId <= 0) return $res;
        $base = AgentState::memberDir($memberId, $engine) . '/projects';
        if (!is_dir($base)) return $res;   // nobody has opened a Terminal yet

        $known = self::knownExtKeys($memberId);

        foreach ((glob($base . '/*', GLOB_ONLYDIR) ?: []) as $projDir) {
            $name = basename($projDir);
            // Build-agent worktrees are not somewhere a person types.
            if (strpos($name, '-aibuilder-wt-task-') !== false) continue;
            $tag = self::tagFromProjectDir($name);

            foreach ((glob($projDir . '/*.jsonl') ?: []) as $file) {
                foreach (self::userTurns($file) as $turn) {
                    if ($turn['uuid'] !== '' && isset($known[$turn['uuid']])) continue;
                    $id = self::record([
                        'member_id'    => $memberId,
                        'source'       => self::SOURCE_TERMINAL,
                        'title'        => self::firstLine($turn['text']),
                        'body'         => $turn['text'],
                        'instance_tag' => $tag,
                        'ext_key'      => $turn['uuid'],
                        'created_at'   => $turn['at'],
                    ]);
                    if ($id) {
                        $res['added']++;
                        $known[$turn['uuid']] = true;
                    } else {
                        $res['failed']++;
                        if ($res['error'] === '') $res['error'] = self::$lastError;
                    }
                }
            }
        }
        return $res;
    }

    /** uuids already imported, so a re-harvest is a no-op rather than a duplicate. */
    private static function knownExtKeys(int $memberId): array {
        $out = [];
        try {
            foreach (R::find('promptlog', 'member_id = ? AND source = ? AND ext_key != ""',
                             [$memberId, self::SOURCE_TERMINAL]) as $row) {
                $out[(string) $row->extKey] = true;
            }
        } catch (\Throwable $e) { /* nothing imported yet */ }
        return $out;
    }

    /**
     * The human turns in one transcript: [{uuid, text, at}].
     *
     * Everything skipped here is skipped because it is not something a person typed —
     * tool results, the CLI's own injected context, and the scaffolding prompts our own
     * planner/audit runners send into this same directory.
     */
    private static function userTurns(string $file): array {
        $out = [];
        $fh = @fopen($file, 'r');
        if (!$fh) return $out;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') continue;
            $o = json_decode($line, true);
            if (!is_array($o)) continue;
            $msg = $o['message'] ?? null;
            if (!is_array($msg) || ($msg['role'] ?? '') !== 'user') continue;

            $text = '';
            $c = $msg['content'] ?? null;
            if (is_string($c)) {
                $text = $c;
            } elseif (is_array($c)) {
                foreach ($c as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'text') { $text = (string) $part['text']; break; }
                    // A tool_result is the machine answering itself, never a prompt.
                    if (is_array($part) && ($part['type'] ?? '') === 'tool_result') { $text = ''; break; }
                }
            }
            $text = trim($text);
            if ($text === '' || !self::isHumanPrompt($text)) continue;

            $out[] = [
                'uuid' => (string) ($o['uuid'] ?? ''),
                'text' => $text,
                'at'   => self::normalizeTs((string) ($o['timestamp'] ?? '')),
            ];
        }
        fclose($fh);
        return $out;
    }

    /**
     * Is this a person's prompt, or tiknix talking to its own agent?
     *
     * The scaffolding is ours and its exact wording lives in PlanRunner / AuditRunner, so
     * these are matched literally rather than guessed at. Command-line meta-turns the CLI
     * injects (<command-name>, system reminders, caveats) are not typing either.
     */
    private static function isHumanPrompt(string $text): bool {
        if ($text[0] === '<') return false;                         // injected CLI/system block
        if (stripos($text, 'Read the file .aibuilder/') === 0) return false;   // planner + audit runners
        if (stripos($text, 'Read .aibuilder/task.md') === 0) return false;     // build agents
        if (stripos($text, 'Caveat:') === 0) return false;
        return true;
    }

    /** "-var-www-html-default-discotuba-353308-tiknix" -> "discotuba-353308.tiknix". */
    private static function tagFromProjectDir(string $name): string {
        $prefix = '-var-www-html-default-';
        if (strpos($name, $prefix) !== 0) return '';
        $rest = substr($name, strlen($prefix));
        // The dot in "<slug>.tiknix" was flattened to a hyphen by the CLI's path encoding;
        // the app suffix is the last segment, so restore just that one separator.
        $pos = strrpos($rest, '-');
        return $pos === false ? $rest : substr($rest, 0, $pos) . '.' . substr($rest, $pos + 1);
    }

    private static function normalizeTs(string $iso): string {
        if ($iso === '') return date('Y-m-d H:i:s');
        $t = strtotime($iso);
        return $t ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s');
    }

    private static function firstLine(string $s): string {
        $s = trim(strtok(str_replace(["\r\n", "\r"], "\n", $s), "\n") ?: '');
        return mb_substr($s, 0, 200);
    }

    private static function warn(string $msg): void {
        try {
            $log = \Flight::get('log');
            if ($log) { $log->warning('PromptLog: ' . $msg); return; }
        } catch (\Throwable $e) { /* fall through */ }
        error_log('[PromptLog] ' . $msg);
    }
}
