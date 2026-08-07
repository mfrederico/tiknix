<?php
/**
 * TmuxManager - Centralized tmux session management
 *
 * Provides a clean abstraction layer for tmux operations.
 * Used by ClaudeRunner (for Claude sessions) and Workbench (for test servers).
 *
 * Session Types:
 * - Claude tasks: tiknix-{member_id}-task-{task_id} or tiknix-team-{team_id}-task-{task_id}
 * - Test servers: tiknix-{slug}-{member_id}-serve-{task_id}
 *
 * Every name carries the PROJECT SLUG, because task ids are per-instance: each
 * project's workbench.db counts from 1, so an unscoped name refers to that id in
 * every project at once.
 */

namespace app;

class TmuxManager {

    /**
     * Check if a tmux session exists
     *
     * @param string $sessionName The session name to check
     * @return bool True if session exists
     */
    public static function exists(string $sessionName): bool {
        $cmd = sprintf('tmux has-session -t %s 2>&1', escapeshellarg($sessionName));
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * List live session names in ONE `tmux ls` call, optionally filtered to those
     * starting with $prefix. Cheaper than calling exists() per candidate when you
     * need to discover which of many sessions are alive.
     *
     * @param string $prefix Only return sessions whose name starts with this.
     * @return string[] Matching session names (empty if tmux has no server/sessions).
     */
    public static function list(string $prefix = ''): array {
        exec('tmux ls -F "#{session_name}" 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) return [];   // no server / no sessions
        $names = array_filter(array_map('trim', (array)$output), fn($n) => $n !== '');
        if ($prefix !== '') {
            $names = array_filter($names, fn($n) => strncmp($n, $prefix, strlen($prefix)) === 0);
        }
        return array_values($names);
    }

    /**
     * Create a new tmux session
     *
     * @param string $sessionName The session name
     * @param string $command The command to run in the session
     * @param string|null $workDir Working directory for the session
     * @return bool Success
     * @throws \Exception On failure
     */
    public static function create(string $sessionName, string $command, ?string $workDir = null): bool {
        if (self::exists($sessionName)) {
            throw new \Exception("Session already exists: {$sessionName}");
        }

        $cmd = 'tmux new-session -d -s ' . escapeshellarg($sessionName);

        if ($workDir && is_dir($workDir)) {
            $cmd .= ' -c ' . escapeshellarg($workDir);
        }

        $cmd .= ' ' . escapeshellarg($command) . ' 2>&1';

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Failed to create tmux session: " . implode("\n", $output));
        }

        return true;
    }

    /**
     * Kill a tmux session
     *
     * @param string $sessionName The session name to kill
     * @return bool Success (true even if session didn't exist)
     */
    public static function kill(string $sessionName): bool {
        if (!self::exists($sessionName)) {
            return true; // Already dead
        }

        $cmd = sprintf('tmux kill-session -t %s 2>&1', escapeshellarg($sessionName));
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Capture the content of a tmux pane
     *
     * @param string $sessionName The session name
     * @param int $lines Number of lines to capture (from bottom)
     * @return string The captured content
     */
    public static function capture(string $sessionName, int $lines = 100): string {
        if (!self::exists($sessionName)) {
            return '';
        }

        $cmd = sprintf(
            'tmux capture-pane -t %s -p -S -%d 2>/dev/null',
            escapeshellarg($sessionName),
            $lines
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return '';
        }

        return implode("\n", $output);
    }

    /**
     * Send keys to a tmux session
     *
     * @param string $sessionName The session name
     * @param string $keys The keys to send
     * @param bool $literal Send keys literally (no escaping)
     * @return bool Success
     */
    public static function sendKeys(string $sessionName, string $keys, bool $literal = false): bool {
        if (!self::exists($sessionName)) {
            return false;
        }

        $cmd = sprintf(
            'tmux send-keys -t %s %s%s 2>&1',
            escapeshellarg($sessionName),
            $literal ? '-l ' : '',
            escapeshellarg($keys)
        );
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Send text using tmux buffer (for long/complex text)
     *
     * @param string $sessionName The session name
     * @param string $text The text to send
     * @param string $bufferName Buffer name to use
     * @return bool Success
     */
    public static function sendTextViaBuffer(string $sessionName, string $text, string $bufferName = 'tiknix-text'): bool {
        if (!self::exists($sessionName)) {
            return false;
        }

        // Write to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'tmux-');
        if ($tempFile === false) {
            return false;
        }

        file_put_contents($tempFile, $text);

        // Load into buffer
        $loadCmd = sprintf(
            'tmux load-buffer -b %s %s 2>&1',
            escapeshellarg($bufferName),
            escapeshellarg($tempFile)
        );
        exec($loadCmd, $output, $loadCode);

        unlink($tempFile);

        if ($loadCode !== 0) {
            return false;
        }

        // Paste buffer
        $pasteCmd = sprintf(
            'tmux paste-buffer -b %s -t %s 2>&1',
            escapeshellarg($bufferName),
            escapeshellarg($sessionName)
        );
        exec($pasteCmd, $output, $pasteCode);

        return $pasteCode === 0;
    }

    /**
     * Get the PID of the main process in a session's pane
     *
     * @param string $sessionName The session name
     * @return int|null The PID or null if not found
     */
    public static function getPanePid(string $sessionName): ?int {
        if (!self::exists($sessionName)) {
            return null;
        }

        $cmd = sprintf(
            'tmux list-panes -t %s -F "#{pane_pid}" 2>/dev/null | head -1',
            escapeshellarg($sessionName)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || empty($output[0])) {
            return null;
        }

        return (int) trim($output[0]);
    }

    /**
     * Check if a process is running in the session
     *
     * @param string $sessionName The session name
     * @param string $processPattern Pattern to match (for pgrep -f)
     * @return bool True if process is running
     */
    public static function isProcessRunning(string $sessionName, string $processPattern): bool {
        $panePid = self::getPanePid($sessionName);
        if ($panePid === null) {
            return false;
        }

        $cmd = sprintf('pgrep -P %d -f %s 2>/dev/null', $panePid, escapeshellarg($processPattern));
        exec($cmd, $output, $returnCode);

        return $returnCode === 0 && !empty($output);
    }

    /**
     * List all tmux sessions matching a prefix
     *
     * @param string $prefix Session name prefix (e.g., 'tiknix-')
     * @return array Array of session info [name, created, attached]
     */
    public static function listSessions(string $prefix = ''): array {
        $cmd = 'tmux list-sessions -F "#{session_name}|#{session_created}|#{session_attached}" 2>/dev/null';
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $sessions = [];
        foreach ($output as $line) {
            $parts = explode('|', $line);
            if (count($parts) >= 3) {
                $name = $parts[0];
                if (empty($prefix) || strpos($name, $prefix) === 0) {
                    $sessions[] = [
                        'name' => $name,
                        'created' => date('Y-m-d H:i:s', (int)$parts[1]),
                        'attached' => $parts[2] === '1'
                    ];
                }
            }
        }

        return $sessions;
    }

    /**
     * List all Tiknix sessions (task runners and test servers)
     *
     * @return array Array of session info
     */
    public static function listTiknixSessions(): array {
        return self::listSessions('tiknix-');
    }

    /**
     * List task runner sessions only
     *
     * @return array Array of session info
     */
    public static function listTaskSessions(): array {
        $all = self::listTiknixSessions();
        return array_filter($all, function($s) {
            // Task sessions contain '-task-' but NOT '-serve-'
            return strpos($s['name'], '-task-') !== false && strpos($s['name'], '-serve-') === false;
        });
    }

    /**
     * List test server sessions only
     *
     * @return array Array of session info
     */
    public static function listServerSessions(): array {
        $all = self::listTiknixSessions();
        return array_filter($all, function($s) {
            // '-serve-' anywhere, not a 'tiknix-serve-' prefix: the slug now leads,
            // so a prefix test would silently match nothing and every preview would
            // look like it had already stopped.
            return strpos($s['name'], '-serve-') !== false;
        });
    }

    /**
     * Kill all sessions matching a prefix
     *
     * @param string $prefix Session name prefix
     * @return int Number of sessions killed
     */
    public static function killByPrefix(string $prefix): int {
        $sessions = self::listSessions($prefix);
        $killed = 0;

        foreach ($sessions as $session) {
            if (self::kill($session['name'])) {
                $killed++;
            }
        }

        return $killed;
    }

    /**
     * Clean up orphaned test server sessions
     * (Sessions for tasks that no longer exist or are not running)
     *
     * @param callable $isValidCallback Callback that takes session name, returns true if valid
     * @return int Number of sessions cleaned up
     */
    public static function cleanupOrphaned(callable $isValidCallback): int {
        $sessions = self::listTiknixSessions();
        $cleaned = 0;

        foreach ($sessions as $session) {
            if (!$isValidCallback($session['name'])) {
                if (self::kill($session['name'])) {
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Get session info
     *
     * @param string $sessionName The session name
     * @return array|null Session info or null if not found
     */
    public static function getInfo(string $sessionName): ?array {
        if (!self::exists($sessionName)) {
            return null;
        }

        $cmd = sprintf(
            'tmux display-message -t %s -p "#{session_created}|#{session_attached}|#{pane_pid}" 2>/dev/null',
            escapeshellarg($sessionName)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || empty($output[0])) {
            return null;
        }

        $parts = explode('|', $output[0]);

        return [
            'name' => $sessionName,
            'created' => isset($parts[0]) ? date('Y-m-d H:i:s', (int)$parts[0]) : null,
            'attached' => isset($parts[1]) && $parts[1] === '1',
            'pane_pid' => isset($parts[2]) ? (int)$parts[2] : null,
            'exists' => true
        ];
    }

    /**
     * Build a test server session name
     *
     * @param int $memberId Member ID
     * @param int $taskId Task ID
     * @return string Session name
     */
    public static function buildServerSessionName(int $memberId, int $taskId, string $slug = ''): string {
        // SCOPED BY PROJECT, for the same reason buildTaskSessionName is.
        //
        // Task ids are per-instance — every project's workbench.db counts from 1 —
        // so tiknix-serve-1-16 named member 1's task 16 in EVERY project at once.
        // Four of the instances checked have a task #16. Starting a preview for one
        // project while another's task 16 was serving would find the existing
        // session and hand back somebody else's site, on somebody else's port,
        // with nothing announcing the swap.
        //
        // Ports were given a scope earlier (PortManager::getPortForTask) and task
        // sessions were given a slug; this namer was missed, so it kept the bug
        // both of those were fixed for.
        //
        // Shape matches the task namer — tiknix-{slug}-{member}-{kind}-{id} — so
        // `tmux ls` groups by project and the kind reads in the same place every
        // time. The tiknix- prefix stays: listTiknixSessions() filters on it.
        $where = self::slugPart($slug);
        return "tiknix-{$where}{$memberId}-serve-{$taskId}";
    }

    /**
     * Build a task runner session name
     *
     * @param int $memberId Member ID
     * @param int $taskId Task ID
     * @param int|null $teamId Team ID (null for personal tasks)
     * @return string Session name
     */
    public static function buildTaskSessionName(int $memberId, int $taskId, ?int $teamId = null, string $slug = ''): string {
        // tiknix-<slug>-<member>-task-<id>, e.g. tiknix-mileage-1-task-26.
        //
        // The slug is here because task ids are PER-PROJECT: "task 26" exists in
        // every project, and without the slug they all built the same name. A run
        // on mileage then blocked a run on bidsurge with "a session for this task
        // is already active" — naming a session belonging to a project the person
        // was not looking at — and one orphaned session blocked every project's
        // task 26 at once.
        //
        // It leads, rather than trailing, so `tmux ls` groups by project and a
        // human can see whose session is whose at a glance.
        //
        // The tiknix- prefix STAYS: listTiknixSessions() filters on it and
        // cleanupOrphaned() depends on that, so dropping it would stop orphans
        // being reaped — which is the failure that produced this bug's symptom.
        //
        // The member stays too: parseSessionName reports it and
        // ClaudeRunner::findByTaskId rebuilds a runner from it.
        $where = self::slugPart($slug);

        if ($teamId) {
            return "tiknix-{$where}team-{$teamId}-task-{$taskId}";
        }
        return "tiknix-{$where}{$memberId}-task-{$taskId}";
    }

    /**
     * Parse a session name to extract IDs
     *
     * @param string $sessionName The session name
     * @return array|null Parsed info [type, member_id, task_id, team_id] or null
     */
    public static function parseSessionName(string $sessionName): ?array {
        // Test server: tiknix-{slug}-{member_id}-serve-{task_id}, and the older
        // tiknix-serve-{member}-{task} for sessions started before the rename.
        if (preg_match('/^tiknix-(?:(.+)-)?(\d+)-serve-(\d+)$/', $sessionName, $mm)) {
            return [
                'type' => 'server',
                'slug' => $mm[1] ?? '',
                'member_id' => (int) $mm[2],
                'task_id' => (int) $mm[3],
            ];
        }
        if (preg_match('/^tiknix-serve-(\d+)-(\d+)$/', $sessionName, $m)) {
            return [
                'type' => 'server',
                'member_id' => (int)$m[1],
                'task_id' => (int)$m[2],
                'team_id' => null
            ];
        }

        // Team task: tiknix-[slug-]team-{team_id}-task-{task_id}
        // The slug is optional so sessions created before it was added still parse
        // — an unparseable name is a session cleanupOrphaned() cannot reap, which
        // would turn one stale run into a permanent block on that task id.
        if (preg_match('/^tiknix-(?:(?<slug>[A-Za-z0-9-]+?)-)?team-(?<team>\d+)-task-(?<task>\d+)$/', $sessionName, $m)) {
            return [
                'type' => 'task',
                'member_id' => null,
                'task_id' => (int)$m['task'],
                'team_id' => (int)$m['team'],
                'slug' => $m['slug'] ?? '',
            ];
        }

        // Personal task: tiknix-[slug-]{member_id}-task-{task_id}
        if (preg_match('/^tiknix-(?:(?<slug>[A-Za-z0-9-]*[A-Za-z][A-Za-z0-9-]*)-)?(?<member>\d+)-task-(?<task>\d+)$/', $sessionName, $m)) {
            return [
                'type' => 'task',
                'member_id' => (int)$m['member'],
                'task_id' => (int)$m['task'],
                'team_id' => null,
                'slug' => $m['slug'] ?? '',
            ];
        }

        return null;
    }

    /**
     * Orchestrator session for a plan: tiknix-{slug}-plan{id}-orchestrator.
     *
     * SCOPED BY PROJECT, for the third time in this file and for the same reason.
     * Plan ids are subtask ids, and subtask ids come from the INSTANCE's own
     * data/workbench.db — every project counts from 1. So tiknix-plan26-orchestrator
     * named plan 26 in every project at once: starting a build on one project while
     * another project's plan 26 was building found the existing session and reported
     * "this plan is already running" about a plan the person was not looking at, and
     * deleting either plan killed the other one's orchestrator.
     *
     * The slug leads so tmux ls groups by project, matching buildTaskSessionName and
     * buildServerSessionName. The tiknix- prefix stays: listTiknixSessions filters on
     * it, and reap-stale-tasks.php skips plan sessions by matching this shape.
     */
    public static function buildPlanSessionName(int $planId, string $slug = ''): string {
        return 'tiknix-' . self::slugPart($slug) . 'plan' . $planId . '-orchestrator';
    }

    /** Per-subtask build agent under a plan: tiknix-{slug}-plan{id}-task{taskId}. */
    public static function buildPlanTaskSessionName(int $planId, int $taskId, string $slug = ''): string {
        return 'tiknix-' . self::slugPart($slug) . 'plan' . $planId . '-task' . $taskId;
    }

    /** Legacy unscoped names, still recognised so pre-rename rows keep working. */
    public static function legacyPlanSessionName(int $planId): string {
        return 'tiknix-plan' . $planId . '-orchestrator';
    }

    /**
     * Is this session name owned by the plan executor (orchestrator or build agent)?
     *
     * Matches the scoped shape AND the legacy unscoped one, because agent_session is
     * PERSISTED on the task row: rows written before the rename still carry
     * tiknix-plan26-task76, and callers use this to decide that a task is plan-managed
     * rather than ClaudeRunner-managed. Failing to recognise an old name there would
     * hand a live subtask to the poller that force-fails sessions it cannot find.
     */
    public static function isPlanSession(string $sessionName): bool {
        return (bool) preg_match(
            '/^tiknix-(?:[A-Za-z0-9-]+-)?plan\d+-(?:orchestrator|task\d+)$/',
            $sessionName
        );
    }

    /** Shared slug segment for every scoped session name (empty slug yields ''). */
    private static function slugPart(string $slug): string {
        $slug = trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $slug), '-');
        return $slug !== '' ? $slug . '-' : '';
    }
}
