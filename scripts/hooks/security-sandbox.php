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
$data = json_decode(($input) ?? '', true);

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

// Workspace isolation - if set, writes are restricted to this folder
$workspaceRoot = getenv('TIKNIX_PROJECT_ROOT') ?: '';
if ($workspaceRoot) {
    $workspaceRoot = rtrim(realpath($workspaceRoot) ?: $workspaceRoot, '/');
}

// Are we running INSIDE the bubblewrap jail? jail-run.sh mounts a tmpfs at
// /aibhome and exports AIBUILDER_INSTANCE; neither exists on the host.
//
// This matters because not every agent is jailed. ClaudeRunner::jailFor() returns
// no jail for an isolated task workspace (no dot in the basename, or outside
// /var/www/html/default, or no public/index.php) — those run on the HOST, where
// this hook is the only boundary there is. So rules are not deleted for being
// redundant under bwrap; they are scoped, and only skipped where the jail really
// does enforce them.
// An EMPTY value is not "jailed": getenv() returns '' for a variable that exists
// but is blank, and !== false would have quietly treated the host as a jail and
// switched off every rule scoped to it.
$inJail = is_dir('/aibhome') || (string) getenv('AIBUILDER_INSTANCE') !== '';

// Security log file
$securityLogPath = $projectDir . '/log/security.log';

// === DATABASE CONNECTION ===

// WHERE THE RULES LIVE IS NOT WHERE THE AGENT IS WORKING.
//
// This read $projectDir alone, i.e. CLAUDE_PROJECT_DIR. In an agent run that is the
// WORKTREE (…/projects/<member>/<slug>.tiknix/<task>), a fresh clone that carries no
// database/security.db — so every run took the "not found" branch below and exited 0
// with EVERY PATH RULE OFF. That is why lib/ and conf/*.example.ini were writable inside
// a worktree while being protected everywhere else.
//
// Ask the installs that actually ship the file, most specific first: the workspace root
// the launcher names, then the install this very script lives in (it cannot be running
// otherwise), then the working directory as a last resort.
$dbCandidates = [];
foreach ([$workspaceRoot, dirname(dirname(__DIR__)), $projectDir] as $root) {
    $root = rtrim((string) $root, '/');
    if ($root === '') continue;
    $dbCandidates[$root] = $root . '/database/security.db';
}

$securityDbPath = '';
$installRoot    = '';
foreach ($dbCandidates as $root => $candidate) {
    if (file_exists($candidate)) { $securityDbPath = $candidate; $installRoot = $root; break; }
}

if ($securityDbPath === '') {
    // WHO IS THE BOUNDARY HERE?
    //
    // INSIDE THE JAIL bwrap is. It binds the workspace and its vendor and nothing else:
    // other tenants, host secrets and /etc are not in the namespace at all, so they
    // cannot be reached whatever these rules say. A jailed workspace legitimately ships
    // no security.db, and refusing every tool for its absence would stop the agent doing
    // the work it was jailed in order to be allowed to do.
    //
    // UNJAILED nothing else is holding the line — the agent runs as a real uid on the
    // host with permission prompts off — so these rules are the only guard, and their
    // absence is a broken install rather than a deliberate one. Fail closed: "no rules
    // loaded, so permit everything" is indistinguishable from a clean pass, and silent.
    if ($inJail) {
        exit(0);
    }
    fwrite(STDERR,
        "SECURITY BLOCK: no security.db found (looked in: "
        . implode(', ', array_keys($dbCandidates)) . ") and this session is NOT jailed, so "
        . "nothing else constrains it. Refusing every tool rather than running with all "
        . "path rules disabled. Fix: restore database/security.db in the install, or point "
        . "TIKNIX_PROJECT_ROOT at one that has it.\n");
    exit(2);
}

// Load RedBeanPHP from the install that owns the rules, not from the worktree.
require_once $installRoot . '/vendor/autoload.php';
use RedBeanPHP\R;
use \app\Bean;

try {
    R::setup('sqlite:' . $securityDbPath);
    Bean::freeze(true); // Read-only mode

    // AN `allow` IS AN EXCEPTION, SO IT MUST BE READ BEFORE THE RULE IT EXCEPTS.
    //
    // checkPath()/checkCommand() return on the FIRST matching rule, and this was ordered
    // by priority alone. Every `allow` in the table carves a specific path out of a
    // broader `block` — and all of them sat behind it, so not one had ever fired:
    //
    //   /home/ubuntu/capricorn        (allow, level 50, priority 5)   shadowed by
    //   /home/ubuntu/production/tiknix(allow, level 100, priority 100) shadowed by
    //   .claude/projects/*/memory/    (allow, priority 12)             shadowed by
    //                                  /home (block, level 15, priority 1)
    //
    // Somebody wrote each of those deliberately and none of them worked. Ordering allows
    // first makes the exception mechanism function by construction, instead of depending
    // on whoever adds the next one to also guess a lower priority number than every block
    // it needs to beat. Allows still carry their own level, so this widens nothing on its
    // own: it only lets a rule that was always meant to apply actually apply.
    $rules = Bean::find('securitycontrol',
        "is_active = 1 ORDER BY CASE action WHEN 'allow' THEN 0 ELSE 1 END, priority ASC");

    // Inside the jail, drop the rules bwrap already enforces. /root, /boot, /sys,
    // /home and /var/log are not bind-mounted at all, and a jailed process holds
    // CapEff=0 with NoNewPrivs=1 and no block devices — so reboot/shutdown/sudo/
    // mkfs/dd-to-device cannot succeed regardless. Keeping them only produces
    // false positives on commands that MENTION the word.
    // A rule with no scope (older row, hand-added) is treated as 'always'.
    if ($inJail) {
        $rules = array_filter($rules, static function ($r) {
            return (string) ($r->scope ?? 'always') !== 'unjailed';
        });
    }
} catch (Exception $e) {
    fwrite(STDERR, "WARNING: Failed to load security rules: " . $e->getMessage() . "\n");
    exit(0); // Allow on error - fail open (could change to fail closed with exit(2))
}

// === HELPER FUNCTIONS ===

/**
 * Strip quoted spans from a command so rules match ACTIONS, not MENTIONS.
 *
 * grep -nE "reboot|shutdown" file is a search, not a restart, and blocking it
 * taught nobody anything except to distrust the hook. The same goes for a commit
 * message, a comment, or a sed expression that happens to name a blocked word.
 *
 * Quoted text is removed rather than kept, and the payload of a nested shell is
 * pulled back out separately by nestedShellPayloads() — so sh -c "reboot" is
 * still caught, while echo "reboot" is not.
 */
function unquotedView(string $s): string {
    $s = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', ' ', $s) ?? $s;
    $s = preg_replace("/'[^']*'/", ' ', $s) ?? $s;
    return preg_replace('/(^|\s)#.*$/m', ' ', $s) ?? $s;   // trailing comments
}

/**
 * Quoted payloads handed to a nested shell (sh -c '…', bash -c "…", eval).
 * These ARE commands, so they have to be checked as commands.
 */
function nestedShellPayloads(string $command): array {
    $out = [];
    $re = '/\b(?:ba|z|da|k|a)?sh\s+(?:-[a-zA-Z]+\s+)*-c\s+("(?:\\\\.|[^"\\\\])*"|\'[^\']*\')/';
    if (preg_match_all($re, $command, $m)) {
        foreach ($m[1] as $q) { $out[] = substr($q, 1, -1); }
    }
    if (preg_match_all('/\beval\s+("(?:\\\\.|[^"\\\\])*"|\'[^\']*\')/', $command, $m)) {
        foreach ($m[1] as $q) { $out[] = substr($q, 1, -1); }
    }
    return $out;
}

/**
 * Every string that should be matched as a command: the visible command with
 * quoted arguments removed, plus each nested shell payload (recursively).
 */
function commandSubjects(string $command, int $depth = 0): array {
    $subjects = [unquotedView($command)];
    if ($depth < 3) {
        foreach (nestedShellPayloads($command) as $payload) {
            foreach (commandSubjects($payload, $depth + 1) as $s) { $subjects[] = $s; }
        }
    }
    return $subjects;
}

/**
 * Targets a command would WRITE to — redirect destinations and the operands of
 * write verbs — including relative ones.
 *
 * The path scan only ever looked at absolute paths (#(?:^|\s)(/[^\s]+)#), so
 * echo x > .claude/guard.php sailed past every protect rule. Inside the jail
 * that is the gap that matters most: bwrap mounts the instance READ-WRITE, so the
 * protect rules on .claude, scripts/hooks, conf/ and lib/ are the only thing
 * stopping an agent from editing the guardrails that constrain it.
 *
 * Only write POSITIONS are collected, not every token, so naming a protected file
 * in a read (grep x .claude/settings.json) stays allowed.
 */
function writeTargets(string $subject): array {
    $targets = [];

    // Redirections: > file, >> file (but not 2>&1 style fd dups).
    if (preg_match_all('/>>?\s*([^\s|;&<>()]+)/', $subject, $m)) {
        foreach ($m[1] as $t) { if ($t !== '' && $t[0] !== '&') { $targets[] = $t; } }
    }

    // Operands of commands that modify files in place.
    $verbs = 'rm|mv|cp|tee|truncate|chmod|chown|chgrp|ln|install|shred|dd';
    foreach (preg_split('/(?:\|\||&&|[|;\n])/', $subject) as $segment) {
        $segment = trim($segment);
        if ($segment === '') continue;
        if (!preg_match('/^\s*(?:sudo\s+)?(' . $verbs . '|sed\s+-i\S*)\b(.*)$/s', $segment, $mm)) continue;
        foreach (preg_split('/\s+/', trim($mm[2])) as $tok) {
            if ($tok === '' || $tok[0] === '-') continue;   // skip flags
            $targets[] = $tok;
        }
    }

    return $targets;
}

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
 * Path-rule matching, aligned to path SEGMENTS rather than raw substrings.
 *
 * A plain substring match reads a path rule as "any path containing these characters",
 * which is not what a path means. `/boot` matched `…/tiknix/bootstrap.php` and blocked
 * edits to it; the same flaw has `/var/log` blocking `/var/logging/`, `/root` blocking
 * `/rootkit-scan/`, and `/etc/passwd` blocking `/etc/passwd-backup-tool/`.
 *
 * Those are not harmless false positives. A guard that fires on files it was never meant
 * to protect trains people to disable it, and a disabled guard protects nothing — so the
 * over-broad match costs real security rather than adding any.
 *
 * A pattern matches when it appears at a segment boundary: the next character is `/` or
 * the path ends there. `/boot` therefore matches `/boot` and `/boot/vmlinuz`, but not
 * `/bootstrap.php`. Patterns beginning with `/` that name a bare directory (`/.ssh`) still
 * match at any depth, which is how they were always intended to work.
 *
 * Regex patterns are handed straight to patternMatches() — an author writing a regex has
 * said exactly what they mean, and second-guessing it here would be its own surprise.
 */
function pathPatternMatches(string $pattern, string $subject): bool {
    $pattern = trim($pattern);
    if ($pattern === '') return false;

    // Regex rules keep their existing semantics.
    $first = $pattern[0];
    $last  = $pattern[strlen($pattern) - 1];
    if (strlen($pattern) >= 3 && $first === $last && in_array($first, ['/', '#', '~', '@'], true)) {
        return patternMatches($pattern, $subject);
    }

    $pattern = rtrim($pattern, '/');
    if ($pattern === '') return false;

    $len = strlen($pattern);
    $at  = 0;
    while (($at = strpos($subject, $pattern, $at)) !== false) {
        $after = $subject[$at + $len] ?? '';        // '' = pattern ends the path
        if ($after === '' || $after === '/') return true;
        $at++;
    }
    return false;
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
 * Check if a path is within the allowed workspace
 * Returns true if no workspace restriction or path is within workspace
 */
function isWithinWorkspace(string $path, string $workspaceRoot): bool {
    if (empty($workspaceRoot)) {
        return true; // No workspace restriction
    }

    $normalizedPath = normalizePath($path);

    // For new files that don't exist yet, check the directory
    if (!file_exists($path)) {
        $dir = dirname($path);
        $normalizedPath = normalizePath($dir);
        if (!$normalizedPath || $normalizedPath === '.') {
            $normalizedPath = $path; // Use original path if dir doesn't resolve
        }
    }

    // Check if path starts with workspace root
    return strpos($normalizedPath, $workspaceRoot) === 0;
}

/**
 * Check path against rules
 */
function checkPath(string $path, array $rules, int $memberLevel, bool $isWrite): array {
    $path = normalizePath($path);

    foreach ($rules as $rule) {
        if ($rule->target !== 'path') continue;
        // Segment-aligned, not substring — see pathPatternMatches().
        if (!pathPatternMatches($rule->pattern, $path)) continue;

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
    $subjects = commandSubjects($command);

    foreach ($rules as $rule) {
        if ($rule->target !== 'command') continue;

        $hit = false;
        foreach ($subjects as $subject) {
            if (patternMatches($rule->pattern, $subject)) { $hit = true; break; }
        }
        if (!$hit) continue;

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
            // Workspace isolation - block writes outside the workspace
            if (!isWithinWorkspace($filePath, $workspaceRoot)) {
                blockTool("Cannot write outside workspace. File '{$filePath}' is not within '{$workspaceRoot}'");
            }

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

            // Also check for file paths in the command. Same rule as above: a path
            // inside a quoted argument is a mention (a grep pattern, a message, a
            // doc string), not an access — scan the unquoted view plus any nested
            // shell payload, so sh -c "cat /etc/shadow" is still seen.
            $subjects     = commandSubjects($command);
            $pathScanText = implode(' ', $subjects);

            // Writes first, and checked AS writes (isWrite = true) so protect
            // rules actually engage — reading a protected file stays fine.
            foreach ($subjects as $subject) {
                foreach (writeTargets($subject) as $target) {
                    $result = checkPath($target, $rulesArray, $memberLevel, true);
                    if (!$result['allowed']) {
                        blockTool("Command writes to a protected path: {$result['reason']}");
                    }
                }
            }

            if (preg_match_all('#(?:^|\s)(/[^\s]+)#', $pathScanText, $matches)) {
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
