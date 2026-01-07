<?php
namespace app;

/**
 * PHP code validation service
 *
 * Provides syntax checking, structure validation, and security scanning
 * for PHP files (MCP tools and hooks).
 */
class PhpValidator
{
    /**
     * Dangerous function patterns to warn about
     */
    private const DANGEROUS_FUNCTIONS = [
        'eval' => 'Code execution via eval() - extremely dangerous',
        'exec' => 'Shell command execution',
        'shell_exec' => 'Shell command execution',
        'system' => 'Shell command execution',
        'passthru' => 'Shell command execution',
        'popen' => 'Process execution',
        'proc_open' => 'Process execution',
        'pcntl_exec' => 'Process execution',
        'assert' => 'Can execute code if string passed',
        'create_function' => 'Dynamic function creation (deprecated)',
        'call_user_func' => 'Dynamic function call - verify source',
        'call_user_func_array' => 'Dynamic function call - verify source',
        'preg_replace' => 'Can execute code with /e modifier (deprecated)',
    ];

    /**
     * File operation functions to flag
     */
    private const FILE_FUNCTIONS = [
        'file_get_contents' => 'File/URL read - verify source is trusted',
        'file_put_contents' => 'File write - verify path is safe',
        'fopen' => 'File operation - verify path is safe',
        'fwrite' => 'File write operation',
        'unlink' => 'File deletion',
        'rmdir' => 'Directory deletion',
        'rename' => 'File/directory rename',
        'copy' => 'File copy operation',
        'move_uploaded_file' => 'File upload handling',
    ];

    /**
     * Check PHP syntax using php -l
     *
     * @param string $code PHP code to validate
     * @return array ['valid' => bool, 'errors' => array of error messages]
     */
    public static function checkSyntax(string $code): array
    {
        // Write to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'php_validate_');
        file_put_contents($tempFile, $code);

        // Run php -l
        $output = [];
        $returnCode = 0;
        exec('php -l ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnCode);

        // Clean up
        unlink($tempFile);

        // Parse output
        $errors = [];
        foreach ($output as $line) {
            $line = trim($line);
            // Skip "No syntax errors" message
            if (stripos($line, 'No syntax errors') !== false) {
                continue;
            }
            // Skip empty lines
            if (empty($line)) {
                continue;
            }
            // Replace temp filename with generic reference
            $line = preg_replace('/in \/tmp\/php_validate_[a-zA-Z0-9]+/', 'in code', $line);
            $errors[] = $line;
        }

        return [
            'valid' => $returnCode === 0,
            'errors' => $errors
        ];
    }

    /**
     * Validate that code defines a proper MCP tool class
     *
     * @param string $code PHP code to validate
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public static function validateToolClass(string $code): array
    {
        $errors = [];
        $warnings = [];

        // Note: Syntax check is done by validateAll() before calling this method

        // Check for namespace
        if (!preg_match('/namespace\s+app\\\\mcptools/i', $code)) {
            $errors[] = 'Missing or incorrect namespace. Expected: namespace app\mcptools;';
        }

        // Check for class extending BaseTool
        if (!preg_match('/class\s+\w+Tool\s+extends\s+BaseTool/i', $code)) {
            $errors[] = 'Class must extend BaseTool and be named *Tool (e.g., MyFeatureTool)';
        }

        // Check for required static properties
        $requiredProps = ['$name', '$description', '$inputSchema'];
        foreach ($requiredProps as $prop) {
            if (!preg_match('/public\s+static\s+\w*\s*' . preg_quote($prop, '/') . '/i', $code)) {
                $errors[] = "Missing required property: public static {$prop}";
            }
        }

        // Check for execute method
        if (!preg_match('/public\s+function\s+execute\s*\(\s*array\s+\$\w+\s*\)/i', $code)) {
            $errors[] = 'Missing required method: public function execute(array $args): string';
        }

        // Check $name is not empty
        if (preg_match('/\$name\s*=\s*[\'"][\'"]\s*;/', $code)) {
            $errors[] = 'Tool $name cannot be empty';
        }

        // Check for return type hint on execute (warning, not error)
        if (!preg_match('/function\s+execute\s*\([^)]*\)\s*:\s*string/i', $code)) {
            $warnings[] = 'execute() method should have return type: string';
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate hook script structure
     *
     * @param string $code PHP code to validate
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public static function validateHookScript(string $code): array
    {
        $errors = [];
        $warnings = [];

        // Note: Syntax check is done by validateAll() before calling this method

        // Check for PHP opening tag (allow shebang before it)
        if (!preg_match('/^(#![^\n]*\n)?<\?php/i', trim($code))) {
            $errors[] = 'Hook must start with <?php (shebang line allowed before it)';
        }

        // Check for stdin reading (recommended but not required)
        if (strpos($code, 'php://stdin') === false) {
            $warnings[] = 'Hook typically reads input from php://stdin';
        }

        // Check for exit statement (recommended)
        if (!preg_match('/exit\s*\(\s*\d*\s*\)/i', $code)) {
            $warnings[] = 'Hook should have explicit exit() with status code';
        }

        // Check for doc block with hook metadata (recommended)
        if (!preg_match('/\/\*\*.*?Hook:.*?\*\//s', $code)) {
            $warnings[] = 'Consider adding doc block with Hook: name, Event: type, Matcher: pattern';
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Scan code for security concerns
     *
     * @param string $code PHP code to scan
     * @return array ['issues' => array of ['severity' => 'warning'|'danger', 'message' => string, 'line' => int|null]]
     */
    public static function scanSecurity(string $code): array
    {
        $issues = [];
        $lines = explode("\n", $code);

        foreach ($lines as $lineNum => $line) {
            $lineNumber = $lineNum + 1;

            // Check for dangerous functions
            foreach (self::DANGEROUS_FUNCTIONS as $func => $description) {
                // Match function calls, avoiding false positives in strings/comments
                if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $line)) {
                    // Skip if in a comment
                    $trimmed = ltrim($line);
                    if (strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0 || strpos($trimmed, '#') === 0) {
                        continue;
                    }
                    $issues[] = [
                        'severity' => 'danger',
                        'message' => "{$func}(): {$description}",
                        'line' => $lineNumber
                    ];
                }
            }

            // Check for file functions (warning level)
            foreach (self::FILE_FUNCTIONS as $func => $description) {
                if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $line)) {
                    $trimmed = ltrim($line);
                    if (strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0 || strpos($trimmed, '#') === 0) {
                        continue;
                    }
                    $issues[] = [
                        'severity' => 'warning',
                        'message' => "{$func}(): {$description}",
                        'line' => $lineNumber
                    ];
                }
            }

            // Check for SQL injection patterns
            if (preg_match('/\$_(GET|POST|REQUEST|COOKIE)\s*\[.*?\].*?(SELECT|INSERT|UPDATE|DELETE|DROP)/i', $line)) {
                $issues[] = [
                    'severity' => 'danger',
                    'message' => 'Potential SQL injection: user input directly in SQL query',
                    'line' => $lineNumber
                ];
            }

            // Check for hardcoded credentials
            if (preg_match('/(password|secret|api_key|apikey|token)\s*=\s*[\'"][^\'"]+[\'"]/i', $line)) {
                // Skip if it looks like a variable assignment or placeholder
                if (!preg_match('/\$|getenv|env\(|config\(|\{\{/i', $line)) {
                    $issues[] = [
                        'severity' => 'warning',
                        'message' => 'Possible hardcoded credential or secret',
                        'line' => $lineNumber
                    ];
                }
            }

            // Check for remote URL in file operations
            if (preg_match('/file_get_contents\s*\(\s*[\'"]https?:\/\//i', $line)) {
                $issues[] = [
                    'severity' => 'warning',
                    'message' => 'Remote URL fetch - ensure source is trusted',
                    'line' => $lineNumber
                ];
            }

            // Check for unserialize with user input
            if (preg_match('/unserialize\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', $line)) {
                $issues[] = [
                    'severity' => 'danger',
                    'message' => 'unserialize() with user input - object injection vulnerability',
                    'line' => $lineNumber
                ];
            }

            // Check for include/require with variables
            if (preg_match('/(include|require|include_once|require_once)\s*\(\s*\$/i', $line)) {
                $issues[] = [
                    'severity' => 'danger',
                    'message' => 'Dynamic include/require - potential local file inclusion',
                    'line' => $lineNumber
                ];
            }
        }

        return ['issues' => $issues];
    }

    /**
     * Run all validations and return combined result
     *
     * @param string $code PHP code
     * @param string $type 'tool' or 'hook'
     * @return array ['valid' => bool, 'syntax' => array, 'structure' => array, 'security' => array]
     */
    public static function validateAll(string $code, string $type = 'tool'): array
    {
        $syntaxResult = self::checkSyntax($code);

        if ($type === 'tool') {
            $structureResult = self::validateToolClass($code);
        } else {
            $structureResult = self::validateHookScript($code);
        }

        $securityResult = self::scanSecurity($code);

        // Consider invalid if syntax fails or structure has errors
        $valid = $syntaxResult['valid'] && $structureResult['valid'];

        return [
            'valid' => $valid,
            'syntax' => $syntaxResult,
            'structure' => $structureResult,
            'security' => $securityResult
        ];
    }

    /**
     * Get tool metadata from code without executing it
     *
     * @param string $code PHP code
     * @return array|null ['name' => string, 'description' => string, 'className' => string] or null if can't parse
     */
    public static function extractToolMetadata(string $code): ?array
    {
        $result = [];

        // Extract class name
        if (preg_match('/class\s+(\w+Tool)\s+extends/i', $code, $matches)) {
            $result['className'] = $matches[1];
        } else {
            return null;
        }

        // Extract $name value
        if (preg_match('/\$name\s*=\s*[\'"]([^\'"]+)[\'"]/i', $code, $matches)) {
            $result['name'] = $matches[1];
        } else {
            $result['name'] = '';
        }

        // Extract $description value
        if (preg_match('/\$description\s*=\s*[\'"]([^\'"]+)[\'"]/i', $code, $matches)) {
            $result['description'] = $matches[1];
        } else {
            $result['description'] = '';
        }

        return $result;
    }
}
