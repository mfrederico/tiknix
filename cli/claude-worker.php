#!/usr/bin/env php
<?php
/**
 * Claude Worker - Executes Claude Code for a workbench task
 *
 * This script runs inside a tmux session spawned by ClaudeRunner.
 * It loads the task, builds a prompt, configures MCP, and runs Claude Code.
 *
 * Usage: php cli/claude-worker.php --task=123 --member=5 [--team=2]
 *
 * Environment:
 * - Runs in isolated tmux session
 * - Work directory: /tmp/tiknix-{member}-task-{id} or /tmp/tiknix-team-{team}-task-{id}
 * - Logs to task log table
 */

// Bootstrap the application
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/bootstrap.php';

use \app\Bean;
use \app\PromptBuilder;
use \RedBeanPHP\R as R;
use \Flight as Flight;

// Parse command line arguments
$options = getopt('', ['task:', 'member:', 'team::']);

$taskId = (int)($options['task'] ?? 0);
$memberId = (int)($options['member'] ?? 0);
$teamId = isset($options['team']) ? (int)$options['team'] : null;

if (!$taskId || !$memberId) {
    echo "Error: --task and --member are required\n";
    exit(1);
}

echo "=== Tiknix Claude Worker ===\n";
echo "Task ID: {$taskId}\n";
echo "Member ID: {$memberId}\n";
echo "Team ID: " . ($teamId ?? 'none') . "\n";
echo "Work Dir: " . getcwd() . "\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 40) . "\n\n";

// Helper function to log task events
function logTaskEvent(int $taskId, string $level, string $type, string $message, array $context = []): void {
    try {
        $log = Bean::dispense('tasklog');
        $log->taskId = $taskId;
        $log->memberId = null; // System log
        $log->logLevel = $level;
        $log->logType = $type;
        $log->message = $message;
        $log->contextJson = !empty($context) ? json_encode($context) : null;
        $log->createdAt = date('Y-m-d H:i:s');
        Bean::store($log);
    } catch (Exception $e) {
        error_log("Failed to log task event: " . $e->getMessage());
    }
}

// Helper function to update task status
function updateTaskStatus(int $taskId, string $status, array $data = []): void {
    try {
        $task = Bean::load('workbenchtask', $taskId);
        if ($task->id) {
            $task->status = $status;
            foreach ($data as $key => $value) {
                $task->$key = $value;
            }
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);
        }
    } catch (Exception $e) {
        error_log("Failed to update task status: " . $e->getMessage());
    }
}

// Helper function to save snapshot
function saveSnapshot(int $taskId, string $type, string $content): void {
    try {
        $snapshot = Bean::dispense('tasksnapshot');
        $snapshot->taskId = $taskId;
        $snapshot->snapshotType = $type;
        $snapshot->content = $content;
        $snapshot->createdAt = date('Y-m-d H:i:s');
        Bean::store($snapshot);
    } catch (Exception $e) {
        error_log("Failed to save snapshot: " . $e->getMessage());
    }
}

try {
    // Load the task
    $task = Bean::load('workbenchtask', $taskId);

    if (!$task->id) {
        echo "Error: Task not found\n";
        exit(1);
    }

    echo "Task: {$task->title}\n";
    echo "Type: {$task->taskType}\n";
    echo "Priority: {$task->priority}\n\n";

    // Update status to running
    updateTaskStatus($taskId, 'running');
    logTaskEvent($taskId, 'info', 'system', 'Claude worker started');

    // Build the prompt
    $prompt = PromptBuilder::build([
        'id' => $task->id,
        'title' => $task->title,
        'description' => $task->description,
        'task_type' => $task->taskType,
        'acceptance_criteria' => $task->acceptanceCriteria,
        'related_files' => json_decode($task->relatedFiles, true) ?: [],
        'tags' => json_decode($task->tags, true) ?: [],
    ]);

    echo "Prompt built (" . strlen($prompt) . " chars)\n";
    logTaskEvent($taskId, 'debug', 'system', 'Prompt built', ['length' => strlen($prompt)]);

    // Write the prompt to a file for Claude
    $promptFile = getcwd() . '/prompt.txt';
    file_put_contents($promptFile, $prompt);

    // Generate MCP configuration
    $mcpConfig = generateMcpConfig($taskId);
    $mcpConfigFile = getcwd() . '/mcp-config.json';
    file_put_contents($mcpConfigFile, json_encode($mcpConfig, JSON_PRETTY_PRINT));

    echo "MCP config written\n";

    // Build Claude command
    $claudeCmd = buildClaudeCommand($promptFile, $mcpConfigFile);

    echo "\n" . str_repeat('=', 40) . "\n";
    echo "Starting Claude Code...\n";
    echo str_repeat('=', 40) . "\n\n";

    logTaskEvent($taskId, 'info', 'system', 'Executing Claude Code');

    // Execute Claude Code
    $startTime = microtime(true);
    $output = [];
    $returnCode = 0;

    // Use passthru for real-time output
    passthru($claudeCmd, $returnCode);

    $duration = round(microtime(true) - $startTime, 2);

    echo "\n" . str_repeat('=', 40) . "\n";
    echo "Claude Code finished\n";
    echo "Duration: {$duration}s\n";
    echo "Return code: {$returnCode}\n";
    echo str_repeat('=', 40) . "\n";

    // Determine outcome
    if ($returnCode === 0) {
        // Success - check for PR URL
        $prUrl = extractPrUrl();
        $branchName = extractBranchName();

        updateTaskStatus($taskId, 'completed', [
            'completedAt' => date('Y-m-d H:i:s'),
            'prUrl' => $prUrl,
            'branchName' => $branchName,
        ]);

        logTaskEvent($taskId, 'info', 'system', 'Task completed successfully', [
            'duration' => $duration,
            'pr_url' => $prUrl,
            'branch' => $branchName
        ]);

        echo "\nTask completed successfully!\n";
        if ($prUrl) {
            echo "PR URL: {$prUrl}\n";
        }

    } else {
        // Failure
        updateTaskStatus($taskId, 'failed', [
            'errorMessage' => "Claude Code exited with code {$returnCode}",
        ]);

        logTaskEvent($taskId, 'error', 'system', 'Task failed', [
            'return_code' => $returnCode,
            'duration' => $duration
        ]);

        echo "\nTask failed with exit code {$returnCode}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";

    updateTaskStatus($taskId, 'failed', [
        'errorMessage' => $e->getMessage(),
    ]);

    logTaskEvent($taskId, 'error', 'system', 'Worker exception: ' . $e->getMessage());

    exit(1);
}

/**
 * Generate MCP configuration for Claude
 */
function generateMcpConfig(int $taskId): array {
    // Get the Tiknix base URL from config
    $baseUrl = Flight::get('baseurl') ?? 'http://localhost:8000';

    // Generate a temporary API key for this task
    // In production, you'd want to use a proper key
    $apiKey = 'worker-' . bin2hex(random_bytes(16));

    return [
        'mcpServers' => [
            'tiknix' => [
                'type' => 'http',
                'url' => $baseUrl . '/mcp/message',
                'headers' => [
                    'X-MCP-Token' => $apiKey,
                    'X-Task-ID' => (string)$taskId
                ]
            ]
        ]
    ];
}

/**
 * Build the Claude Code command
 */
function buildClaudeCommand(string $promptFile, string $mcpConfigFile): string {
    $projectRoot = dirname(__DIR__);

    // Read prompt from file
    $prompt = file_get_contents($promptFile);

    // Build command - using claude with the prompt
    // The MCP config will be read from the project's .mcp.json or user settings
    $cmd = sprintf(
        'cd %s && claude -p %s 2>&1',
        escapeshellarg($projectRoot),
        escapeshellarg($prompt)
    );

    return $cmd;
}

/**
 * Extract PR URL from git output or environment
 */
function extractPrUrl(): ?string {
    // Try to get from git
    $cmd = 'git log -1 --format="%b" 2>/dev/null | grep -oE "https://github.com/[^/]+/[^/]+/pull/[0-9]+"';
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && !empty($output[0])) {
        return $output[0];
    }

    // Try gh pr view
    $cmd = 'gh pr view --json url -q .url 2>/dev/null';
    exec($cmd, $output2, $returnCode2);

    if ($returnCode2 === 0 && !empty($output2[0])) {
        return $output2[0];
    }

    return null;
}

/**
 * Extract branch name from git
 */
function extractBranchName(): ?string {
    $cmd = 'git branch --show-current 2>/dev/null';
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && !empty($output[0])) {
        return trim($output[0]);
    }

    return null;
}
