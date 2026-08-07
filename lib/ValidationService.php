<?php
/**
 * ValidationService - Code Validation & Security Scanning
 *
 * Provides comprehensive validation for PHP code including:
 * - PHP syntax checking
 * - Security scanning (OWASP Top 10)
 * - RedBeanPHP convention checking
 * - FlightPHP pattern validation
 */

namespace app;

class ValidationService {

    /**
     * The Bean:: surface, from lib/Bean.php. A raw R:: call to any of these has a
     * wrapper that should have been used instead; anything not listed (setup, close,
     * testConnection, getWriter, nuke) has no Bean:: equivalent and is left alone.
     */
    private const BEAN_WRAPPED = [
        'dispense', 'load', 'store', 'trash', 'trashAll',
        'findOne', 'find', 'findAll', 'count',
        'exec', 'getAll', 'getRow', 'getCol', 'getCell',
        'begin', 'commit', 'rollback',
        'freeze', 'inspect', 'addDatabase', 'selectDatabase', 'hasDatabase',
        'currentDatabaseKey', 'getDatabaseAdapter', 'normalize', 'genSlots',
    ];

    private string $projectRoot;

    /**
     * Where raw R:: is legitimate, per CLAUDE.md.
     *
     * Callers here pass a path RELATIVE to the project root (fullValidation strips it),
     * so the leading slash is added back before matching - without it "lib/Bean.php"
     * and "services/Schema/Seeds/01_Member.php" both fall through the allowlist and the
     * two files that MUST use raw R:: get reported as violations on every scan.
     */
    private function rawRedbeanAllowed(string $filePath): bool {
        $path = '/' . ltrim(str_replace('\\', '/', $filePath), '/');
        if (basename($path) === 'bootstrap.php') return true;
        if (basename($path) === 'Bean.php' && strpos($path, '/lib/') !== false) return true;
        return strpos($path, '/services/Schema/Seeds/') !== false;
    }

    /**
     * Code with comments and string literals removed, so a docblock that NAMES a raw
     * call is not mistaken for one. Lexed rather than parsed: token_get_all without
     * TOKEN_PARSE never throws, so a fragment still classifies correctly.
     */
    private function codeOnly(string $code): string {
        $source = preg_match('/^(#![^\n]*\n)?\s*<\?(php|=)?/', $code) ? $code : "<?php\n" . $code;
        try {
            $tokens = @token_get_all($source);
        } catch (\Throwable $e) {
            return $code;
        }
        if (!$tokens) return $code;

        $skip = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE];
        $out  = '';
        foreach ($tokens as $t) {
            if (is_array($t)) {
                $out .= in_array($t[0], $skip, true) ? ' ' : $t[1];
                continue;
            }
            $out .= $t;
        }
        return $out;
    }

    public function __construct(?string $projectRoot = null) {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__);
    }

    /**
     * Run full validation on a file or directory
     *
     * @param string $path File or directory path
     * @return array Validation results
     */
    public function fullValidation(string $path): array {
        $results = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'info' => []
        ];

        $files = $this->getPhpFiles($path);

        foreach ($files as $file) {
            // PHP Syntax
            $syntax = $this->validatePhpSyntax($file);
            if (!$syntax['valid']) {
                $results['valid'] = false;
                $results['errors'] = array_merge($results['errors'], $syntax['errors']);
            }

            // Read file content
            $content = file_get_contents($file);
            $relativePath = str_replace($this->projectRoot . '/', '', $file);

            // Security
            $security = $this->scanSecurity($content, $relativePath);
            if (!empty($security['critical'])) {
                $results['valid'] = false;
            }
            $results['errors'] = array_merge($results['errors'], $security['critical'] ?? []);
            $results['warnings'] = array_merge($results['warnings'], $security['high'] ?? []);
            $results['warnings'] = array_merge($results['warnings'], $security['medium'] ?? []);

            // RedBeanPHP
            $redbean = $this->checkRedBeanConventions($content, $relativePath);
            $results['errors'] = array_merge($results['errors'], $redbean['errors'] ?? []);
            $results['warnings'] = array_merge($results['warnings'], $redbean['warnings'] ?? []);

            // FlightPHP
            $flight = $this->checkFlightPhpPatterns($content, $relativePath);
            $results['warnings'] = array_merge($results['warnings'], $flight['warnings'] ?? []);
            $results['info'] = array_merge($results['info'], $flight['info'] ?? []);
        }

        return $results;
    }

    /**
     * Validate PHP syntax for a single file
     *
     * @param string $file File path
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validatePhpSyntax(string $file): array {
        if (!file_exists($file)) {
            return [
                'valid' => false,
                'errors' => ["File not found: {$file}"]
            ];
        }

        $cmd = sprintf('php -l %s 2>&1', escapeshellarg($file));
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            return ['valid' => true, 'errors' => []];
        }

        return [
            'valid' => false,
            'errors' => $output
        ];
    }

    /**
     * Validate PHP syntax for multiple files
     *
     * @param array $files File paths
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validatePhpSyntaxBulk(array $files): array {
        $allValid = true;
        $allErrors = [];

        foreach ($files as $file) {
            $result = $this->validatePhpSyntax($file);
            if (!$result['valid']) {
                $allValid = false;
                $allErrors = array_merge($allErrors, $result['errors']);
            }
        }

        return ['valid' => $allValid, 'errors' => $allErrors];
    }

    /**
     * Scan code for security issues (OWASP Top 10)
     *
     * @param string $code PHP code content
     * @param string $file File path for context
     * @return array Issues grouped by severity
     */
    public function scanSecurity(string $code, string $file = ''): array {
        $issues = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => []
        ];

        $issues['critical'] = array_merge(
            $issues['critical'],
            $this->scanSqlInjection($code, $file),
            $this->scanCommandInjection($code, $file)
        );

        $issues['high'] = array_merge(
            $issues['high'],
            $this->scanXss($code, $file),
            $this->scanCsrf($code, $file),
            $this->scanPathTraversal($code, $file)
        );

        $issues['medium'] = array_merge(
            $issues['medium'],
            $this->scanHardcodedSecrets($code, $file),
            $this->scanInsecureCrypto($code, $file),
            $this->scanOpenRedirect($code, $file)
        );

        return $issues;
    }

    /**
     * Scan for SQL injection vulnerabilities
     */
    public function scanSqlInjection(string $code, string $file = ''): array {
        $issues = [];

        // Direct variable in SQL
        $patterns = [
            '/(?:R|Bean)::exec\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/' => 'Direct variable in exec()',
            '/(?:R|Bean)::(?:getAll|getRow|getCol|getCell)\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/' => 'Direct variable in a raw SELECT',
            '/query\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/' => 'Direct variable in query()',
            '/\->exec\s*\(\s*["\'][^"\']*\$/' => 'Direct variable in exec()',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                $line = $this->getLineNumber($code, $matches[0][1]);
                $issues[] = "[{$file}:{$line}] SQL Injection risk: {$message}";
            }
        }

        return $issues;
    }

    /**
     * Scan for XSS vulnerabilities
     */
    public function scanXss(string $code, string $file = ''): array {
        $issues = [];

        $patterns = [
            '/echo\s+\$_(?:GET|POST|REQUEST|COOKIE)\[/' => 'Direct echo of user input',
            '/print\s+\$_(?:GET|POST|REQUEST|COOKIE)\[/' => 'Direct print of user input',
            '/<\?=\s*\$_(?:GET|POST|REQUEST)\[/' => 'Direct output of user input in template',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                $line = $this->getLineNumber($code, $matches[0][1]);
                $issues[] = "[{$file}:{$line}] XSS risk: {$message}. Use htmlspecialchars()";
            }
        }

        return $issues;
    }

    /**
     * Scan for missing CSRF protection
     */
    public function scanCsrf(string $code, string $file = ''): array {
        $issues = [];

        // Check for POST handlers without CSRF validation
        if (preg_match('/\$request->method\s*===?\s*[\'"]POST[\'"]/', $code) ||
            preg_match('/if\s*\(\s*\$_SERVER\[[\'"]REQUEST_METHOD[\'"]\]\s*===?\s*[\'"]POST[\'"]/', $code)) {

            if (!preg_match('/validateCSRF|csrf|_token/', $code)) {
                $issues[] = "[{$file}] CSRF risk: POST handler without CSRF token validation";
            }
        }

        return $issues;
    }

    /**
     * Scan for command injection
     */
    public function scanCommandInjection(string $code, string $file = ''): array {
        $issues = [];

        $patterns = [
            '/exec\s*\([^)]*\$_(?:GET|POST|REQUEST)/' => 'User input in exec()',
            '/shell_exec\s*\([^)]*\$_(?:GET|POST|REQUEST)/' => 'User input in shell_exec()',
            '/system\s*\([^)]*\$_(?:GET|POST|REQUEST)/' => 'User input in system()',
            '/passthru\s*\([^)]*\$_(?:GET|POST|REQUEST)/' => 'User input in passthru()',
            '/`[^`]*\$_(?:GET|POST|REQUEST)/' => 'User input in backtick operator',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                $line = $this->getLineNumber($code, $matches[0][1]);
                $issues[] = "[{$file}:{$line}] Command Injection risk: {$message}";
            }
        }

        return $issues;
    }

    /**
     * Scan for path traversal vulnerabilities
     */
    public function scanPathTraversal(string $code, string $file = ''): array {
        $issues = [];

        $patterns = [
            '/file_get_contents\s*\([^)]*\$_(?:GET|POST|REQUEST)/' => 'User input in file_get_contents()',
            '/include\s*\(?[^;)]*\$_(?:GET|POST|REQUEST)/' => 'User input in include',
            '/require\s*\(?[^;)]*\$_(?:GET|POST|REQUEST)/' => 'User input in require',
            '/fopen\s*\([^)]*\$_(?:GET|POST|REQUEST)/' => 'User input in fopen()',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                $line = $this->getLineNumber($code, $matches[0][1]);
                $issues[] = "[{$file}:{$line}] Path Traversal risk: {$message}";
            }
        }

        return $issues;
    }

    /**
     * Scan for hardcoded secrets
     */
    public function scanHardcodedSecrets(string $code, string $file = ''): array {
        $issues = [];

        $patterns = [
            '/[\'"](?:password|passwd|pwd)[\'"]?\s*(?:=>|=)\s*[\'"][^\'"]{8,}[\'"]/' => 'Possible hardcoded password',
            '/[\'"]api[_-]?key[\'"]?\s*(?:=>|=)\s*[\'"][a-zA-Z0-9]{20,}[\'"]/' => 'Possible hardcoded API key',
            '/[\'"]secret[_-]?key[\'"]?\s*(?:=>|=)\s*[\'"][^\'"]{16,}[\'"]/' => 'Possible hardcoded secret key',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                $line = $this->getLineNumber($code, $matches[0][1]);
                $issues[] = "[{$file}:{$line}] Hardcoded secret: {$message}. Use environment variables.";
            }
        }

        return $issues;
    }

    /**
     * Scan for insecure cryptography
     */
    public function scanInsecureCrypto(string $code, string $file = ''): array {
        $issues = [];

        if (preg_match('/md5\s*\([^)]*\$.*password/i', $code, $matches, PREG_OFFSET_CAPTURE)) {
            $line = $this->getLineNumber($code, $matches[0][1]);
            $issues[] = "[{$file}:{$line}] Insecure crypto: MD5 for password. Use password_hash()";
        }

        if (preg_match('/sha1\s*\([^)]*\$.*password/i', $code, $matches, PREG_OFFSET_CAPTURE)) {
            $line = $this->getLineNumber($code, $matches[0][1]);
            $issues[] = "[{$file}:{$line}] Insecure crypto: SHA1 for password. Use password_hash()";
        }

        return $issues;
    }

    /**
     * Scan for open redirect vulnerabilities
     */
    public function scanOpenRedirect(string $code, string $file = ''): array {
        $issues = [];

        $patterns = [
            '/header\s*\(\s*[\'"]Location:\s*[\'"]?\s*\.\s*\$_(?:GET|POST|REQUEST)/' => 'User input in redirect header',
            '/Flight::redirect\s*\(\s*\$_(?:GET|POST|REQUEST)/' => 'User input in Flight::redirect()',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                $line = $this->getLineNumber($code, $matches[0][1]);
                $issues[] = "[{$file}:{$line}] Open Redirect risk: {$message}";
            }
        }

        return $issues;
    }

    /**
     * Check RedBeanPHP conventions
     */
    public function checkRedBeanConventions(string $code, string $file = ''): array {
        $result = ['errors' => [], 'warnings' => []];

        // Raw R:: where Bean:: wraps it. This is the check that matters most in this
        // class: these tools are what a jailed build agent asks BEFORE it writes, so a
        // validator that still teaches the old way is how raw R:: gets reintroduced
        // faster than it can be swept out. Kept in step with
        // scripts/hooks/validate-tiknix-php.php, which enforces the same rule on
        // Write/Edit. Methods with no Bean:: equivalent (setup, close, testConnection)
        // are deliberately not flagged - blocking a call with no alternative just
        // teaches people to route around the validator.
        if (!$this->rawRedbeanAllowed($file)) {
            $seen = [];
            if (preg_match_all('/(?<![A-Za-z0-9_])R::([a-zA-Z]+)/', $this->codeOnly($code), $mm)) {
                foreach ($mm[1] as $method) {
                    if (!in_array($method, self::BEAN_WRAPPED, true) || isset($seen[$method])) continue;
                    $seen[$method] = true;
                    $result['errors'][] = "[{$file}] R::{$method}() bypasses the Bean wrapper. "
                        . "Use Bean::{$method}() instead (add: use app\\Bean;). Raw R:: skips the "
                        . "cached adapter's read-caching and write-busting, and skips bean-type "
                        . "normalization. Raw R:: is allowed ONLY in bootstrap.php and "
                        . "services/Schema/Seeds/*.php.";
                }
            }
        }

        // Check for R::dispense with invalid bean names
        if (preg_match_all('/R::dispense\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $code, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $beanName = $match[0];
                $offset = $match[1];

                if (preg_match('/[A-Z_]/', $beanName)) {
                    $line = $this->getLineNumber($code, $offset);
                    $normalized = strtolower(str_replace('_', '', $beanName));
                    $result['errors'][] = "[{$file}:{$line}] Invalid bean name '{$beanName}'. Use lowercase: '{$normalized}' or Bean::dispense()";
                }
            }
        }

        // Check for R::exec used for simple CRUD
        // Bean::exec too: the wrapper bypasses FUSE models identically, so checking
        // only R:: meant converting a file to the mandated Bean:: silenced the warning
        // about the thing that was actually wrong.
        if (preg_match('/(?:R|Bean)::exec\s*\(\s*[\'"](?:INSERT|UPDATE|DELETE)\s/i', $code, $matches, PREG_OFFSET_CAPTURE)) {
            $line = $this->getLineNumber($code, $matches[0][1]);
            $result['warnings'][] = "[{$file}:{$line}] Consider using bean operations instead of exec() for CRUD";
        }

        // Check for manual FK assignment
        if (preg_match('/\$\w+->(\w+)_id\s*=/', $code, $matches, PREG_OFFSET_CAPTURE)) {
            $line = $this->getLineNumber($code, $matches[0][1]);
            $result['warnings'][] = "[{$file}:{$line}] Consider using associations (ownXxxList) instead of manual FK assignment";
        }

        return $result;
    }

    /**
     * Check FlightPHP patterns
     */
    public function checkFlightPhpPatterns(string $code, string $file = ''): array {
        $result = ['warnings' => [], 'info' => []];

        // Check if controller extends Control
        if (preg_match('/class\s+\w+\s+(?!extends\s+Control)/m', $code) &&
            strpos($file, 'controls/') !== false &&
            strpos($file, 'BaseControls') === false) {
            $result['warnings'][] = "[{$file}] Controller should extend BaseControls\\Control";
        }

        // Check for direct $_GET/$_POST instead of getParam
        if (preg_match('/\$_(?:GET|POST|REQUEST)\[/', $code) &&
            strpos($file, 'controls/') !== false) {
            $result['info'][] = "[{$file}] Consider using \$this->getParam() instead of direct \$_GET/\$_POST";
        }

        return $result;
    }

    /**
     * Get PHP files from path
     */
    private function getPhpFiles(string $path): array {
        if (is_file($path)) {
            return pathinfo($path, PATHINFO_EXTENSION) === 'php' ? [$path] : [];
        }

        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Skip vendor directory
                if (strpos($file->getPathname(), '/vendor/') === false) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Get line number from offset
     */
    private function getLineNumber(string $content, int $offset): int {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }
}
