#!/usr/bin/env php
<?php
/**
 * Task Complete Callback
 *
 * Called by the Claude worker shell script when Claude exits.
 * Updates the task status in the database.
 *
 * Usage: php cli/task-complete.php --task=123 --status=completed [--error="message"]
 */

// Bootstrap the application
$projectRoot = dirname(__DIR__);
chdir($projectRoot);
require_once $projectRoot . '/bootstrap.php';

// Initialize the application
$app = new \app\Bootstrap('conf/config.ini');

use \app\Bean;

// Parse command line arguments
$options = getopt('', ['task:', 'status:', 'error::']);

$taskId = (int)($options['task'] ?? 0);
$status = $options['status'] ?? '';
$errorMessage = $options['error'] ?? null;

if (!$taskId || !$status) {
    echo "Error: --task and --status are required\n";
    exit(1);
}

// Validate status
$validStatuses = ['completed', 'failed', 'paused'];
if (!in_array($status, $validStatuses)) {
    echo "Error: Invalid status. Must be one of: " . implode(', ', $validStatuses) . "\n";
    exit(1);
}

try {
    $task = Bean::load('workbenchtask', $taskId);

    if (!$task->id) {
        echo "Error: Task not found\n";
        exit(1);
    }

    // Update task status
    $task->status = $status;
    $task->updatedAt = date('Y-m-d H:i:s');

    if ($status === 'completed') {
        $task->completedAt = date('Y-m-d H:i:s');

        // Try to extract PR URL and branch from git
        $prUrl = extractPrUrl($projectRoot);
        $branchName = extractBranchName($projectRoot);

        if ($prUrl) {
            $task->prUrl = $prUrl;
        }
        if ($branchName) {
            $task->branchName = $branchName;
        }
    }

    if ($status === 'failed' && $errorMessage) {
        $task->errorMessage = $errorMessage;
    }

    Bean::store($task);

    // Log the completion
    $log = Bean::dispense('tasklog');
    $log->taskId = $taskId;
    $log->logLevel = $status === 'completed' ? 'info' : 'error';
    $log->logType = 'system';
    $log->message = $status === 'completed'
        ? 'Task completed successfully'
        : 'Task failed: ' . ($errorMessage ?? 'Unknown error');
    $log->createdAt = date('Y-m-d H:i:s');
    Bean::store($log);

    echo "Task {$taskId} updated to status: {$status}\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Extract PR URL from git
 */
function extractPrUrl(string $projectRoot): ?string {
    // Try gh pr view
    $cmd = "cd " . escapeshellarg($projectRoot) . " && gh pr view --json url -q .url 2>/dev/null";
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && !empty($output[0])) {
        return trim($output[0]);
    }

    return null;
}

/**
 * Extract branch name from git
 */
function extractBranchName(string $projectRoot): ?string {
    $cmd = "cd " . escapeshellarg($projectRoot) . " && git branch --show-current 2>/dev/null";
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && !empty($output[0])) {
        return trim($output[0]);
    }

    return null;
}
