#!/usr/bin/env php
<?php
/**
 * Claude Code Hook - Log file activity to Tiknix task
 *
 * This hook is called by Claude Code after Edit/Write operations.
 * It reads the tool input from stdin (JSON) and logs to the active task.
 *
 * Hook Input Format (JSON via stdin):
 * {
 *   "session_id": "...",
 *   "hook_event_name": "PostToolUse",
 *   "tool_name": "Write",
 *   "tool_input": { "file_path": "/path/to/file" },
 *   "tool_response": { "success": true },
 *   ...
 * }
 */

// Get project directory (two levels up from this script)
$scriptDir = dirname(__FILE__);
$projectDir = dirname(dirname($scriptDir));

// Read hook input from stdin (JSON with tool info)
$hookInput = file_get_contents('php://stdin');
$data = json_decode($hookInput, true);

if (!$data) {
    exit(0);
}

// Parse JSON data
$toolName = $data['tool_name'] ?? '';
$filePath = $data['tool_input']['file_path'] ?? '';
$success = $data['tool_response']['success'] ?? true;

// Only proceed if we have a file path
if (empty($filePath)) {
    exit(0);
}

// Get current task ID from environment (set by ClaudeRunner)
$taskId = getenv('TIKNIX_TASK_ID');

// If no task ID from environment, try to get from the work directory name
if (!$taskId) {
    $workDir = getcwd();
    if (preg_match('/tiknix-.*-task-(\d+)/', $workDir, $matches)) {
        $taskId = $matches[1];
    }
}

// Only log if we have a task ID
if (!$taskId) {
    exit(0);
}

// Get API token
$tokenFile = $projectDir . '/.mcp_token';
if (!file_exists($tokenFile)) {
    exit(0);
}

$apiToken = trim(file_get_contents($tokenFile));
if (empty($apiToken)) {
    exit(0);
}

// Get MCP URL - priority: env var (set by ClaudeRunner) > .mcp_url file > default
$mcpEndpoint = getenv('TIKNIX_HOOK_URL');

if (!$mcpEndpoint) {
    $mcpUrlFile = $projectDir . '/.mcp_url';
    if (file_exists($mcpUrlFile)) {
        $baseUrl = trim(file_get_contents($mcpUrlFile));
    } else {
        $baseUrl = 'http://localhost:8080';
    }
    $mcpEndpoint = $baseUrl . '/mcp/message';
}

// Make the file path relative to project for cleaner logging
$relativePath = $filePath;
if (strpos($filePath, $projectDir . '/') === 0) {
    $relativePath = substr($filePath, strlen($projectDir) + 1);
}

// Determine message based on success
if ($success === false) {
    $logMessage = ($toolName ?: 'Edit') . " (failed): {$relativePath}";
    $logLevel = 'warning';
} else {
    $logMessage = ($toolName ?: 'Edit') . ": {$relativePath}";
    $logLevel = 'info';
}

// Log the activity via MCP
$mcpRequest = [
    'jsonrpc' => '2.0',
    'id' => 'hook-' . microtime(true),
    'method' => 'tools/call',
    'params' => [
        'name' => 'tiknix_add_task_log',
        'arguments' => [
            'task_id' => (int)$taskId,
            'level' => $logLevel,
            'type' => 'file_change',
            'message' => $logMessage
        ]
    ]
];

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiToken}\r\n",
        'content' => json_encode($mcpRequest),
        'timeout' => 5,
        'ignore_errors' => true
    ]
]);

// Silently make request - don't block Claude
@file_get_contents($mcpEndpoint, false, $ctx);

// Always exit 0 so we don't block Claude
exit(0);
