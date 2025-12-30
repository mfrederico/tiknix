<?php
namespace app\mcptools;

class SecurityScanTool extends BaseTool {

    public static string $name = 'security_scan';

    public static string $description = 'Scan PHP code for security vulnerabilities (OWASP Top 10). Returns issues grouped by severity.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to PHP file or directory to scan'
            ],
            'code' => [
                'type' => 'string',
                'description' => 'PHP code to scan directly (alternative to path)'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        $path = $args['path'] ?? null;
        $code = $args['code'] ?? null;

        if (!$path && !$code) {
            throw new \Exception("Either 'path' or 'code' is required");
        }

        $projectRoot = \Flight::get('project_root') ?? dirname(dirname(__DIR__));
        $validator = new \app\ValidationService($projectRoot);

        if ($path) {
            $fullPath = $this->resolvePath($path, $projectRoot);
            if (!file_exists($fullPath)) {
                throw new \Exception("File not found: {$path}");
            }
            $code = file_get_contents($fullPath);
        }

        $issues = $validator->scanSecurity($code, $path ?? 'inline');

        $totalIssues = count($issues['critical'] ?? [])
            + count($issues['high'] ?? [])
            + count($issues['medium'] ?? [])
            + count($issues['low'] ?? []);

        return json_encode([
            'path' => $path ?? 'inline',
            'has_issues' => $totalIssues > 0,
            'issue_count' => $totalIssues,
            'issues' => $issues
        ], JSON_PRETTY_PRINT);
    }

    private function resolvePath(string $path, string $projectRoot): string {
        if (strpos($path, '/') === 0) {
            return $path;
        }
        return $projectRoot . '/' . ltrim($path, './');
    }
}
