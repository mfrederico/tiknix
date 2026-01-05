#!/usr/bin/env php
<?php
/**
 * Task Health Check CLI
 *
 * Checks all running tasks for hung sessions and updates their status.
 * Run via cron every 5 minutes to detect and handle hung tasks
 *
 * Options:
 *   --dry-run     Show what would be updated without making changes
 *   --verbose     Show detailed output
 *   --task=ID     Check only a specific task
 */

// Load bootstrap
require_once __DIR__ . '/../bootstrap.php';

use app\ClaudeRunner;
use \RedBeanPHP\R as R;

// Initialize application (loads database, etc.)
$bootstrap = new \app\Bootstrap('conf/config.ini');

$options = getopt('', ['dry-run', 'verbose', 'task:']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$specificTask = $options['task'] ?? null;

if ($verbose) {
    echo "Task Health Check\n";
    echo str_repeat('=', 60) . "\n";
    echo "Mode: " . ($dryRun ? "DRY RUN" : "LIVE") . "\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
}

// Find all tasks with status 'running'
if ($specificTask) {
    $tasks = [R::load('workbenchtask', (int)$specificTask)];
    if (!$tasks[0]->id) {
        echo "Task not found: $specificTask\n";
        exit(1);
    }
} else {
    $tasks = R::find('workbenchtask', 'status = ?', ['running']);
}

if (empty($tasks)) {
    if ($verbose) {
        echo "No running tasks found.\n";
    }
    exit(0);
}

$updated = 0;
$healthy = 0;
$errors = [];

foreach ($tasks as $task) {
    $taskId = $task->id;

    if ($verbose) {
        echo "Checking task #$taskId: " . substr($task->title, 0, 40) . "...\n";
    }

    // Try to find the session for this task
    $runner = ClaudeRunner::findByTaskId($taskId);

    if (!$runner) {
        // No session found - task may have ended without updating
        if ($verbose) {
            echo "  - No tmux session found\n";
        }

        // Check how long it's been running without a session
        $updatedAt = strtotime($task->updatedAt);
        $idleTime = time() - $updatedAt;

        if ($idleTime > 300) { // 5 minutes without session
            $errors[] = [
                'task_id' => $taskId,
                'reason' => 'No tmux session found (idle ' . round($idleTime / 60) . ' min)'
            ];

            if (!$dryRun) {
                $task->status = 'failed';
                $task->errorMessage = 'Session ended unexpectedly';
                $task->updatedAt = date('Y-m-d H:i:s');
                R::store($task);

                // Log it
                $log = R::dispense('workbenchtasklog');
                $log->workbenchtask = $task;
                $log->type = 'status_change';
                $log->level = 'error';
                $log->message = 'Task marked as failed: session ended unexpectedly';
                $log->createdAt = date('Y-m-d H:i:s');
                R::store($log);
            }
            $updated++;
        }
        continue;
    }

    // Check health of the session
    $health = $runner->checkHealth();

    if ($verbose) {
        echo "  - Session exists: " . ($runner->exists() ? 'yes' : 'no') . "\n";
        echo "  - Status: " . $health['status'] . "\n";
        echo "  - Is hung: " . ($health['is_hung'] ? 'yes' : 'no') . "\n";
        if ($health['error_message']) {
            echo "  - Error: " . $health['error_message'] . "\n";
        }
    }

    if ($health['is_hung']) {
        $errors[] = [
            'task_id' => $taskId,
            'reason' => $health['error_message'] ?? 'Session appears hung'
        ];

        if (!$dryRun) {
            // Update task status
            $task->status = 'failed';
            $task->errorMessage = $health['error_message'] ?? 'Claude session hung';
            $task->updatedAt = date('Y-m-d H:i:s');
            R::store($task);

            // Log it
            $log = R::dispense('workbenchtasklog');
            $log->workbenchtask = $task;
            $log->type = 'status_change';
            $log->level = 'error';
            $log->message = 'Task marked as failed: ' . ($health['error_message'] ?? 'session hung');
            $log->createdAt = date('Y-m-d H:i:s');
            R::store($log);

            // Kill the hung session
            $runner->kill();

            if ($verbose) {
                echo "  - Updated task status to 'failed'\n";
                echo "  - Killed hung tmux session\n";
            }
        }
        $updated++;
    } else {
        $healthy++;
    }

    if ($verbose) {
        echo "\n";
    }
}

// Summary
if ($verbose || !empty($errors)) {
    echo "Summary:\n";
    echo "  Checked: " . count($tasks) . " task(s)\n";
    echo "  Healthy: $healthy\n";
    echo "  Updated: $updated" . ($dryRun ? " (dry run)" : "") . "\n";

    if (!empty($errors)) {
        echo "\nErrors detected:\n";
        foreach ($errors as $e) {
            echo "  Task #{$e['task_id']}: {$e['reason']}\n";
        }
    }
}

exit($updated > 0 ? 0 : 0);
