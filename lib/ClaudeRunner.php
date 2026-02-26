<?php
/**
 * ClaudeRunner - Tmux-based Claude Code Execution (aoe-php backed)
 *
 * Manages isolated tmux sessions for running Claude Code tasks.
 * Each task gets its own session with a unique work directory.
 *
 * Session Naming:
 * - Personal tasks: tiknix-{member_id}-task-{task_id}
 * - Team tasks: tiknix-team-{team_id}-task-{task_id}
 *
 * Internally uses aoe-php's TmuxService for tmux operations and
 * StatusDetector for intelligent status detection. The old TmuxManager
 * is no longer used (deprecated).
 */

namespace app;

use Aoe\Tmux\TmuxService;
use Aoe\Tmux\StatusDetector;
use Aoe\Session\Status as AoeStatus;
use \Exception as Exception;

class ClaudeRunner {

    private int $taskId;
    private int $memberId;
    private ?int $teamId;
    private int $memberLevel;
    private string $sessionName;
    private string $workDir;
    private ?string $projectPath = null;
    private TmuxService $tmux;
    private StatusDetector $statusDetector;

    /**
     * Create a new ClaudeRunner instance
     *
     * @param int $taskId The task ID
     * @param int $memberId The member who owns/triggered the task
     * @param int|null $teamId The team ID (null for personal tasks)
     * @param string|null $projectPath Custom project path (workspace clone location)
     * @param int $memberLevel The member's permission level (default 100 = MEMBER)
     */
    public function __construct(int $taskId, int $memberId, ?int $teamId = null, ?string $projectPath = null, int $memberLevel = 100) {
        $this->taskId = $taskId;
        $this->memberId = $memberId;
        $this->teamId = $teamId;
        $this->memberLevel = $memberLevel;
        $this->projectPath = $projectPath;

        // Build session name (preserves existing naming convention)
        $this->sessionName = self::buildTaskSessionName($memberId, $taskId, $teamId);

        // Work directory based on ownership
        if ($teamId) {
            $this->workDir = "/tmp/tiknix-team-{$teamId}-task-{$taskId}";
        } else {
            $this->workDir = "/tmp/tiknix-{$memberId}-task-{$taskId}";
        }

        // Initialize aoe-php services
        // Use 'tiknix' as workspace ID - TmuxService uses it only for prefix-based listing
        // We use custom session names via createSessionWithName() so the prefix doesn't affect naming
        $this->tmux = new TmuxService('tiknix', 'tiknix');
        $this->statusDetector = new StatusDetector();

        // Add Tiknix-specific patterns for richer status detection
        $this->statusDetector->addRunningPattern('/esc to interrupt/i');
        $this->statusDetector->addRunningPattern('/In progress.*tool uses/i');
        $this->statusDetector->addWaitingPattern('/↵ send/');
        $this->statusDetector->addWaitingPattern('/waiting for (your |user )?input/i');
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
        $workspaceRoot = $this->getProjectPath();

        // Build Claude command to run interactively
        $claudeCmd = 'claude --debug';
        if ($skipPermissions) {
            $claudeCmd .= ' --dangerously-skip-permissions';
        }

        // Build a wrapper script that shows invocation info then runs Claude
        $invocationScript = $this->buildInvocationScript($claudeCmd, $workspaceRoot);
        $scriptFile = $this->workDir . '/run-claude.sh';
        file_put_contents($scriptFile, $invocationScript);
        chmod($scriptFile, 0755);

        // Use aoe-php TmuxService to create the session with custom name
        $success = $this->tmux->createSessionWithName($this->sessionName, $workspaceRoot, $scriptFile);

        if (!$success) {
            throw new Exception("Failed to create tmux session: {$this->sessionName}");
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
     * @param string $workspaceRoot The workspace root directory (may differ from main project for isolated tasks)
     * @return string Shell script content
     */
    private function buildInvocationScript(string $claudeCmd, string $workspaceRoot): string {
        // TIKNIX_PROJECT_ROOT always points to main project (for vendor, hooks, DB)
        // The workspace may be different for isolated tasks
        $mainProjectRoot = dirname(__DIR__);
        $timestamp = date('Y-m-d H:i:s');
        $teamInfo = $this->teamId ? "Team ID: {$this->teamId}" : "Personal task";
        $taskId = $this->taskId;
        $callbackScript = $mainProjectRoot . '/cli/task-complete.php';
        $sessionName = $this->sessionName;

        // Determine internal URL for hooks - check .mcp_url first, then use localhost
        $hookUrl = $this->getHookUrl($mainProjectRoot);

        return <<<BASH
#!/bin/bash
#
# Tiknix Claude Worker Session
#

# Export task ID for hooks and child processes
export TIKNIX_TASK_ID={$this->taskId}
export TIKNIX_MEMBER_ID={$this->memberId}
export TIKNIX_MEMBER_LEVEL={$this->memberLevel}
export TIKNIX_SESSION_NAME="{$sessionName}"
export TIKNIX_PROJECT_ROOT="{$mainProjectRoot}"
export TIKNIX_WORKSPACE="{$workspaceRoot}"
export TIKNIX_HOOK_URL="{$hookUrl}"

# Allow larger Claude outputs (default is 32000, set to ~250k tokens = ~1MB text)
export CLAUDE_CODE_MAX_OUTPUT_TOKENS=250000

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
echo "  Project:     {$mainProjectRoot}"
echo "  Workspace:   {$workspaceRoot}"
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

# Change to workspace directory and run Claude
cd "{$workspaceRoot}"
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

        // Use aoe-php's paste method for reliable long text delivery
        if (!$this->tmux->pasteTextByName($this->sessionName, $prompt)) {
            // Fallback to send-keys for shorter prompts
            return $this->sendMessage($prompt);
        }

        // Wait for Claude Code's TUI to process the pasted text.
        // The terminal needs time to render each line in the input buffer.
        // Use 25ms per line (proven reliable in myctobot).
        $lineCount = substr_count($prompt, "\n") + 1;
        $delayMs = max($lineCount * 25, 500); // 25ms/line, minimum 500ms
        usleep($delayMs * 1000);

        // Send Enter to submit the prompt to Claude
        $this->tmux->sendEnterByName($this->sessionName);

        return true;
    }

    /**
     * Kill the tmux session
     *
     * @return bool Success
     */
    public function kill(): bool {
        return $this->tmux->killSessionByName($this->sessionName);
    }

    /**
     * Check if the tmux session exists
     *
     * @return bool
     */
    public function exists(): bool {
        return $this->tmux->sessionExistsByName($this->sessionName);
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

        // Use status detection - Running or Starting means Claude is active
        $status = $this->refreshAoeStatus();
        return $status === AoeStatus::Running || $status === AoeStatus::Starting;
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

        // Send literal text then Enter using aoe-php
        if (!$this->tmux->sendTextByName($this->sessionName, $message)) {
            return false;
        }

        return $this->tmux->sendEnterByName($this->sessionName);
    }

    /**
     * Capture a snapshot of the current tmux pane content
     *
     * @param int $lines Number of lines to capture
     * @return string The captured content
     */
    public function captureSnapshot(int $lines = 100): string {
        return $this->tmux->capturePaneByName($this->sessionName, $lines);
    }

    /**
     * Refresh status using aoe-php StatusDetector
     *
     * @return AoeStatus The detected aoe-php status
     */
    private function refreshAoeStatus(): AoeStatus {
        if (!$this->exists()) {
            return AoeStatus::Stopped;
        }

        $content = $this->tmux->capturePaneByName($this->sessionName, 20);
        return $this->statusDetector->detect($content);
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
     * Detect overall status using aoe-php StatusDetector with Tiknix-specific enrichments
     *
     * Returns string status codes for backward compatibility with the Workbench controller.
     * Internally uses StatusDetector for the heavy lifting.
     */
    public function detectStatus(array $lines = []): string {
        // If no lines provided, capture from tmux
        if (empty($lines)) {
            $content = $this->captureSnapshot(50);
            $lines = explode("\n", $content);
        }

        $recentLines = array_slice($lines, -15);
        $lastLines = implode("\n", $recentLines);

        // Check for Tiknix-specific completion/error indicators first
        // (These come from the invocation script, not Claude itself)
        if (preg_match('/Session complete|Claude exited with code: 0/i', $lastLines)) {
            return 'completed';
        }

        if (preg_match('/Claude exited with code: [1-9]|Fatal error:|PHP Parse error:|API Error:/i', $lastLines)) {
            return 'error';
        }

        // Use aoe-php StatusDetector for Claude-specific status detection
        $detectedResult = $this->statusDetector->detectWithDetails($lastLines, 0);
        $aoeStatus = $detectedResult['status'];

        // Map aoe-php status to Tiknix status strings
        // Also check for Claude's richer status indicators
        if ($aoeStatus === AoeStatus::Running) {
            // Try to extract more specific status from Claude's status line
            foreach ($recentLines as $line) {
                if (preg_match('/^.\s+(.+?)\s+\(esc to interrupt\s*·\s*(.+)\)/u', $line, $matches)) {
                    $statusText = trim($matches[1], '…. ');
                    $statusMap = [
                        'determining' => 'determining',
                        'thinking' => 'thinking',
                        'processing' => 'processing',
                        'analyzing' => 'analyzing',
                        'exploring' => 'exploring',
                        'searching' => 'searching',
                        'reading' => 'reading',
                        'writing' => 'writing',
                    ];
                    $lower = strtolower($statusText);
                    foreach ($statusMap as $key => $status) {
                        if (str_starts_with($lower, $key)) {
                            return $status;
                        }
                    }
                    return preg_match('/^(\w+)/', $lower, $m) ? $m[1] : 'working';
                }
            }

            // Check for "In progress" tool execution
            if (preg_match('/In progress.*tool uses/i', $lastLines)) {
                return 'executing';
            }

            return 'working';
        }

        if ($aoeStatus === AoeStatus::Waiting) {
            return 'waiting';
        }

        if ($aoeStatus === AoeStatus::Error) {
            return 'error';
        }

        if ($aoeStatus === AoeStatus::Idle) {
            // Distinguish between idle-at-prompt and session-completed
            if ($this->exists()) {
                // Check if Claude process is still alive via pgrep
                $panePid = $this->getPanePid();
                if ($panePid !== null) {
                    $cmd = sprintf('pgrep -P %d -f %s 2>/dev/null', $panePid, escapeshellarg('claude'));
                    exec($cmd, $output, $returnCode);
                    if ($returnCode === 0 && !empty($output)) {
                        return 'running';
                    }
                }
                return 'completed';
            }
            return 'unknown';
        }

        if ($aoeStatus === AoeStatus::Stopped) {
            return $this->exists() ? 'completed' : 'unknown';
        }

        return 'unknown';
    }

    /**
     * Get the PID of the main process in the session's pane
     */
    private function getPanePid(): ?int {
        $cmd = sprintf(
            'tmux list-panes -t %s -F "#{pane_pid}" 2>/dev/null | head -1',
            escapeshellarg($this->sessionName)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || empty($output[0])) {
            return null;
        }

        return (int) trim($output[0]);
    }

    /**
     * Check if the session is hung (error + waiting at prompt)
     *
     * @return bool True if session appears hung
     */
    public function isHung(): bool {
        if (!$this->exists()) {
            return false;
        }

        $progress = $this->getProgress();

        // Session is hung if it's in error state or waiting after an error
        if ($progress['status'] === 'error') {
            return true;
        }

        // Check if waiting at prompt after an API error
        if ($progress['status'] === 'waiting') {
            $snapshot = $this->captureSnapshot(100);
            if (preg_match('/API Error:/i', $snapshot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract error message from session output if present
     *
     * @return string|null Error message or null
     */
    public function getErrorMessage(): ?string {
        if (!$this->exists()) {
            return null;
        }

        $snapshot = $this->captureSnapshot(100);

        // Look for API Error
        if (preg_match('/API Error:\s*(.+?)(?:\.\s*To configure|$)/i', $snapshot, $matches)) {
            return 'API Error: ' . trim($matches[1]);
        }

        // Look for Claude exit code
        if (preg_match('/Claude exited with code:\s*(\d+)/i', $snapshot, $matches)) {
            return 'Claude exited with code: ' . $matches[1];
        }

        // Look for PHP errors
        if (preg_match('/(Fatal error:|PHP Parse error:)\s*(.+)/i', $snapshot, $matches)) {
            return $matches[1] . ' ' . trim($matches[2]);
        }

        return null;
    }

    /**
     * Check and update task status if session is hung or errored
     *
     * @return array Status info with 'is_hung', 'error_message', 'updated'
     */
    public function checkHealth(): array {
        $result = [
            'is_hung' => false,
            'error_message' => null,
            'status' => 'running',
            'updated' => false
        ];

        if (!$this->exists()) {
            $result['status'] = 'session_not_found';
            return $result;
        }

        $progress = $this->getProgress();
        $result['status'] = $progress['status'];

        if ($this->isHung()) {
            $result['is_hung'] = true;
            $result['error_message'] = $this->getErrorMessage();
        }

        return $result;
    }

    // =========================================================================
    // Static methods (preserve backward compatibility)
    // =========================================================================

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

    /**
     * List all active tiknix task sessions
     *
     * @return array Session info
     */
    public static function listAllSessions(): array {
        // Use direct tmux listing for full backward compatibility
        // (includes both old and new naming conventions)
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
                // Match tiknix task sessions (both old and new naming)
                if (strpos($name, 'tiknix-') === 0 && strpos($name, '-task-') !== false && strpos($name, '-serve-') === false) {
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
            $parsed = self::parseSessionName($session['name']);
            if ($parsed && $parsed['task_id'] === $taskId && $parsed['type'] === 'task') {
                $memberId = $parsed['member_id'] ?? 0;
                $teamId = $parsed['team_id'];
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
        $tmux = new TmuxService('tiknix', 'tiknix');

        foreach ($dirs as $dir) {
            $mtime = filemtime($dir);
            if ($mtime && $mtime < $cutoff) {
                // Check if there's an active session for this dir
                $sessionName = basename($dir);
                if (!$tmux->sessionExistsByName($sessionName)) {
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
