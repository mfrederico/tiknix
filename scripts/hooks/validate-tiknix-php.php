#!/usr/bin/env php
<?php
/**
 * Tiknix PHP Code Validator Hook
 *
 * Validates PHP code against Tiknix/RedBeanPHP/FlightPHP coding standards:
 * 1. Bean:: is THE database mechanism - raw R:: is blocked wherever Bean:: wraps it
 * 2. Bean type names must be all lowercase (no underscores) for R::dispense
 * 3. exec() should almost NEVER be used - only in extreme situations
 * 4. Prefer RedBeanPHP associations (ownBeanList/sharedBeanList) over manual FK management
 * 5. Use with()/withCondition() for ordering and filtering associations
 * 6. Security scanning (OWASP Top 10 patterns)
 *
 * Usage: This script reads JSON from stdin and outputs JSON to stdout
 */

/**
 * The Bean:: surface, from lib/Bean.php. A raw R:: call to any of these has a
 * wrapper that should have been used instead; anything NOT here (getWriter, nuke,
 * testConnection, setup, close) has no Bean:: equivalent and is left alone, because
 * blocking a call with no alternative just makes the hook something to work around.
 */
const BEAN_WRAPPED = [
    'dispense', 'load', 'store', 'trash', 'trashAll',
    'findOne', 'find', 'findAll', 'count',
    'exec', 'getAll', 'getRow', 'getCol', 'getCell',
    'begin', 'commit', 'rollback',
    'freeze', 'inspect', 'addDatabase', 'selectDatabase', 'hasDatabase',
    'currentDatabaseKey', 'getDatabaseAdapter', 'normalize', 'genSlots',
];

/**
 * Where raw R:: is legitimate, per CLAUDE.md.
 *
 * bootstrap.php owns the connection lifecycle, the schema seeds build the schema
 * before the ORM layer means anything, and lib/Bean.php IS the wrapper - it has
 * nothing to delegate to but R.
 */
function rawRedbeanAllowed(string $filePath): bool
{
    $path = str_replace('\\', '/', $filePath);
    if (basename($path) === 'bootstrap.php') return true;
    if (basename($path) === 'Bean.php' && strpos($path, '/lib/') !== false) return true;
    if (strpos($path, '/services/Schema/Seeds/') !== false) return true;
    return false;
}

/**
 * Content the tokenizer will accept, whether it arrived as a whole file or a hunk.
 *
 * An Edit hands us new_string - a FRAGMENT with no open tag - so one has to be added
 * or the tokenizer calls the whole thing T_INLINE_HTML and every check that reads
 * tokens sees nothing.
 *
 * The shebang is the case that made this its own function. Every CLI script here
 * starts with a shebang line ABOVE its open tag, so the naive "does it start with
 * <?php" test said no and prepended a second one. Two open tags is a parse error,
 * TOKEN_PARSE throws, and both callers fall back to scanning raw text - which is
 * exactly the prose-matching behaviour they exist to avoid. Every scripts/*.php file
 * was silently on the fallback path.
 */
function phpParsable(string $content): string
{
    if (preg_match('/^(#![^\n]*\n)?\s*<\?(php|=)?/', $content)) return $content;
    return "<?php\n" . $content;
}

/**
 * Code with comments and string literals removed, for checks that must not fire on
 * prose.
 *
 * The backtick check below learned this the hard way: a docblock that merely NAMED
 * something got treated as if it did it, and the write was blocked. A comment saying
 * "never call R::store here" is advice, and an MCP tool description that quotes
 * "R::exec usage" is documentation - neither is a database call. PHP's tokenizer is
 * the only thing that reliably tells one from the other.
 *
 * NO TOKEN_PARSE here, deliberately. TOKEN_PARSE validates syntax and THROWS on
 * anything that is not a complete program - and an Edit hunk almost never is: a bare
 * "public function foo()" at top level, a dangling closing brace, half a match arm.
 * Every one of those fell through to the raw-content fallback, where a docblock that
 * merely NAMES a raw call reads exactly like the call itself. This hook blocked its
 * own author's edit that way, twice. Plain token_get_all is a LEXER, not a parser: it
 * classifies comments and strings correctly inside a fragment and never throws, which
 * is all this function needs. The try/catch stays as a backstop.
 */
function phpCodeOnly(string $content): string
{
    $source = phpParsable($content);

    try {
        $tokens = @token_get_all($source);
    } catch (\Throwable $e) {
        return $content;
    }
    if (!$tokens) return $content;

    $skip = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE];
    $out  = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            if (in_array($token[0], $skip, true)) { $out .= ' '; continue; }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
}

/**
 * Find raw R:: calls that have a Bean:: wrapper.
 *
 * Bean:: is not a style preference: it routes every read and write through the
 * cached adapter, so a raw R:: read can serve rows a Bean:: write has already
 * invalidated - a staleness bug with nothing on screen or in the log to explain it.
 * It also normalizes the bean type, which is what makes dispense('api_key') work.
 *
 * Matches the fully-qualified form too: the char before R only has to be a
 * non-identifier, so RedBeanPHP\R::store is caught while PlanRunner::start is not.
 *
 * @return array List of blocking issues
 */
function findRawRedbeanCalls(string $content, string $filePath): array
{
    if (rawRedbeanAllowed($filePath)) return [];

    $issues = [];
    $seen   = [];
    if (preg_match_all('/(?<![A-Za-z0-9_])R::([a-zA-Z]+)/', phpCodeOnly($content), $matches)) {
        foreach ($matches[1] as $method) {
            if (!in_array($method, BEAN_WRAPPED, true)) continue;   // no wrapper to use
            if (isset($seen[$method])) continue;
            $seen[$method] = true;
            $issues[] = "R::{$method}() bypasses the Bean wrapper. Use Bean::{$method}() instead "
                . "(add: use app\\Bean;). Raw R:: skips the cached adapter's read-caching and "
                . "write-busting, and skips bean-type normalization. Raw R:: is allowed ONLY in "
                . "bootstrap.php (connection lifecycle) and services/Schema/Seeds/*.php.";
        }
    }

    return $issues;
}

/**
 * Find R::dispense with invalid bean type names - these WILL FAIL at runtime!
 *
 * CRITICAL: R::dispense() bean type names must be:
 * - All lowercase (a-z)
 * - Only alphanumeric (no underscores, no uppercase)
 *
 * @param string $content PHP code to check
 * @return array List of blocking issues
 */
function findUnderscoreTableNames(string $content): array
{
    $issues = [];

    // Match R::dispense with any table name
    if (preg_match_all("/R::dispense\s*\(\s*['\"]([a-zA-Z0-9_]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $tableName) {
            // Check for underscores
            if (strpos($tableName, '_') !== false) {
                $lowercase = strtolower(str_replace('_', '', $tableName));
                $issues[] = "R::dispense('{$tableName}') will FAIL! RedBeanPHP doesn't allow underscores in dispense(). "
                    . "Use R::dispense('{$lowercase}') instead.";
            }
            // Check for uppercase letters
            elseif ($tableName !== strtolower($tableName)) {
                $lowercase = strtolower($tableName);
                $issues[] = "R::dispense('{$tableName}') will FAIL! RedBeanPHP requires all lowercase bean types in dispense(). "
                    . "Use R::dispense('{$lowercase}') instead.";
            }
        }
    }

    return $issues;
}

/**
 * Find problematic use of exec() and flag it for review.
 *
 * BOTH prefixes. Bean::exec is the wrapper for R::exec, so it bypasses FUSE models
 * in exactly the same way - checking only R:: meant that converting a file to the
 * mandated Bean:: silenced the warning about the thing that was actually wrong,
 * turning a standards fix into a way to hide an INSERT that should have been a bean.
 *
 * @param string $content PHP code to check
 * @return array List of warning issues
 */
function findExecUsage(string $content): array
{
    $issues = [];

    // Match R::exec / Bean::exec with any SQL statement
    if (preg_match_all("/(?:R|Bean)::exec\s*\(\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $sql) {
            $sqlUpper = strtoupper(trim($sql));

            // DDL operations are OK - these can't be done with beans
            if (preg_match('/^(CREATE|ALTER|DROP)\s/', $sqlUpper)) {
                continue;
            }

            if (strpos($sqlUpper, 'INSERT') === 0) {
                $issues[] = "exec() used for INSERT. This bypasses FUSE models! Use Bean::dispense() + Bean::store() instead.";
            } elseif (strpos($sqlUpper, 'UPDATE') === 0) {
                // Check if it's a simple update that should use beans
                if (strpos($sqlUpper, 'WHERE') !== false && (strpos($sql, '= ?') !== false || strpos($sql, '=?') !== false)) {
                    if (strpos($sql, '+ 1') === false && strpos($sql, '- 1') === false && strpos($sqlUpper, 'NOW()') === false) {
                        $issues[] = "exec() used for UPDATE. This bypasses FUSE models! Use Bean::load() + Bean::store() instead.";
                    } else {
                        $issues[] = "exec() for UPDATE detected. Verify this is truly necessary and cannot be done with beans.";
                    }
                } else {
                    $issues[] = "exec() for UPDATE detected. Verify this is truly necessary and cannot be done with beans.";
                }
            } elseif (strpos($sqlUpper, 'DELETE') === 0) {
                $issues[] = "exec() used for DELETE. This bypasses FUSE models! Use Bean::trash() instead.";
            } else {
                $issues[] = "exec() detected. Bean::exec should ONLY be used in extreme situations. Can this use bean methods instead?";
            }
        }
    }

    return $issues;
}

/**
 * Detect manual foreign key assignments and suggest using associations instead.
 *
 * @param string $content PHP code to check
 * @return array List of warning issues
 */
function findManualFkAssignments(string $content): array
{
    $issues = [];
    $reported = false;

    // Known FK columns that should use associations
    $knownFks = [
        'board_id', 'boardId', 'jiraboards_id',
        'job_id', 'jobId', 'aidevjobs_id',
        'repo_id', 'repoId', 'repoconnections_id',
        'member_id', 'memberId',
        'parent_id', 'parentId',
        'team_id', 'teamId',
        'task_id', 'taskId',
    ];

    // Pattern: $bean->something_id = or $bean->somethingId =
    $fkPatterns = [
        '/\$\w+->(\\w+_id)\s*=/',
        '/\$\w+->(\\w+Id)\s*=/',
    ];

    foreach ($fkPatterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $fkColumn) {
                $fkLower = strtolower($fkColumn);
                $isKnownFk = false;
                foreach ($knownFks as $known) {
                    if (strtolower($known) === $fkLower) {
                        $isKnownFk = true;
                        break;
                    }
                }

                if ($isKnownFk || preg_match('/_id$/i', $fkColumn) || preg_match('/Id$/', $fkColumn)) {
                    if (!$reported) {
                        $issues[] = "Manual FK assignment detected: \${$fkColumn}. "
                            . "Consider using RedBeanPHP associations instead: "
                            . "\$parent->ownChildList[] = \$child (auto-sets FK, lazy loading, cascade delete with xown). "
                            . "Use with(' ORDER BY col DESC ') for ordering, withCondition(' col = ? ', [\$val]) for filtering. "
                            . "See CLAUDE.md for examples.";
                        $reported = true;
                    }
                    break;
                }
            }
        }
        if ($reported) break;
    }

    // Detect find queries with FK WHERE clauses
    if (!$reported && preg_match("/(?:R|Bean)::(?:find|findOne|findAll)\s*\(\s*['\"](\w+)['\"],\s*['\"](\w+_id)\s*=/", $content, $match)) {
        $childTable = $match[1];
        $fkColumn = $match[2];

        $issues[] = "Manual FK query detected: Bean::find('{$childTable}', '{$fkColumn} = ?'). "
            . "Consider using associations: \$parent->own" . ucfirst($childTable) . "List (lazy loads, auto-cached). "
            . "For ordering: \$parent->with(' ORDER BY col DESC ')->own" . ucfirst($childTable) . "List. "
            . "See CLAUDE.md for examples.";
    }

    return $issues;
}

// =========================================
// Security Scanning (OWASP Top 10)
// =========================================

/**
 * Detect potential SQL injection vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Critical security issues
 */
function findSqlInjectionRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/(?:R|Bean)::exec\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/', 'Direct variable in exec() - Use parameterized queries: Bean::exec($sql, [$param])'],
        ['/(?:R|Bean)::(?:getAll|getRow|getCol|getCell)\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/', 'Direct variable in a raw SELECT - Use parameterized queries'],
        ['/->query\s*\(\s*["\'][^"\']*\$/', 'Direct variable in query() - Use parameterized queries'],
        ['/->exec\s*\(\s*["\'][^"\']*\$/', 'Direct variable in exec() - Use parameterized queries'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "SQL INJECTION RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect potential XSS vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findXssRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/echo\s+\$_(?:GET|POST|REQUEST|COOKIE)\[/', 'Direct echo of user input - Use htmlspecialchars($_GET["param"], ENT_QUOTES, "UTF-8")'],
        ['/print\s+\$_(?:GET|POST|REQUEST|COOKIE)\[/', 'Direct print of user input - Use htmlspecialchars()'],
        ['/<\?=\s*\$_(?:GET|POST|REQUEST)\[/', 'Direct output of user input in template - Use htmlspecialchars()'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "XSS RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect potential command injection vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Critical security issues
 */
function findCommandInjectionRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/exec\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in exec() - Use escapeshellarg() and escapeshellcmd()'],
        ['/shell_exec\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in shell_exec() - Use escapeshellarg()'],
        ['/system\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in system() - Use escapeshellarg()'],
        ['/passthru\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in passthru() - Use escapeshellarg()'],
        ['/proc_open\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in proc_open() - Use escapeshellarg()'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "COMMAND INJECTION RISK: {$message}";
        }
    }

    if (backtickExecUsesUserInput($content)) {
        $issues[] = 'COMMAND INJECTION RISK: User input in backtick operator - Avoid or use escapeshellarg()';
    }

    return $issues;
}

/**
 * Does the file actually SHELL OUT via backticks with request data inside?
 *
 * This used to be a regex over the raw text:
 *
 *     /`[^`]*\$_(?:GET|POST|REQUEST)/
 *
 * which matched a backtick ANYWHERE followed later by $_POST with no backtick
 * in between — hundreds of lines apart, in a different function, in a comment.
 * A docblock that quoted a config key in markdown backticks was therefore an
 * injection risk, and the write was BLOCKED. It matched the character, not the
 * code.
 *
 * PHP's own tokenizer knows the difference. Real backtick execution emits bare
 * '`' tokens; the same character inside a comment or a string literal never
 * does. So the only thing left to ask is whether a superglobal appears between
 * an opening and closing backtick.
 *
 * LEXED, not parsed. The first version of this asked TOKEN_PARSE, which validates
 * syntax and throws on anything that is not a complete program — so every Edit hunk
 * (a bare method, a dangling brace) fell straight back to the regex below, and the
 * markdown-backtick false positive this function exists to prevent came back for the
 * commonest case of all. Plain token_get_all still tells a shell-exec delimiter from
 * a backtick inside a comment, and never throws.
 *
 * The regex fallback remains for content the lexer cannot handle at all: a validator
 * that goes quiet on input it cannot read is worse than one that over-reports.
 */
function backtickExecUsesUserInput(string $content): bool
{
    if (strpos($content, '`') === false) {
        return false;   // nothing to weigh up
    }

    // An Edit hands us new_string — a FRAGMENT with no open tag. Without one the
    // tokenizer calls the whole thing T_INLINE_HTML, every backtick disappears
    // into it, and real shell-exec in an edited hunk sails through. phpParsable
    // adds the tag, and knows not to add a second one above a shebang.
    $source = phpParsable($content);

    try {
        $tokens = @token_get_all($source);
    } catch (\Throwable $e) {
        return (bool) preg_match('/`[^`]*\$_(?:GET|POST|REQUEST)/', $content);
    }
    if (!$tokens) {
        return (bool) preg_match('/`[^`]*\$_(?:GET|POST|REQUEST)/', $content);
    }

    $inShell = false;
    foreach ($tokens as $token) {
        // A bare '`' is the shell-exec delimiter. Comments and string literals
        // arrive as T_COMMENT / T_DOC_COMMENT / T_CONSTANT_ENCAPSED_STRING with
        // the character safely inside their text, never as a token of their own.
        if ($token === '`') {
            $inShell = !$inShell;
            continue;
        }
        if (!$inShell || !is_array($token)) {
            continue;
        }
        if ($token[0] === T_VARIABLE && preg_match('/^\$_(GET|POST|REQUEST)$/', $token[1])) {
            return true;
        }
    }

    return false;
}

/**
 * Detect potential path traversal vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Critical security issues
 */
function findPathTraversalRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/file_get_contents\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in file_get_contents() - Validate and sanitize file paths'],
        ['/include\s*\(?[^;)]*\$_(?:GET|POST|REQUEST)/', 'User input in include - This is extremely dangerous!'],
        ['/require\s*\(?[^;)]*\$_(?:GET|POST|REQUEST)/', 'User input in require - This is extremely dangerous!'],
        ['/fopen\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in fopen() - Validate and sanitize file paths'],
        ['/readfile\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in readfile() - Validate and sanitize file paths'],
        ['/file\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in file() - Validate and sanitize file paths'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "PATH TRAVERSAL RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect use of insecure cryptographic functions for passwords.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findInsecureCrypto(string $content): array
{
    $issues = [];

    if (preg_match('/md5\s*\([^)]*\$.*password/i', $content)) {
        $issues[] = "INSECURE CRYPTO: MD5 used for password - Use password_hash() instead";
    }

    if (preg_match('/sha1\s*\([^)]*\$.*password/i', $content)) {
        $issues[] = "INSECURE CRYPTO: SHA1 used for password - Use password_hash() instead";
    }

    return $issues;
}

/**
 * Detect hardcoded secrets/credentials.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findHardcodedSecrets(string $content): array
{
    $issues = [];

    $patterns = [
        ['/["\'](?:password|passwd|pwd)["\']?\s*(?:=>|=)\s*["\'][^"\']{8,}[\'"]/i', 'Possible hardcoded password - Use environment variables'],
        ['/["\']api[_-]?key["\']?\s*(?:=>|=)\s*["\'][a-zA-Z0-9]{20,}[\'"]/i', 'Possible hardcoded API key - Use environment variables'],
        ['/["\']secret[_-]?key["\']?\s*(?:=>|=)\s*["\'][^"\']{16,}[\'"]/i', 'Possible hardcoded secret - Use environment variables'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "HARDCODED SECRET: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect POST handlers without CSRF protection.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findCsrfMissing(string $content): array
{
    $issues = [];

    // Check for POST handlers
    $hasPostHandler = preg_match('/\$request->method\s*===?\s*["\']POST["\']/', $content)
        || preg_match('/\$_SERVER\[["\']REQUEST_METHOD["\']\]\s*===?\s*["\']POST["\']/', $content);

    if ($hasPostHandler) {
        // Check for CSRF validation
        if (!preg_match('/validateCSRF|csrf|_token/i', $content)) {
            $issues[] = "CSRF RISK: POST handler without CSRF token validation - Use \$this->validateCSRF()";
        }
    }

    return $issues;
}

/**
 * Detect potential open redirect vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findOpenRedirectRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/header\s*\(\s*["\']Location:\s*["\']?\s*\.\s*\$_(?:GET|POST|REQUEST)/', 'User input in redirect header - Validate redirect URLs'],
        ['/Flight::redirect\s*\(\s*\$_(?:GET|POST|REQUEST)/', 'User input in Flight::redirect() - Validate redirect URLs'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "OPEN REDIRECT RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Run all security validations.
 *
 * @param string $content PHP code to check
 * @return array [critical_issues, warning_issues]
 */
function findSecurityIssues(string $content): array
{
    $criticalIssues = [];
    $warningIssues = [];

    // Critical - these should block
    $criticalIssues = array_merge($criticalIssues, findSqlInjectionRisks($content));
    $criticalIssues = array_merge($criticalIssues, findCommandInjectionRisks($content));
    $criticalIssues = array_merge($criticalIssues, findPathTraversalRisks($content));

    // High/Medium - warn but allow
    $warningIssues = array_merge($warningIssues, findXssRisks($content));
    $warningIssues = array_merge($warningIssues, findCsrfMissing($content));
    $warningIssues = array_merge($warningIssues, findInsecureCrypto($content));
    $warningIssues = array_merge($warningIssues, findHardcodedSecrets($content));
    $warningIssues = array_merge($warningIssues, findOpenRedirectRisks($content));

    return [$criticalIssues, $warningIssues];
}

/**
 * Run all validations on PHP content.
 *
 * @param string $content  PHP code to check
 * @param string $filePath the file being written, so the R:: allowlist can apply
 * @return array [blocking_issues, warning_issues]
 */
function validatePhpCode(string $content, string $filePath = ''): array
{
    $blockingIssues = [];
    $warningIssues = [];

    // No open-tag test here, on purpose.
    //
    // This used to return early for any content without <?php or <?= unless it
    // also mentioned R:: or Bean::. An Edit passes new_string — a HUNK, which
    // almost never carries an open tag — so in practice every security check was
    // skipped for nearly every edit, and the validator only really ran on a Write
    // of a whole file. Editing shell-exec with $_GET into an existing file passed
    // silently; writing the same file wholesale was blocked.
    //
    // The tag test was also the wrong question to ask. main() has already
    // established this is a .php file by its path before calling us, so the
    // content IS PHP by definition — a fragment of it is still PHP.
    //
    // Nothing to say about nothing, though: an empty hunk (a pure deletion) has
    // no code to judge.
    if (trim($content) === '') {
        return [[], []];
    }

    // RedBeanPHP Convention Issues
    // Blocking - these will cause runtime errors, or silent staleness
    $blockingIssues = array_merge($blockingIssues, findUnderscoreTableNames($content));
    $blockingIssues = array_merge($blockingIssues, findRawRedbeanCalls($content, $filePath));

    // Warning - suggestions for better practices
    $warningIssues = array_merge($warningIssues, findExecUsage($content));
    $warningIssues = array_merge($warningIssues, findManualFkAssignments($content));

    // Security Scanning (OWASP Top 10)
    [$securityCritical, $securityWarnings] = findSecurityIssues($content);
    $blockingIssues = array_merge($blockingIssues, $securityCritical);
    $warningIssues = array_merge($warningIssues, $securityWarnings);

    return [$blockingIssues, $warningIssues];
}

/**
 * Main entry point
 */
function main(): void
{
    try {
        // Read input from stdin (JSON format from Claude Code)
        $inputJson = file_get_contents('php://stdin');
        $inputData = json_decode(($inputJson) ?? '', true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // If input isn't valid JSON, just pass through
            exit(0);
        }

        $toolName = $inputData['tool_name'] ?? '';
        $toolInput = $inputData['tool_input'] ?? [];

        // Only validate Write and Edit operations
        if (!in_array($toolName, ['Write', 'Edit'])) {
            exit(0);
        }

        // Get file path and content
        $filePath = $toolInput['file_path'] ?? '';

        // Only validate PHP files
        if (!str_ends_with($filePath, '.php')) {
            exit(0);
        }

        // Get the content being written/edited
        if ($toolName === 'Write') {
            $content = $toolInput['content'] ?? '';
        } elseif ($toolName === 'Edit') {
            $content = $toolInput['new_string'] ?? '';
        } else {
            exit(0);
        }

        // Run validations
        [$blockingIssues, $warningIssues] = validatePhpCode($content, $filePath);

        // Blocking issues - will prevent the operation
        if (!empty($blockingIssues)) {
            $feedback = "TIKNIX CODE STANDARDS VIOLATION (BLOCKING):\n\n";
            foreach ($blockingIssues as $i => $issue) {
                $feedback .= ($i + 1) . ". {$issue}\n";
            }
            $feedback .= "\nThese issues will cause runtime errors. Fix before proceeding.\n";
            $feedback .= "See CLAUDE.md for Tiknix coding standards.";

            echo json_encode([
                'decision' => 'block',
                'reason' => $feedback
            ]);
            exit(0);
        }

        // Warning issues - allow but inform
        if (!empty($warningIssues)) {
            $feedback = "TIKNIX BEST PRACTICES SUGGESTION:\n\n";
            foreach ($warningIssues as $i => $issue) {
                $feedback .= ($i + 1) . ". {$issue}\n";
            }
            $feedback .= "\nThese are suggestions for better code. Operation allowed.\n";
            $feedback .= "See CLAUDE.md for RedBeanPHP association patterns.";

            echo json_encode([
                'decision' => 'allow',
                'reason' => $feedback
            ]);
        }

        exit(0);

    } catch (Exception $e) {
        // Log error but don't block
        fwrite(STDERR, "Hook error: " . $e->getMessage() . "\n");
        exit(0);
    }
}

main();
