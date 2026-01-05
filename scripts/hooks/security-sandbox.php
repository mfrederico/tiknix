#!/usr/bin/env php
<?php
/**
 * Security Sandbox Hook for Claude Code
 *
 * Blocks access to sensitive files and dangerous commands based on
 * rules stored in an isolated security database.
 *
 * This is a PreToolUse hook that runs BEFORE any tool executes.
 *
 * Exit codes:
 * - 0 with no output = allow
 * - 0 with JSON output = modify the request
 * - 2 = BLOCK the tool call
 *
 * Database: database/security.db (isolated from main app DB)
 * Table: securitycontrol
 *   - target: 'path' or 'command'
 *   - action: 'block', 'allow', 'protect'
 *   - pattern: path prefix or regex
 *   - level: minimum level required (null = applies to all)
 *   - priority: lower = checked first
 */

// Read input from stdin
$input = file_get_contents('php://stdin');
$data = json_decode($input, true);

if (!$data) {
    exit(0); // Allow if we can't parse input
}

$toolName = $data['tool_name'] ?? '';
$toolInput = $data['tool_input'] ?? [];

// Get environment variables
$projectDir = getenv('CLAUDE_PROJECT_DIR') ?: dirname(dirname(__DIR__));
$projectDir = rtrim(realpath($projectDir) ?: $projectDir, '/');

// Get member level from environment (set by ClaudeRunner)
// Levels: 1=ROOT, 50=ADMIN, 100=MEMBER, 101=PUBLIC
$memberLevel = (int)(getenv('TIKNIX_MEMBER_LEVEL') ?: 100);
$memberId = (int)(getenv('TIKNIX_MEMBER_ID') ?: 0);
$taskId = (int)(getenv('TIKNIX_TASK_ID') ?: 0);

// Security log file
$securityLogPath = $projectDir . '/log/security.log';

// === DATABASE CONNECTION ===

$securityDbPath = $projectDir . '/database/security.db';

if (!file_exists($securityDbPath)) {
    // No security database - allow by default but log warning
    fwrite(STDERR, "WARNING: Security database not found at {$securityDbPath}\n");
    exit(0);
}

// Load RedBeanPHP
require_once $projectDir . '/vendor/autoload.php';
use RedBeanPHP\R;

try {
    R::setup('sqlite:' . $securityDbPath);
    R::freeze(true); // Read-only mode

    // Load all active rules, ordered by priority
    $rules = R::find('securitycontrol', 'is_active = 1 ORDER BY priority ASC');
} catch (Exception $e) {
    fwrite(STDERR, "WARNING: Failed to load security rules: " . $e->getMessage() . "\n");
    exit(0); // Allow on error - fail open (could change to fail closed with exit(2))
}

// === HELPER FUNCTIONS ===

/**
 * Check if a pattern matches a path/command
 * Patterns can be:
 * - Simple substring: /etc (matches /etc/passwd)
 * - Regex: /pattern/ or #pattern# (delimited by same char at start/end)
 */
function patternMatches(string $pattern, string $subject): bool {
    $pattern = trim($pattern);
    if (empty($pattern)) return false;

    // Check if it's a regex (common delimiters: / # ~ @)
    // Must start and end with same delimiter and be at least 3 chars
    $firstChar = $pattern[0];
    $lastChar = $pattern[strlen($pattern) - 1];

    if (strlen($pattern) >= 3 &&
        $firstChar === $lastChar &&
        in_array($firstChar, ['/', '#', '~', '@'], true)) {
        // It's a regex - use it directly
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            // Invalid regex - treat as literal string
            return strpos($subject, $pattern) !== false;
        }
        return (bool)$result;
    }

    // Simple substring match
    return strpos($subject, $pattern) !== false;
}

/**
 * Normalize and resolve a path
 */
function normalizePath(string $path): string {
    $path = str_replace('//', '/', $path);
    $realPath = realpath($path);
    return $realPath ?: $path;
}

/**
 * Check path against rules
 */
function checkPath(string $path, array $rules, int $memberLevel, bool $isWrite): array {
    $path = normalizePath($path);

    foreach ($rules as $rule) {
        if ($rule->target !== 'path') continue;
        if (!patternMatches($rule->pattern, $path)) continue;

        switch ($rule->action) {
            case 'block':
                // Check if member level allows bypass
                if ($rule->level !== null && $memberLevel <= $rule->level) {
                    continue 2; // Member has sufficient level, skip this rule
                }
                return [
                    'allowed' => false,
                    'reason' => $rule->description ?: "Blocked by security rule: {$rule->name}"
                ];

            case 'allow':
                // Check if member has required level
                if ($rule->level !== null && $memberLevel > $rule->level) {
                    continue 2; // Member doesn't have sufficient level
                }
                return ['allowed' => true];

            case 'protect':
                // Protected paths: read OK, write requires level
                if (!$isWrite) {
                    return ['allowed' => true]; // Read is always OK
                }
                if ($rule->level !== null && $memberLevel > $rule->level) {
                    return [
                        'allowed' => false,
                        'reason' => "Write access requires ADMIN: " . ($rule->description ?: $rule->name)
                    ];
                }
                return ['allowed' => true];
        }
    }

    // No rule matched - allow by default
    return ['allowed' => true];
}

/**
 * Check command against rules
 */
function checkCommand(string $command, array $rules, int $memberLevel): array {
    foreach ($rules as $rule) {
        if ($rule->target !== 'command') continue;
        if (!patternMatches($rule->pattern, $command)) continue;

        switch ($rule->action) {
            case 'block':
                if ($rule->level !== null && $memberLevel <= $rule->level) {
                    continue 2;
                }
                return [
                    'safe' => false,
                    'reason' => $rule->description ?: "Blocked by security rule: {$rule->name}"
                ];

            case 'allow':
                if ($rule->level !== null && $memberLevel > $rule->level) {
                    continue 2;
                }
                return ['safe' => true];
        }
    }

    return ['safe' => true];
}

/**
 * Log security event to file
 */
function logSecurity(string $level, string $message, array $context = []): void {
    global $securityLogPath, $toolName, $toolInput, $memberLevel, $memberId, $taskId;

    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => $level,
        'message' => $message,
        'tool' => $toolName,
        'member_id' => $memberId,
        'member_level' => $memberLevel,
        'task_id' => $taskId,
        'context' => $context
    ];

    // Add tool input (sanitized - don't log full file contents)
    if (isset($toolInput['command'])) {
        $logEntry['command'] = substr($toolInput['command'], 0, 500);
    }
    if (isset($toolInput['file_path'])) {
        $logEntry['file_path'] = $toolInput['file_path'];
    }
    if (isset($toolInput['path'])) {
        $logEntry['path'] = $toolInput['path'];
    }

    $logLine = date('Y-m-d H:i:s') . " [{$level}] {$message} " . json_encode($logEntry) . "\n";

    @file_put_contents($securityLogPath, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Block the tool with a message
 */
function blockTool(string $reason): void {
    // Log the block
    logSecurity('BLOCK', $reason);

    fwrite(STDERR, "SECURITY BLOCK: {$reason}\n");
    R::close();
    exit(2);
}

// === MAIN LOGIC ===

// Convert rules to array for easier iteration
$rulesArray = array_values($rules);

switch ($toolName) {
    case 'Read':
    case 'View':
        $filePath = $toolInput['file_path'] ?? $toolInput['path'] ?? '';
        if ($filePath) {
            $result = checkPath($filePath, $rulesArray, $memberLevel, false);
            if (!$result['allowed']) {
                blockTool("Cannot read file: {$result['reason']}");
            }
        }
        break;

    case 'Write':
    case 'Edit':
        $filePath = $toolInput['file_path'] ?? $toolInput['path'] ?? '';
        if ($filePath) {
            $result = checkPath($filePath, $rulesArray, $memberLevel, true);
            if (!$result['allowed']) {
                blockTool("Cannot write/edit file: {$result['reason']}");
            }
        }
        break;

    case 'Bash':
        $command = $toolInput['command'] ?? '';
        if ($command) {
            // Check command patterns
            $result = checkCommand($command, $rulesArray, $memberLevel);
            if (!$result['safe']) {
                blockTool("Cannot execute command: {$result['reason']}");
            }

            // Also check for file paths in the command
            if (preg_match_all('#(?:^|\s)(/[^\s]+)#', $command, $matches)) {
                foreach ($matches[1] as $path) {
                    // Skip common safe paths
                    if (in_array($path, ['/dev/null', '/dev/stdout', '/dev/stderr'])) {
                        continue;
                    }
                    $result = checkPath($path, $rulesArray, $memberLevel, false);
                    if (!$result['allowed']) {
                        blockTool("Command references blocked path: {$result['reason']}");
                    }
                }
            }
        }
        break;

    case 'Glob':
    case 'Grep':
        $path = $toolInput['path'] ?? '';
        if ($path) {
            $result = checkPath($path, $rulesArray, $memberLevel, false);
            if (!$result['allowed']) {
                blockTool("Cannot search in path: {$result['reason']}");
            }
        }
        break;
}

// Close database connection
R::close();

// Allow the tool call
exit(0);
