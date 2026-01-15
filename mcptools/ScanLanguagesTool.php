<?php
/**
 * Scan Languages MCP Tool
 *
 * Scans a repository to detect and catalog all programming languages used,
 * including file counts and percentage distribution.
 */

namespace app\mcptools;

use app\LanguageScanner;

class ScanLanguagesTool extends BaseTool {

    public static string $name = 'scan_languages';

    public static string $description = 'Scans a repository to detect and catalog all programming languages used in the codebase. Returns file counts, percentages, and file extensions for each language, ranked by prevalence. Excludes binary files and generated code.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to the repository to scan. Defaults to current working directory if not specified.'
            ],
            'format' => [
                'type' => 'string',
                'enum' => ['json', 'text'],
                'description' => 'Output format: "json" for structured data or "text" for human-readable summary. Default: json'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        $path = $args['path'] ?? getcwd();
        $format = $args['format'] ?? 'json';

        // Resolve relative paths
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }

        // Ensure path exists
        if (!is_dir($path)) {
            return json_encode([
                'success' => false,
                'error' => "Path does not exist or is not a directory: {$path}"
            ], JSON_PRETTY_PRINT);
        }

        $scanner = new LanguageScanner($path);
        $results = $scanner->scan();

        if ($format === 'text') {
            return $scanner->getSummary($results);
        }

        return json_encode($results, JSON_PRETTY_PRINT);
    }
}
