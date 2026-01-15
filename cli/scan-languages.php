#!/usr/bin/env php
<?php
/**
 * Language Scanner CLI
 *
 * Scans a repository to detect and catalog all programming languages used.
 *
 * Usage:
 *   php cli/scan-languages.php [path] [--format=json|text]
 *
 * Options:
 *   path       Path to repository (defaults to current directory)
 *   --format   Output format: json or text (default: text)
 *   --help     Show this help message
 *
 * Examples:
 *   php cli/scan-languages.php
 *   php cli/scan-languages.php /path/to/repo
 *   php cli/scan-languages.php . --format=json
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Find and load bootstrap
$bootstrapPath = dirname(__DIR__) . '/bootstrap.php';
if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
}

// Load the LanguageScanner class if not already loaded via bootstrap
require_once dirname(__DIR__) . '/lib/LanguageScanner.php';

use app\LanguageScanner;

/**
 * Parse command line arguments
 */
function parseArgs(array $argv): array {
    $args = [
        'path' => getcwd(),
        'format' => 'text',
        'help' => false,
    ];

    array_shift($argv); // Remove script name

    foreach ($argv as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $args['help'] = true;
        } elseif (str_starts_with($arg, '--format=')) {
            $args['format'] = substr($arg, 9);
        } elseif (!str_starts_with($arg, '-')) {
            $args['path'] = $arg;
        }
    }

    return $args;
}

/**
 * Show help message
 */
function showHelp(): void {
    echo <<<HELP
Language Scanner - Detect programming languages in a repository

Usage:
  php cli/scan-languages.php [path] [options]

Arguments:
  path              Path to repository (defaults to current directory)

Options:
  --format=FORMAT   Output format: json or text (default: text)
  --help, -h        Show this help message

Examples:
  php cli/scan-languages.php
  php cli/scan-languages.php /path/to/repo
  php cli/scan-languages.php . --format=json
  php cli/scan-languages.php --format=json > languages.json

Output includes:
  - Programming languages detected
  - File count and percentage for each language
  - Byte count and percentage for each language
  - File extensions associated with each language
  - Languages ranked by prevalence

Excluded from scan:
  - Binary files (images, audio, video, archives)
  - Generated files (*.min.js, *.bundle.js, lock files)
  - Common dependency directories (node_modules, vendor, etc.)

HELP;
}

// Main execution
$args = parseArgs($argv);

if ($args['help']) {
    showHelp();
    exit(0);
}

// Resolve path
$path = $args['path'];
if (!str_starts_with($path, '/')) {
    $path = getcwd() . '/' . $path;
}
$path = realpath($path);

if (!$path || !is_dir($path)) {
    fwrite(STDERR, "Error: Invalid path or directory does not exist: {$args['path']}\n");
    exit(1);
}

// Validate format
if (!in_array($args['format'], ['json', 'text'], true)) {
    fwrite(STDERR, "Error: Invalid format '{$args['format']}'. Use 'json' or 'text'.\n");
    exit(1);
}

// Run scanner
$scanner = new LanguageScanner($path);
$results = $scanner->scan();

// Output results
if ($args['format'] === 'json') {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
} else {
    echo $scanner->getSummary($results) . "\n";
}

// Exit with appropriate code
exit($results['success'] ? 0 : 1);
