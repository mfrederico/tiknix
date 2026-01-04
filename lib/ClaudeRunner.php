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
    private ?string $projectPath = null;

    /**
     * Create a new ClaudeRunner instance
     *
     * @param int $taskId The task ID
     * @param int $memberId The member who owns/triggered the task
     * @param int|null $teamId The team ID (null for personal tasks)
     * @param string|null $projectPath Custom project path (workspace clone location)
     */
    public function __construct(int $taskId, int $memberId, ?int $teamId = null, ?string $projectPath = null) {
        $this->taskId = $taskId;
        $this->memberId = $memberId;
        $this->teamId = $teamId;
        $this->projectPath = $projectPath;

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
     * Get the project path (workspace or default)
     */
    public function getProjectPath(): string {
        return $this->projectPath ?? dirname(__DIR__);
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
     * Get the internal URL for hooks to call back to the MCP endpoint
     * Checks .mcp_url file (written by serve.sh) or defaults to localhost:8080
     */
    private function getHookUrl(string $projectRoot): string {
        // Check if serve.sh wrote a .mcp_url file
        $mcpUrlFile = $projectRoot . '/.mcp_url';
        if (file_exists($mcpUrlFile)) {
            $url = trim(file_get_contents($mcpUrlFile));
            if (!empty($url)) {
                return $url . '/mcp/message';
            }
        }

        // Default to localhost:8080 for production (nginx -> php-fpm)
        return 'http://localhost:8080/mcp/message';
    }

    /**
     * Spawn a new tmux session with Claude Code running interactively
     *
     * @param bool $skipPermissions Use --dangerously-skip-permissions flag
     * @return bool Success
     */
    public function spawn(bool $skipPermissions = true): bool {
        // Create work directory for task files
        if (!is_dir($this->workDir)) {
            if (!mkdir($this->workDir, 0755, true)) {
                throw new Exception("Failed to create work directory: {$this->workDir}");
            }
        }

        // Check if session already exists
        if ($this->exists()) {
            throw new Exception("Session already exists: {$this->sessionName}");
        }

        // Use custom project path (workspace) if provided, otherwise default to main project
        $projectRoot = $this->getProjectPath();

        // Build Claude command to run interactively
        $claudeCmd = 'claude --debug';
        if ($skipPermissions) {
            $claudeCmd .= ' --dangerously-skip-permissions';
        }

        // Build a wrapper script that shows invocation info then runs Claude
        $invocationScript = $this->buildInvocationScript($claudeCmd, $projectRoot);
        $scriptFile = $this->workDir . '/run-claude.sh';
        file_put_contents($scriptFile, $invocationScript);
        chmod($scriptFile, 0755);

        // Create tmux session running the wrapper script
        $tmuxCmd = sprintf(
            'tmux new-session -d -s %s -c %s %s 2>&1',
            escapeshellarg($this->sessionName),
            escapeshellarg($projectRoot),
            escapeshellarg($scriptFile)
        );

        exec($tmuxCmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Failed to create tmux session: " . implode("\n", $output));
        }

        // Wait for Claude to initialize
        usleep(500000); // 500ms delay

        // Verify session was created
        if (!$this->exists()) {
            throw new Exception("Session created but not found: {$this->sessionName}");
        }

        return true;
    }

    /**
     * Build the invocation script that displays info and runs Claude
     *
     * @param string $claudeCmd The claude command to run
     * @param string $projectRoot The project root directory
     * @return string Shell script content
     */
    private function buildInvocationScript(string $claudeCmd, string $projectRoot): string {
        $timestamp = date('Y-m-d H:i:s');
        $teamInfo = $this->teamId ? "Team ID: {$this->teamId}" : "Personal task";
        $taskId = $this->taskId;
        $callbackScript = $projectRoot . '/cli/task-complete.php';
        $sessionName = $this->sessionName;

        // Determine internal URL for hooks - check .mcp_url first, then use localhost
        $hookUrl = $this->getHookUrl($projectRoot);

        return <<<BASH
#!/bin/bash
#
# Tiknix Claude Worker Session
#

# Export task ID for hooks and child processes
export TIKNIX_TASK_ID={$this->taskId}
export TIKNIX_MEMBER_ID={$this->memberId}
export TIKNIX_SESSION_NAME="{$sessionName}"
export TIKNIX_PROJECT_ROOT="{$projectRoot}"
export TIKNIX_HOOK_URL="{$hookUrl}"

echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║                    TIKNIX CLAUDE WORKER                          ║"
echo "╚══════════════════════════════════════════════════════════════════╝"
echo ""
echo "  Session:     {$sessionName}"
echo "  Task ID:     {$this->taskId}"
echo "  Member ID:   {$this->memberId}"
echo "  {$teamInfo}"
echo "  Started:     {$timestamp}"
echo ""
echo "  Project:     {$projectRoot}"
echo "  Work Dir:    {$this->workDir}"
echo ""
echo "────────────────────────────────────────────────────────────────────"
echo "  Invoking: {$claudeCmd}"
echo "────────────────────────────────────────────────────────────────────"
echo ""

# Function to auto-accept bypass permissions dialog
auto_accept_permissions() {
    local session="{$sessionName}"
    local max_attempts=10
    local attempt=0

    while [ \$attempt -lt \$max_attempts ]; do
        sleep 0.5
        # Check if the bypass permissions dialog is showing
        local content=\$(tmux capture-pane -t "\$session" -p 2>/dev/null)
        if echo "\$content" | grep -q "Bypass Permissions mode"; then
            # Dialog is showing - send Down arrow then Enter to select "Yes, I accept"
            sleep 0.3
            tmux send-keys -t "\$session" Down 2>/dev/null
            sleep 0.1
            tmux send-keys -t "\$session" Enter 2>/dev/null
            echo "  [Auto-accepted bypass permissions dialog]"
            return 0
        fi
        # Check if Claude is already running (no dialog)
        if echo "\$content" | grep -q "Claude Code"; then
            return 0
        fi
        attempt=\$((attempt + 1))
    done
}

# Start the auto-accept watcher in background
auto_accept_permissions &
WATCHER_PID=\$!

# Change to project directory and run Claude
cd "{$projectRoot}"
{$claudeCmd}
EXIT_CODE=\$?

# Kill the watcher if still running
kill \$WATCHER_PID 2>/dev/null

echo ""
echo "────────────────────────────────────────────────────────────────────"
echo "  Claude exited with code: \$EXIT_CODE"
echo "  Updating task status..."
echo "────────────────────────────────────────────────────────────────────"

# Update task status based on exit code
if [ \$EXIT_CODE -eq 0 ]; then
    php "{$callbackScript}" --task={$taskId} --status=completed
else
    php "{$callbackScript}" --task={$taskId} --status=failed --error="Claude exited with code \$EXIT_CODE"
fi

echo ""
echo "Session complete. Press Enter to close."
read
BASH;
    }

    /**
     * Send a prompt to the running Claude session
     *
     * @param string $prompt The prompt to send
     * @return bool Success
     */
    public function sendPrompt(string $prompt): bool {
        if (!$this->exists()) {
            return false;
        }

        // Write prompt to a temp file to avoid shell escaping issues with long prompts
        $promptFile = $this->workDir . '/prompt.txt';
        file_put_contents($promptFile, $prompt);

        // Use tmux load-buffer and paste-buffer for reliable text input
        // This avoids issues with special characters and long text
        $loadCmd = sprintf(
            'tmux load-buffer -b tiknix-prompt %s 2>&1',
            escapeshellarg($promptFile)
        );
        exec($loadCmd, $output, $returnCode);

        if ($returnCode !== 0) {
            // Fallback to send-keys for shorter prompts
            return $this->sendMessage($prompt);
        }

        // Paste the buffer into the session
        $pasteCmd = sprintf(
            'tmux paste-buffer -b tiknix-prompt -t %s 2>&1',
            escapeshellarg($this->sessionName)
        );
        exec($pasteCmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        // Small delay to ensure paste completes
        usleep(100000); // 100ms

        // Send Enter to submit the prompt to Claude
        $enterCmd = sprintf(
            'tmux send-keys -t %s Enter 2>&1',
            escapeshellarg($this->sessionName)
        );
        exec($enterCmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        // Send a second Enter in case Claude needs confirmation
        usleep(50000); // 50ms
        exec($enterCmd, $output, $returnCode);

        return $returnCode === 0;
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
        // More specific patterns to avoid false positives
        // These match Claude's actual tool usage output
        $patterns = [
            '/gh pr create/i' => 'Creating pull request',
            '/git push /i' => 'Pushing changes',
            '/git commit /i' => 'Committing changes',
            '/Read\s+tool|Reading\s+\S+\.(php|js|ts|json)/i' => 'Reading files',
            '/Write\s+tool|Writing\s+to\s+\S+/i' => 'Writing files',
            '/Edit\s+tool|Editing\s+\S+\.(php|js|ts|json)/i' => 'Editing files',
            '/Grep\s+tool|Glob\s+tool/i' => 'Searching codebase',
            '/Bash\s+tool|Running\s+command/i' => 'Running command',
            '/npm test|pytest|phpunit/i' => 'Running tests',
            '/TodoWrite/i' => 'Planning tasks',
            '/Task\s+tool|Agent/i' => 'Running sub-agent',
        ];

        // Search from bottom up (most recent first), only last 20 lines
        $recentLines = array_slice($lines, -20);
        foreach (array_reverse($recentLines) as $line) {
            foreach ($patterns as $pattern => $task) {
                if (preg_match($pattern, $line)) {
                    return $task;
                }
            }
        }

        // Check for thinking indicator
        $allText = implode("\n", $recentLines);
        if (preg_match('/esc to interrupt/i', $allText)) {
            return 'Thinking...';
        }

        // If Claude is running, show generic status
        if ($this->isRunning()) {
            return 'Working...';
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
        // Check last few lines for status indicators
        $lastLines = implode("\n", array_slice($lines, -15));

        // "esc to interrupt" means Claude is actively thinking
        if (preg_match('/esc to interrupt|thinking|processing/i', $lastLines)) {
            return 'thinking';
        }

        // Check for session complete message (from our wrapper script)
        if (preg_match('/Session complete|Claude exited with code: 0/i', $lastLines)) {
            return 'completed';
        }

        // Check for actual error exit
        if (preg_match('/Claude exited with code: [1-9]|Fatal error:|PHP Parse error:/i', $lastLines)) {
            return 'error';
        }

        // Check for waiting prompts (Claude's actual prompt indicators)
        if (preg_match('/\?\s*$|>\s*$|Press Enter|waiting for (your |user )?input/i', $lastLines)) {
            return 'waiting';
        }

        // If Claude process is running, it's working
        if ($this->isRunning()) {
            return 'running';
        }

        // If session exists but Claude not running, it completed
        if ($this->exists()) {
            return 'completed';
        }

        return 'unknown';
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
