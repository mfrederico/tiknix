<?php
/**
 * Language Scanner
 *
 * Scans a repository to detect and catalog all programming languages used,
 * including file counts and percentage distribution.
 *
 * Features:
 * - Identifies programming languages by file extension
 * - Excludes binary files and generated code
 * - Ranks languages by prevalence
 * - Provides detailed statistics including file extensions
 */

namespace app;

class LanguageScanner {

    /**
     * Mapping of file extensions to language names
     */
    private const EXTENSION_MAP = [
        // PHP
        'php' => 'PHP',
        'phtml' => 'PHP',
        'php3' => 'PHP',
        'php4' => 'PHP',
        'php5' => 'PHP',
        'phps' => 'PHP',

        // JavaScript/TypeScript
        'js' => 'JavaScript',
        'jsx' => 'JavaScript',
        'mjs' => 'JavaScript',
        'cjs' => 'JavaScript',
        'ts' => 'TypeScript',
        'tsx' => 'TypeScript',

        // Web
        'html' => 'HTML',
        'htm' => 'HTML',
        'xhtml' => 'HTML',
        'css' => 'CSS',
        'scss' => 'SCSS',
        'sass' => 'Sass',
        'less' => 'Less',

        // Python
        'py' => 'Python',
        'pyw' => 'Python',
        'pyx' => 'Python',
        'pxd' => 'Python',

        // Ruby
        'rb' => 'Ruby',
        'rake' => 'Ruby',
        'gemspec' => 'Ruby',

        // Java/Kotlin
        'java' => 'Java',
        'kt' => 'Kotlin',
        'kts' => 'Kotlin',

        // C/C++
        'c' => 'C',
        'h' => 'C',
        'cpp' => 'C++',
        'cxx' => 'C++',
        'cc' => 'C++',
        'hpp' => 'C++',
        'hxx' => 'C++',

        // C#
        'cs' => 'C#',

        // Go
        'go' => 'Go',

        // Rust
        'rs' => 'Rust',

        // Swift
        'swift' => 'Swift',

        // Shell
        'sh' => 'Shell',
        'bash' => 'Shell',
        'zsh' => 'Shell',
        'fish' => 'Shell',

        // SQL
        'sql' => 'SQL',
        'mysql' => 'SQL',
        'pgsql' => 'SQL',

        // Data/Config
        'json' => 'JSON',
        'xml' => 'XML',
        'yaml' => 'YAML',
        'yml' => 'YAML',
        'toml' => 'TOML',
        'ini' => 'INI',
        'conf' => 'Config',

        // Documentation
        'md' => 'Markdown',
        'markdown' => 'Markdown',
        'rst' => 'reStructuredText',
        'txt' => 'Text',

        // Vue/Svelte
        'vue' => 'Vue',
        'svelte' => 'Svelte',

        // Other
        'lua' => 'Lua',
        'pl' => 'Perl',
        'pm' => 'Perl',
        'r' => 'R',
        'scala' => 'Scala',
        'groovy' => 'Groovy',
        'dart' => 'Dart',
        'elm' => 'Elm',
        'ex' => 'Elixir',
        'exs' => 'Elixir',
        'erl' => 'Erlang',
        'hrl' => 'Erlang',
        'hs' => 'Haskell',
        'lhs' => 'Haskell',
        'clj' => 'Clojure',
        'cljs' => 'ClojureScript',
        'coffee' => 'CoffeeScript',
        'graphql' => 'GraphQL',
        'gql' => 'GraphQL',
        'proto' => 'Protocol Buffers',
        'sol' => 'Solidity',
        'tf' => 'Terraform',
        'hcl' => 'HCL',
    ];

    /**
     * Directories to exclude from scanning
     */
    private const EXCLUDED_DIRS = [
        '.git',
        '.svn',
        '.hg',
        'node_modules',
        'vendor',
        'bower_components',
        '.idea',
        '.vscode',
        '__pycache__',
        '.cache',
        'dist',
        'build',
        'coverage',
        '.next',
        '.nuxt',
        'out',
        'target',
        'bin',
        'obj',
    ];

    /**
     * File patterns to exclude (generated/binary)
     */
    private const EXCLUDED_PATTERNS = [
        '/\.min\.(js|css)$/',
        '/\.bundle\.(js|css)$/',
        '/\.lock$/',
        '/\.map$/',
        '/\.wasm$/',
        '/\.pyc$/',
        '/\.pyo$/',
        '/\.class$/',
        '/\.o$/',
        '/\.obj$/',
        '/\.dll$/',
        '/\.so$/',
        '/\.dylib$/',
        '/\.exe$/',
        '/\.bin$/',
        '/\.dat$/',
        '/\.db$/',
        '/\.sqlite$/',
        '/\.log$/',
        '/\.bak$/',
        '/\.swp$/',
        '/\.swo$/',
        '/~$/',
        '/package-lock\.json$/',
        '/yarn\.lock$/',
        '/composer\.lock$/',
        '/Gemfile\.lock$/',
        '/Cargo\.lock$/',
    ];

    /**
     * Binary file extensions to exclude
     */
    private const BINARY_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'bmp', 'ico', 'svg', 'webp',
        'mp3', 'mp4', 'avi', 'mov', 'mkv', 'webm', 'wav', 'flac',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'tar', 'gz', 'bz2', 'xz', '7z', 'rar',
        'ttf', 'otf', 'woff', 'woff2', 'eot',
        'exe', 'dll', 'so', 'dylib', 'bin',
    ];

    private string $repoPath;
    private array $results = [];
    private array $errors = [];

    /**
     * Create a LanguageScanner for a repository
     *
     * @param string $repoPath Path to the repository to scan
     */
    public function __construct(string $repoPath) {
        $this->repoPath = rtrim($repoPath, '/');
    }

    /**
     * Scan the repository and return language statistics
     *
     * @return array Scan results with language statistics
     */
    public function scan(): array {
        $this->results = [];
        $this->errors = [];

        if (!is_dir($this->repoPath)) {
            return [
                'success' => false,
                'error' => "Repository path does not exist: {$this->repoPath}",
                'languages' => [],
            ];
        }

        $this->scanDirectory($this->repoPath);

        return $this->buildResults();
    }

    /**
     * Recursively scan a directory
     *
     * @param string $dir Directory to scan
     */
    private function scanDirectory(string $dir): void {
        $items = @scandir($dir);
        if ($items === false) {
            $this->errors[] = "Cannot read directory: {$dir}";
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                if ($this->shouldExcludeDir($item)) {
                    continue;
                }
                $this->scanDirectory($path);
            } elseif (is_file($path)) {
                $this->processFile($path);
            }
        }
    }

    /**
     * Check if a directory should be excluded
     *
     * @param string $dirName Directory name
     * @return bool True if should be excluded
     */
    private function shouldExcludeDir(string $dirName): bool {
        return in_array($dirName, self::EXCLUDED_DIRS, true);
    }

    /**
     * Process a single file
     *
     * @param string $filePath Full path to file
     */
    private function processFile(string $filePath): void {
        $fileName = basename($filePath);
        $extension = $this->getExtension($fileName);

        // Skip files without extensions
        if (empty($extension)) {
            return;
        }

        // Skip binary files
        if (in_array($extension, self::BINARY_EXTENSIONS, true)) {
            return;
        }

        // Skip files matching excluded patterns
        if ($this->matchesExcludedPattern($fileName)) {
            return;
        }

        // Get the language for this extension
        $language = self::EXTENSION_MAP[$extension] ?? null;

        if ($language === null) {
            return;
        }

        // Initialize language entry if needed
        if (!isset($this->results[$language])) {
            $this->results[$language] = [
                'files' => 0,
                'bytes' => 0,
                'extensions' => [],
            ];
        }

        // Update counts
        $this->results[$language]['files']++;
        $this->results[$language]['bytes'] += filesize($filePath) ?: 0;

        // Track extensions used
        if (!in_array($extension, $this->results[$language]['extensions'], true)) {
            $this->results[$language]['extensions'][] = $extension;
        }
    }

    /**
     * Get file extension (lowercase)
     *
     * @param string $fileName File name
     * @return string Extension or empty string
     */
    private function getExtension(string $fileName): string {
        $pos = strrpos($fileName, '.');
        if ($pos === false || $pos === 0) {
            return '';
        }
        return strtolower(substr($fileName, $pos + 1));
    }

    /**
     * Check if a filename matches any excluded pattern
     *
     * @param string $fileName File name
     * @return bool True if matches excluded pattern
     */
    private function matchesExcludedPattern(string $fileName): bool {
        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (preg_match($pattern, $fileName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the final results array
     *
     * @return array Formatted results
     */
    private function buildResults(): array {
        if (empty($this->results)) {
            return [
                'success' => true,
                'repository' => $this->repoPath,
                'totalFiles' => 0,
                'totalBytes' => 0,
                'languages' => [],
                'errors' => $this->errors,
            ];
        }

        // Calculate totals
        $totalFiles = 0;
        $totalBytes = 0;
        foreach ($this->results as $langData) {
            $totalFiles += $langData['files'];
            $totalBytes += $langData['bytes'];
        }

        // Build language list with percentages
        $languages = [];
        foreach ($this->results as $language => $data) {
            $filePercent = $totalFiles > 0
                ? round(($data['files'] / $totalFiles) * 100, 2)
                : 0;

            $bytePercent = $totalBytes > 0
                ? round(($data['bytes'] / $totalBytes) * 100, 2)
                : 0;

            sort($data['extensions']);

            $languages[] = [
                'language' => $language,
                'files' => $data['files'],
                'bytes' => $data['bytes'],
                'bytesFormatted' => $this->formatBytes($data['bytes']),
                'filePercent' => $filePercent,
                'bytePercent' => $bytePercent,
                'extensions' => $data['extensions'],
            ];
        }

        // Sort by file count (descending)
        usort($languages, function ($a, $b) {
            return $b['files'] <=> $a['files'];
        });

        // Add rank
        $rank = 1;
        foreach ($languages as &$lang) {
            $lang['rank'] = $rank++;
        }
        unset($lang);

        return [
            'success' => true,
            'repository' => $this->repoPath,
            'totalFiles' => $totalFiles,
            'totalBytes' => $totalBytes,
            'totalBytesFormatted' => $this->formatBytes($totalBytes),
            'languageCount' => count($languages),
            'languages' => $languages,
            'errors' => $this->errors,
        ];
    }

    /**
     * Format bytes to human-readable string
     *
     * @param int $bytes Byte count
     * @return string Formatted string
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 0;

        while ($bytes >= 1024 && $factor < count($units) - 1) {
            $bytes /= 1024;
            $factor++;
        }

        return round($bytes, 2) . ' ' . $units[$factor];
    }

    /**
     * Get a text summary of the scan results
     *
     * @param array|null $results Scan results (or null to use last scan)
     * @return string Text summary
     */
    public function getSummary(?array $results = null): string {
        if ($results === null) {
            $results = $this->scan();
        }

        if (!$results['success']) {
            return "Scan failed: " . ($results['error'] ?? 'Unknown error');
        }

        if (empty($results['languages'])) {
            return "No programming languages detected in repository.";
        }

        $lines = [];
        $lines[] = "Language Scan Results";
        $lines[] = "=====================";
        $lines[] = "";
        $lines[] = "Repository: " . $results['repository'];
        $lines[] = "Total Files: " . number_format($results['totalFiles']);
        $lines[] = "Total Size: " . $results['totalBytesFormatted'];
        $lines[] = "Languages Found: " . $results['languageCount'];
        $lines[] = "";
        $lines[] = "Languages by Prevalence:";
        $lines[] = str_repeat("-", 70);
        $lines[] = sprintf(
            "%-4s %-20s %8s %8s %10s  %s",
            "Rank", "Language", "Files", "(%)", "Size", "Extensions"
        );
        $lines[] = str_repeat("-", 70);

        foreach ($results['languages'] as $lang) {
            $extensions = implode(', ', array_map(fn($e) => ".{$e}", $lang['extensions']));
            $lines[] = sprintf(
                "%-4d %-20s %8d %7.1f%% %10s  %s",
                $lang['rank'],
                $lang['language'],
                $lang['files'],
                $lang['filePercent'],
                $lang['bytesFormatted'],
                $extensions
            );
        }

        $lines[] = str_repeat("-", 70);

        if (!empty($results['errors'])) {
            $lines[] = "";
            $lines[] = "Warnings:";
            foreach ($results['errors'] as $error) {
                $lines[] = "  - " . $error;
            }
        }

        return implode("\n", $lines);
    }
}
