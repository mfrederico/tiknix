<?php
/**
 * TmuxManager - Centralized tmux session management
 *
 * Provides a clean abstraction layer for tmux operations.
 * Used by the plan runners (PlanRunner / PlanExecutor sessions). The in-core ClaudeRunner
 * and Workbench that also used it were removed 2026-09-23 (the Workbench is a sidecar).
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
     * rather than managed by the old in-core runner. Failing to recognise an old name there would
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
