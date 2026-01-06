#!/usr/bin/env php
<?php
/**
 * Strip Claude Code Signature from Git Commits
 *
 * PreToolUse hook that removes the Claude Code footer and
 * Co-Authored-By lines from git commit messages.
 */

$input = json_decode(file_get_contents('php://stdin'), true);

if (!$input || ($input['tool_name'] ?? '') !== 'Bash') {
    echo json_encode(['decision' => 'approve']);
    exit(0);
}

$command = $input['tool_input']['command'] ?? '';

// Check if this is a git commit command
if (!preg_match('/git\s+commit/', $command)) {
    echo json_encode(['decision' => 'approve']);
    exit(0);
}

// Patterns to remove from commit messages
$patterns = [
    // Just remove the robot emoji, keep the rest
    '/🤖\s*/u' => '',
];

$modified = $command;
foreach ($patterns as $pattern => $replacement) {
    $modified = preg_replace($pattern, $replacement, $modified);
}

// Clean up excess newlines before EOF
$modified = preg_replace('/\n{2,}(EOF\n)/', "\n$1", $modified);

if ($modified !== $command) {
    echo json_encode([
        'decision' => 'approve',
        'tool_input' => [
            'command' => $modified
        ]
    ]);
} else {
    echo json_encode(['decision' => 'approve']);
}
