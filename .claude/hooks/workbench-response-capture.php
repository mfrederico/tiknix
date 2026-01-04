#!/usr/bin/env php
<?php
/**
 * Workbench Response Capture Hook (Stop Hook)
 *
 * Captures Claude's responses and posts them as log entries to the workbench task.
 * This provides visibility into Claude's work even when MCP tools aren't used.
 *
 * Environment variables (set by ClaudeRunner wrapper script):
 * - TIKNIX_TASK_ID: The workbench task ID
 * - TIKNIX_HOOK_URL: The MCP endpoint URL (e.g., http://localhost:8080/mcp/message)
 * - TIKNIX_MEMBER_ID: The member who started the task
 *
 * This hook only activates when running inside a workbench task session.
 */

// Check if we're in a workbench task context
$taskId = getenv('TIKNIX_TASK_ID');
$hookUrl = getenv('TIKNIX_HOOK_URL');

if (!$taskId || !$hookUrl) {
    // Not in a workbench context, exit silently
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
postToMcp($taskId, $textContent, $hookUrl);

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
 * Post a log entry to the task via the MCP endpoint
 */
function postToMcp(string $taskId, string $message, string $hookUrl): bool {
    $mcpRequest = [
        'jsonrpc' => '2.0',
        'id' => 'hook-' . getmypid(),
        'method' => 'tools/call',
        'params' => [
            'name' => 'tiknix_add_task_log',
            'arguments' => [
                'task_id' => (int)$taskId,
                'message' => truncateMessage($message),
                'level' => 'info',
                'type' => 'claude_response'
            ]
        ]
    ];

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($mcpRequest),
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);

    // Silently fail - don't interrupt Claude's work
    @file_get_contents($hookUrl, false, $ctx);

    return true;
}
