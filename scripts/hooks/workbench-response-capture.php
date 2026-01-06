#!/usr/bin/env php
<?php
/**
 * Workbench Response Capture Hook (Stop Hook)
 *
 * Captures Claude's responses and logs them to the workbench task.
 * Also updates task status to "awaiting" when Claude finishes responding.
 *
 * Environment variables (set by ClaudeRunner wrapper script):
 * - TIKNIX_TASK_ID: The workbench task ID
 * - TIKNIX_PROJECT_ROOT: The project root directory
 * - TIKNIX_MEMBER_ID: The member who started the task
 *
 * This hook only activates when running inside a workbench task session.
 */

// Debug log for troubleshooting
$debugLog = '/tmp/stop-hook-debug.log';
file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Stop hook fired\n", FILE_APPEND);

// Check if we're in a workbench task context
$taskId = getenv('TIKNIX_TASK_ID');
file_put_contents($debugLog, "TIKNIX_TASK_ID: " . ($taskId ?: 'NOT SET') . "\n", FILE_APPEND);

if (!$taskId) {
    // Not in a workbench context, exit silently
    file_put_contents($debugLog, "Exiting - no task ID\n\n", FILE_APPEND);
    echo json_encode(new stdClass());
    exit(0);
}

// TIKNIX_PROJECT_ROOT must point to main project (for vendor, bootstrap, DB)
// CLAUDE_PROJECT_DIR may point to isolated workspace (no vendor there)
$mainProject = getenv('TIKNIX_PROJECT_ROOT');
if (!$mainProject) {
    // Fallback: derive from script location
    $mainProject = dirname(__DIR__, 2);
}

// Load the application for database access from main project
require_once $mainProject . '/vendor/autoload.php';
require_once $mainProject . '/bootstrap.php';

use RedBeanPHP\R as R;

// Initialize database
try {
    $configPath = $mainProject . '/conf/config.ini';
    if (file_exists($configPath)) {
        $config = parse_ini_file($configPath, true);
        $dbPath = $mainProject . '/' . ($config['database']['path'] ?? 'database/tiknix.db');
        if (!R::hasDatabase('default')) {
            R::setup('sqlite:' . $dbPath);
        }
    }
} catch (Exception $e) {
    // Can't connect to DB, exit silently
    echo json_encode(new stdClass());
    exit(0);
}

// Read the hook input from stdin
$input = file_get_contents('php://stdin');
$hookInput = json_decode($input, true);

if (!$hookInput) {
    echo json_encode(new stdClass());
    exit(0);
}

// Extract the stop_hook_response (Claude's message)
$stopResponse = $hookInput['stop_hook_response'] ?? [];
$message = $stopResponse['message'] ?? null;

if (!$message) {
    echo json_encode(new stdClass());
    exit(0);
}

// Extract text content from the message
$textContent = extractTextContent($message);

if (!$textContent || strlen(trim($textContent)) < 10) {
    // Skip very short or empty responses
    echo json_encode(new stdClass());
    exit(0);
}

// Skip if it's mostly JSON (tool output)
$trimmed = trim($textContent);
if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
    echo json_encode(new stdClass());
    exit(0);
}

// Post the response as a task log entry
$logResult = addTaskLog($taskId, $textContent);
file_put_contents($debugLog, "addTaskLog result: " . ($logResult ? 'success' : 'failed') . "\n", FILE_APPEND);

// Update task status to "awaiting" (user's turn)
$statusResult = updateTaskStatus($taskId);
file_put_contents($debugLog, "updateTaskStatus result: " . ($statusResult ? 'success' : 'failed') . "\n", FILE_APPEND);
file_put_contents($debugLog, "Content length: " . strlen($textContent) . "\n\n", FILE_APPEND);

// Always return success to not block Claude
echo json_encode(new stdClass());
exit(0);


/**
 * Extract text content from Claude's response message
 */
function extractTextContent(array $message): ?string {
    $content = $message['content'] ?? [];

    if (is_string($content)) {
        return $content;
    }

    // Content is an array of content blocks
    $textParts = [];
    foreach ($content as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text') {
            $textParts[] = $block['text'] ?? '';
        } elseif (is_string($block)) {
            $textParts[] = $block;
        }
    }

    return $textParts ? implode("\n", $textParts) : null;
}

/**
 * Truncate message to avoid overwhelming the log system
 */
function truncateMessage(string $text, int $maxLength = 2000): string {
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    return substr($text, 0, $maxLength) . "\n\n... [truncated]";
}

/**
 * Add Claude's response to the conversation (taskcomment table)
 */
function addTaskLog(string $taskId, string $message): bool {
    try {
        // Get the task to find the member_id
        $task = R::load('workbenchtask', (int)$taskId);
        if (!$task->id) {
            return false;
        }

        $comment = R::dispense('taskcomment');
        $comment->taskId = (int)$taskId;
        $comment->memberId = $task->memberId; // Use task owner as author
        $comment->content = truncateMessage($message);
        $comment->isFromClaude = 1; // Mark as Claude's response
        $comment->isInternal = 0;
        $comment->createdAt = date('Y-m-d H:i:s');
        R::store($comment);
        return true;
    } catch (Exception $e) {
        // Silently fail - don't interrupt Claude's work
        return false;
    }
}

/**
 * Update task status to "awaiting" (user's turn to respond)
 */
function updateTaskStatus(string $taskId): bool {
    try {
        $task = R::load('workbenchtask', (int)$taskId);
        if (!$task->id) {
            return false;
        }

        // Only update to awaiting if currently running
        if ($task->status === 'running') {
            $task->status = 'awaiting';
            $task->progressMessage = 'Waiting for user input';
            $task->updatedAt = date('Y-m-d H:i:s');
            R::store($task);
        }
        return true;
    } catch (Exception $e) {
        // Silently fail - don't interrupt Claude's work
        return false;
    }
}
