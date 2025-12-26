<?php
/**
 * ClaudeRunner - Tmux-based Claude Code Execution
 *
 * Manages isolated tmux sessions for running Claude Code tasks.
 * Each task gets its own session with a unique work directory.
 *
 * Session Naming:
 * - Personal tasks: tiknix-{member_id}-task-{task_id}
 * - Team tasks: tiknix-team-{team_id}-task-{task_id}
 *
 * Based on patterns from myctobot's TmuxService.
 */

namespace app;

use \Exception as Exception;

class ClaudeRunner {

    private int $taskId;
    private int $memberId;
    private ?int $teamId;
    private string $sessionName;
    private string $workDir;

    /**
     * Create a new ClaudeRunner instance
     *
     * @param int $taskId The task ID
     * @param int $memberId The member who owns/triggered the task
     * @param int|null $teamId The team ID (null for personal tasks)
     */
    public function __construct(int $taskId, int $memberId, ?int $teamId = null) {
        $this->taskId = $taskId;
        $this->memberId = $memberId;
        $this->teamId = $teamId;

        // Session naming based on ownership
        if ($teamId) {
            $this->sessionName = "tiknix-team-{$teamId}-task-{$taskId}";
            $this->workDir = "/tmp/tiknix-team-{$teamId}-task-{$taskId}";
        } else {
            $this->sessionName = "tiknix-{$memberId}-task-{$taskId}";
            $this->workDir = "/tmp/tiknix-{$memberId}-task-{$taskId}";
        }
    }

    /**
     * Get the session name
     */
    public function getSessionName(): string {
        return $this->sessionName;
    }

    /**
     * Get the work directory
     */
    public function getWorkDir(): string {
        return $this->workDir;
    }

    /**
     * Spawn a new tmux session with Claude Code
     *
     * @return bool Success
     */
    public function spawn(): bool {
        // Create work directory
        if (!is_dir($this->workDir)) {
            if (!mkdir($this->workDir, 0755, true)) {
                throw new Exception("Failed to create work directory: {$this->workDir}");
            }
        }

        // Check if session already exists
        if ($this->exists()) {
            throw new Exception("Session already exists: {$this->sessionName}");
        }

        // Build the worker command
        $projectRoot = dirname(__DIR__);
        $workerScript = "{$projectRoot}/cli/claude-worker.php";

        // Ensure worker script exists
        if (!file_exists($workerScript)) {
            throw new Exception("Worker script not found: {$workerScript}");
        }

        $workerArgs = [
            '--task=' . escapeshellarg($this->taskId),
            '--member=' . escapeshellarg($this->memberId),
        ];

        if ($this->teamId) {
            $workerArgs[] = '--team=' . escapeshellarg($this->teamId);
        }

        $workerCmd = 'php ' . escapeshellarg($workerScript) . ' ' . implode(' ', $workerArgs);

        // Create tmux session
        $tmuxCmd = sprintf(
            'tmux new-session -d -s %s -c %s %s 2>&1',
            escapeshellarg($this->sessionName),
            escapeshellarg($this->workDir),
            escapeshellarg($workerCmd)
        );

        exec($tmuxCmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Failed to create tmux session: " . implode("\n", $output));
        }

        // Verify session was created
        usleep(100000); // 100ms delay
        if (!$this->exists()) {
            throw new Exception("Session created but not found: {$this->sessionName}");
        }

        return true;
    }

    /**
     * Kill the tmux session
     *
     * @return bool Success
     */
    public function kill(): bool {
        if (!$this->exists()) {
            return true; // Already dead
        }

        $cmd = sprintf('tmux kill-session -t %s 2>&1', escapeshellarg($this->sessionName));
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Check if the tmux session exists
     *
     * @return bool
     */
    public function exists(): bool {
        $cmd = sprintf('tmux has-session -t %s 2>&1', escapeshellarg($this->sessionName));
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Check if Claude Code is currently running in the session
     *
     * @return bool
     */
    public function isRunning(): bool {
        if (!$this->exists()) {
            return false;
        }

        // Get the pane PID
        $cmd = sprintf(
            'tmux list-panes -t %s -F "#{pane_pid}" 2>/dev/null | head -1',
            escapeshellarg($this->sessionName)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || empty($output[0])) {
            return false;
        }

        $panePid = trim($output[0]);

        // Check if claude process exists as child
        $cmd = sprintf('pgrep -P %d -f claude 2>/dev/null', (int)$panePid);
        exec($cmd, $claudeOutput, $claudeReturnCode);

        return $claudeReturnCode === 0 && !empty($claudeOutput);
    }

    /**
     * Send a message/input to the running session
     *
     * @param string $message The message to send
     * @return bool Success
     */
    public function sendMessage(string $message): bool {
        if (!$this->exists()) {
            return false;
        }

        // Escape special characters for tmux
        $escaped = str_replace(
            ["'", '"', '\\', '$', '`'],
            ["\\'", '\\"', '\\\\', '\\$', '\\`'],
            $message
        );

        $cmd = sprintf(
            'tmux send-keys -t %s %s Enter 2>&1',
            escapeshellarg($this->sessionName),
            escapeshellarg($escaped)
        );
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Capture a snapshot of the current tmux pane content
     *
     * @param int $lines Number of lines to capture
     * @return string The captured content
     */
    public function captureSnapshot(int $lines = 100): string {
        if (!$this->exists()) {
            return '';
        }

        $cmd = sprintf(
            'tmux capture-pane -t %s -p -S -%d 2>/dev/null',
            escapeshellarg($this->sessionName),
            $lines
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return '';
        }

        return implode("\n", $output);
    }

    /**
     * Get progress information from the session
     *
     * @return array Progress data
     */
    public function getProgress(): array {
        $snapshot = $this->captureSnapshot(50);

        if (empty($snapshot)) {
            return [
                'status' => 'unknown',
                'last_activity' => null,
                'current_task' => null,
                'files_changed' => [],
                'last_lines' => []
            ];
        }

        $lines = array_filter(array_map('trim', explode("\n", $snapshot)));
        $lastLines = array_slice($lines, -10);

        // Detect current activity
        $currentTask = $this->detectCurrentTask($lines);
        $filesChanged = $this->detectFilesChanged($lines);
        $status = $this->detectStatus($lines);

        return [
            'status' => $status,
            'last_activity' => date('Y-m-d H:i:s'),
            'current_task' => $currentTask,
            'files_changed' => $filesChanged,
            'last_lines' => $lastLines
        ];
    }

    /**
     * Detect current task from output lines
     */
    private function detectCurrentTask(array $lines): ?string {
        $patterns = [
            '/Creating pull request/i' => 'Creating pull request',
            '/gh pr create/i' => 'Creating pull request',
            '/git push/i' => 'Pushing changes',
            '/git commit/i' => 'Committing changes',
            '/Reading file/i' => 'Reading files',
            '/Writing to/i' => 'Writing files',
            '/Editing/i' => 'Editing files',
            '/Searching/i' => 'Searching codebase',
            '/grep/i' => 'Searching codebase',
            '/Running/i' => 'Running command',
            '/Testing/i' => 'Running tests',
            '/Waiting/i' => 'Waiting for input',
            '/clarification/i' => 'Awaiting clarification',
        ];

        // Search from bottom up (most recent first)
        foreach (array_reverse($lines) as $line) {
            foreach ($patterns as $pattern => $task) {
                if (preg_match($pattern, $line)) {
                    return $task;
                }
            }
        }

        return null;
    }

    /**
     * Detect files that have been changed
     */
    private function detectFilesChanged(array $lines): array {
        $files = [];
        $pattern = '/(editing|wrote|modified|created|Writing to|Editing)\s+[\'"]?([^\s\'"]+\.(php|js|ts|json|md|css|html|vue|py))[\'"]?/i';

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $matches)) {
                $file = $matches[2];
                if (!in_array($file, $files)) {
                    $files[] = $file;
                }
            }
        }

        return array_slice($files, -10); // Last 10 files
    }

    /**
     * Detect overall status
     */
    private function detectStatus(array $lines): string {
        $text = implode("\n", array_slice($lines, -20));

        if (preg_match('/error|failed|exception/i', $text)) {
            return 'error';
        }

        if (preg_match('/waiting|clarification|input needed/i', $text)) {
            return 'waiting';
        }

        if (preg_match('/complete|done|finished|success/i', $text)) {
            return 'completed';
        }

        return 'running';
    }

    /**
     * List all active tiknix sessions
     *
     * @return array Session info
     */
    public static function listAllSessions(): array {
        $cmd = 'tmux list-sessions -F "#{session_name}|#{session_created}|#{session_attached}" 2>/dev/null';
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $sessions = [];
        foreach ($output as $line) {
            $parts = explode('|', $line);
            if (count($parts) >= 3 && strpos($parts[0], 'tiknix-') === 0) {
                $sessions[] = [
                    'name' => $parts[0],
                    'created' => date('Y-m-d H:i:s', (int)$parts[1]),
                    'attached' => $parts[2] === '1'
                ];
            }
        }

        return $sessions;
    }

    /**
     * List sessions for a specific member
     *
     * @param int $memberId Member ID
     * @return array Sessions
     */
    public static function listMemberSessions(int $memberId): array {
        $all = self::listAllSessions();
        $prefix = "tiknix-{$memberId}-";

        return array_filter($all, function($s) use ($prefix) {
            return strpos($s['name'], $prefix) === 0;
        });
    }

    /**
     * List sessions for a specific team
     *
     * @param int $teamId Team ID
     * @return array Sessions
     */
    public static function listTeamSessions(int $teamId): array {
        $all = self::listAllSessions();
        $prefix = "tiknix-team-{$teamId}-";

        return array_filter($all, function($s) use ($prefix) {
            return strpos($s['name'], $prefix) === 0;
        });
    }

    /**
     * Find a runner by task ID
     *
     * @param int $taskId Task ID
     * @return ClaudeRunner|null
     */
    public static function findByTaskId(int $taskId): ?ClaudeRunner {
        $all = self::listAllSessions();

        foreach ($all as $session) {
            if (preg_match('/tiknix-(?:team-(\d+)-)?(\d+)-task-' . $taskId . '$/', $session['name'], $matches)) {
                $teamId = !empty($matches[1]) ? (int)$matches[1] : null;
                $memberId = (int)$matches[2];
                return new self($taskId, $memberId, $teamId);
            }
        }

        return null;
    }

    /**
     * Clean up old work directories
     *
     * @param int $maxAgeSeconds Max age in seconds (default 24 hours)
     */
    public static function cleanupWorkDirs(int $maxAgeSeconds = 86400): void {
        $pattern = '/tmp/tiknix-*';
        $dirs = glob($pattern, GLOB_ONLYDIR);

        if (!$dirs) return;

        $cutoff = time() - $maxAgeSeconds;

        foreach ($dirs as $dir) {
            $mtime = filemtime($dir);
            if ($mtime && $mtime < $cutoff) {
                // Check if there's an active session for this dir
                $sessionName = basename($dir);
                $cmd = sprintf('tmux has-session -t %s 2>&1', escapeshellarg($sessionName));
                exec($cmd, $output, $returnCode);

                if ($returnCode !== 0) {
                    // No active session, safe to remove
                    self::removeDirectory($dir);
                }
            }
        }
    }

    /**
     * Recursively remove a directory
     */
    private static function removeDirectory(string $dir): void {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
