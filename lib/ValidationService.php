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

    private string $projectRoot;

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
            '/R::exec\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/' => 'Direct variable in R::exec()',
            '/R::getAll\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/' => 'Direct variable in R::getAll()',
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
        if (preg_match('/R::exec\s*\(\s*[\'"](?:INSERT|UPDATE|DELETE)\s/i', $code, $matches, PREG_OFFSET_CAPTURE)) {
            $line = $this->getLineNumber($code, $matches[0][1]);
            $result['warnings'][] = "[{$file}:{$line}] Consider using bean operations instead of R::exec() for CRUD";
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
