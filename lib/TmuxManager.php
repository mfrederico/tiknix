<?php
/**
 * TmuxManager - Centralized tmux session management
 *
 * Provides a clean abstraction layer for tmux operations.
 * Used by ClaudeRunner (for Claude sessions) and Workbench (for test servers).
 *
 * Session Types:
 * - Claude tasks: tiknix-{member_id}-task-{task_id} or tiknix-team-{team_id}-task-{task_id}
 * - Test servers: tiknix-serve-{member_id}-{task_id}
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
            return strpos($s['name'], 'tiknix-serve-') === 0;
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
    public static function buildServerSessionName(int $memberId, int $taskId): string {
        return "tiknix-serve-{$memberId}-{$taskId}";
    }

    /**
     * Build a task runner session name
     *
     * @param int $memberId Member ID
     * @param int $taskId Task ID
     * @param int|null $teamId Team ID (null for personal tasks)
     * @return string Session name
     */
    public static function buildTaskSessionName(int $memberId, int $taskId, ?int $teamId = null): string {
        if ($teamId) {
            return "tiknix-team-{$teamId}-task-{$taskId}";
        }
        return "tiknix-{$memberId}-task-{$taskId}";
    }

    /**
     * Parse a session name to extract IDs
     *
     * @param string $sessionName The session name
     * @return array|null Parsed info [type, member_id, task_id, team_id] or null
     */
    public static function parseSessionName(string $sessionName): ?array {
        // Test server: tiknix-serve-{member_id}-{task_id}
        if (preg_match('/^tiknix-serve-(\d+)-(\d+)$/', $sessionName, $m)) {
            return [
                'type' => 'server',
                'member_id' => (int)$m[1],
                'task_id' => (int)$m[2],
                'team_id' => null
            ];
        }

        // Team task: tiknix-team-{team_id}-task-{task_id}
        if (preg_match('/^tiknix-team-(\d+)-task-(\d+)$/', $sessionName, $m)) {
            return [
                'type' => 'task',
                'member_id' => null,
                'task_id' => (int)$m[2],
                'team_id' => (int)$m[1]
            ];
        }

        // Personal task: tiknix-{member_id}-task-{task_id}
        if (preg_match('/^tiknix-(\d+)-task-(\d+)$/', $sessionName, $m)) {
            return [
                'type' => 'task',
                'member_id' => (int)$m[1],
                'task_id' => (int)$m[2],
                'team_id' => null
            ];
        }

        return null;
    }
}
