<?php
/**
 * WorkspaceManager - Manages isolated project workspaces for workbench tasks
 *
 * Creates fully isolated environments for testing/development:
 * - Fresh SQLite database with admin user
 * - Config files updated for the workspace subdomain
 * - Vendor setup (autoload copied, packages symlinked or copied)
 *
 * Designed to be generic enough for non-tiknix projects.
 */

namespace app;

use RedBeanPHP\R as R;
use Flight;

class WorkspaceManager
{
    /** @var string Base directory for all workspaces */
    private string $workspaceRoot;

    /** @var string Path to the source project to clone from */
    private string $sourceProject;

    /** @var array Directories to exclude from cloning */
    private array $excludeDirs = [
        '.git',
        'node_modules',
        'vendor',
        '.claude',
        'projects',
        'log',
        'cache',
        'uploads',
    ];

    /** @var array Files to exclude from cloning */
    private array $excludeFiles = [
        '*.log',
        '*.db',
        '.env',
        '.env.local',
    ];

    public function __construct(?string $workspaceRoot = null, ?string $sourceProject = null)
    {
        // Default to /tmp for local dev, can be configured for production
        $this->workspaceRoot = $workspaceRoot ?? $this->getDefaultWorkspaceRoot();
        $this->sourceProject = $sourceProject ?? dirname(__DIR__);
    }

    /**
     * Get default workspace root based on environment
     */
    private function getDefaultWorkspaceRoot(): string
    {
        // Check for configured workspace root
        $config = Flight::get('config');
        if (!empty($config['workspaces']['root'])) {
            return $config['workspaces']['root'];
        }

        // Default to /tmp for development
        return '/tmp/tiknix-workspaces';
    }

    /**
     * Create a new isolated workspace for a task
     *
     * @param int $memberId Member who owns the task
     * @param int $taskId Task ID
     * @param string $proxyHash Hash for subdomain routing
     * @param string|null $branchName Git branch to checkout (if using git clone)
     * @param string|null $repoUrl Git repository URL (for non-local projects)
     * @return array Workspace info ['path' => string, 'subdomain' => string]
     */
    public function create(
        int $memberId,
        int $taskId,
        string $proxyHash,
        ?string $branchName = null,
        ?string $repoUrl = null
    ): array {
        $workspacePath = "{$this->workspaceRoot}/{$memberId}/{$taskId}";

        // Ensure workspace root exists
        if (!is_dir($this->workspaceRoot)) {
            mkdir($this->workspaceRoot, 0755, true);
        }

        // Clean up existing workspace if present
        if (is_dir($workspacePath)) {
            $this->destroy($workspacePath);
        }

        // Create workspace directory
        mkdir($workspacePath, 0755, true);

        // Clone the project
        if ($repoUrl) {
            $this->cloneFromGit($workspacePath, $repoUrl, $branchName);
        } else {
            $this->cloneFromLocal($workspacePath, $branchName);
        }

        // Build subdomain
        $baseUrl = Flight::get('baseurl') ?? 'https://localhost';
        $baseDomain = preg_replace('#^https?://#', '', $baseUrl);
        $subdomain = "{$proxyHash}.{$baseDomain}";

        // Setup the workspace
        $this->setupVendor($workspacePath);
        $this->updateConfig($workspacePath, $subdomain);
        $this->initDatabase($workspacePath);
        $this->createRequiredDirs($workspacePath);

        return [
            'path' => $workspacePath,
            'subdomain' => $subdomain,
            'baseurl' => "https://{$subdomain}",
        ];
    }

    /**
     * Initialize an existing workspace (e.g., after git clone)
     * Sets up database, config, and vendor for isolated testing
     *
     * @param string $workspacePath Path to existing workspace
     * @param string $proxyHash Hash for subdomain routing
     * @param bool $copyFullVendor If true, copy entire vendor (for remote)
     * @return array Workspace info ['subdomain' => string, 'baseurl' => string]
     */
    public function initialize(string $workspacePath, string $proxyHash, bool $copyFullVendor = false): array
    {
        if (!is_dir($workspacePath)) {
            throw new \RuntimeException("Workspace path does not exist: {$workspacePath}");
        }

        // Build subdomain
        $baseUrl = Flight::get('baseurl') ?? 'https://localhost';
        $baseDomain = preg_replace('#^https?://#', '', $baseUrl);
        $subdomain = "{$proxyHash}.{$baseDomain}";

        // Setup the workspace
        $this->setupVendor($workspacePath, $copyFullVendor);
        $this->updateConfig($workspacePath, $subdomain);
        $this->initDatabase($workspacePath);
        $this->createRequiredDirs($workspacePath);

        return [
            'subdomain' => $subdomain,
            'baseurl' => "https://{$subdomain}",
        ];
    }

    /**
     * Clone project from local source using rsync
     */
    private function cloneFromLocal(string $workspacePath, ?string $branchName = null): void
    {
        // Build exclude list for rsync
        $excludes = [];
        foreach ($this->excludeDirs as $dir) {
            $excludes[] = "--exclude='{$dir}'";
        }
        foreach ($this->excludeFiles as $file) {
            $excludes[] = "--exclude='{$file}'";
        }
        $excludeStr = implode(' ', $excludes);

        // Use rsync for efficient copying
        $cmd = "rsync -a {$excludeStr} " . escapeshellarg($this->sourceProject . '/') . " " . escapeshellarg($workspacePath . '/');
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Failed to clone project: " . implode("\n", $output));
        }

        // Initialize git in workspace if branch specified
        if ($branchName) {
            $this->initGitWorkspace($workspacePath, $branchName);
        }
    }

    /**
     * Clone project from git repository
     */
    private function cloneFromGit(string $workspacePath, string $repoUrl, ?string $branchName = null): void
    {
        $branchArg = $branchName ? "-b " . escapeshellarg($branchName) : "";
        $cmd = "git clone --depth 1 {$branchArg} " . escapeshellarg($repoUrl) . " " . escapeshellarg($workspacePath);

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Failed to clone repository: " . implode("\n", $output));
        }
    }

    /**
     * Initialize git in workspace and create branch
     */
    private function initGitWorkspace(string $workspacePath, string $branchName): void
    {
        $commands = [
            "cd " . escapeshellarg($workspacePath) . " && git init",
            "cd " . escapeshellarg($workspacePath) . " && git add -A",
            "cd " . escapeshellarg($workspacePath) . " && git commit -m 'Initial workspace setup'",
            "cd " . escapeshellarg($workspacePath) . " && git checkout -b " . escapeshellarg($branchName),
        ];

        foreach ($commands as $cmd) {
            exec($cmd . " 2>&1", $output, $returnCode);
        }
    }

    /**
     * Setup vendor directory - copies entire vendor for true isolation
     *
     * @param string $workspacePath Path to workspace
     * @param bool $runComposer If true, run composer install instead of copying
     */
    public function setupVendor(string $workspacePath, bool $runComposer = false): void
    {
        $sourceVendor = $this->sourceProject . '/vendor';
        $targetVendor = $workspacePath . '/vendor';

        if ($runComposer) {
            // Run composer install for completely clean vendor
            $cmd = "cd " . escapeshellarg($workspacePath) . " && composer install --no-dev -q 2>&1";
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                throw new \RuntimeException("Composer install failed: " . implode("\n", $output));
            }
            return;
        }

        if (!is_dir($sourceVendor)) {
            throw new \RuntimeException("Source vendor directory not found: {$sourceVendor}");
        }

        // Remove existing vendor if present (might be stale symlinks)
        if (is_dir($targetVendor) || is_link($targetVendor)) {
            exec("rm -rf " . escapeshellarg($targetVendor));
        }

        // Copy entire vendor directory for true isolation
        exec("cp -r " . escapeshellarg($sourceVendor) . " " . escapeshellarg($targetVendor));

        // Regenerate autoload for workspace paths
        $cmd = "cd " . escapeshellarg($workspacePath) . " && composer dump-autoload -q 2>&1";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            // Log warning but don't fail - autoload might still work
            error_log("Warning: composer dump-autoload failed in workspace: " . implode("\n", $output));
        }
    }

    /**
     * Update config.ini for the workspace
     */
    public function updateConfig(string $workspacePath, string $subdomain): void
    {
        $configPath = $workspacePath . '/conf/config.ini';
        $configExamplePath = $workspacePath . '/conf/config.ini.example';

        // If no config exists, try to copy from example
        if (!file_exists($configPath) && file_exists($configExamplePath)) {
            copy($configExamplePath, $configPath);
        }

        if (!file_exists($configPath)) {
            // Create minimal config for workspace
            $this->createMinimalConfig($configPath, $subdomain);
            return;
        }

        // Read and update existing config
        $config = file_get_contents($configPath);

        // Update baseurl
        $config = preg_replace(
            '/^baseurl\s*=\s*"[^"]*"/m',
            'baseurl = "https://' . $subdomain . '"',
            $config
        );

        // Ensure database path is local (not absolute path to main)
        $config = preg_replace(
            '/^path\s*=\s*".*tiknix\.db"/m',
            'path = "database/tiknix.db"',
            $config
        );

        // Set environment to development
        $config = preg_replace(
            '/^environment\s*=\s*"[^"]*"/m',
            'environment = "development"',
            $config
        );

        // Enable debug mode
        $config = preg_replace(
            '/^debug\s*=\s*(true|false)/m',
            'debug = true',
            $config
        );

        file_put_contents($configPath, $config);
    }

    /**
     * Create minimal config file for workspace
     */
    private function createMinimalConfig(string $configPath, string $subdomain): void
    {
        $config = <<<INI
; Tiknix Workspace Configuration
; Auto-generated for isolated testing

[app]
name = "Tiknix Workspace"
environment = "development"
debug = true
build_mode = true
session_name = "WORKSPACE_SESSION"
session_lifetime = 28800
baseurl = "https://{$subdomain}"
timezone = "UTC"

[database]
type = "sqlite"
path = "database/tiknix.db"

[logging]
level = "DEBUG"
file = "log/app.log"

[security]
csrf_enabled = false
password_min_length = 6

[features]
registration_enabled = true
INI;

        $configDir = dirname($configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        file_put_contents($configPath, $config);
    }

    /**
     * Initialize fresh SQLite database with admin user
     * Uses sqlite3 CLI directly (like install.sh) for reliability
     */
    public function initDatabase(string $workspacePath): void
    {
        $dbPath = $workspacePath . '/database/tiknix.db';
        $dbDir = dirname($dbPath);
        $schemaPath = $workspacePath . '/sql/schema.sql';

        // Ensure database directory exists
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        // Remove existing database (we want fresh)
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        // If schema file exists, use sqlite3 CLI directly (most reliable)
        if (file_exists($schemaPath)) {
            $this->loadSchemaWithSqlite3($dbPath, $schemaPath);
        } else {
            // Fall back to minimal database
            $this->createMinimalDatabase($dbPath);
        }

        // Set admin password to 'admin1234'
        $this->setAdminPassword($dbPath, 'admin1234');
    }

    /**
     * Load schema using sqlite3 CLI (matches install.sh approach)
     */
    private function loadSchemaWithSqlite3(string $dbPath, string $schemaPath): void
    {
        // Use sqlite3 CLI to load schema - this is what install.sh does
        $cmd = sprintf(
            'sqlite3 %s < %s 2>&1',
            escapeshellarg($dbPath),
            escapeshellarg($schemaPath)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            error_log("Schema load warning: " . implode("\n", $output));
            // Try PHP fallback
            $this->createMinimalDatabase($dbPath);
            return;
        }

        // Verify tables were created
        $verifyCmd = sprintf(
            "sqlite3 %s \"SELECT count(*) FROM sqlite_master WHERE type='table';\"",
            escapeshellarg($dbPath)
        );
        $tableCount = trim(shell_exec($verifyCmd) ?? '0');

        if ((int)$tableCount === 0) {
            error_log("No tables created from schema, using minimal database");
            unlink($dbPath);
            $this->createMinimalDatabase($dbPath);
        }
    }

    /**
     * Set admin password using PHP SQLite3
     */
    private function setAdminPassword(string $dbPath, string $password): void
    {
        // Generate password hash using PHP
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            $db = new \SQLite3($dbPath);
            $stmt = $db->prepare("UPDATE member SET password = :password WHERE username = 'admin'");
            $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
            $stmt->execute();
            $db->close();
        } catch (\Exception $e) {
            error_log("Failed to set admin password: " . $e->getMessage());
        }
    }

    /**
     * Create minimal SQLite database with admin user
     */
    private function createMinimalDatabase(string $dbPath): void
    {
        $db = new \SQLite3($dbPath);

        // Create member table
        $db->exec("
            CREATE TABLE IF NOT EXISTS member (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                username TEXT,
                first_name TEXT,
                last_name TEXT,
                level INTEGER DEFAULT 100,
                is_active INTEGER DEFAULT 1,
                created_at TEXT,
                updated_at TEXT
            )
        ");

        // Create admin user with password 'admin1234'
        $hashedPassword = password_hash('admin1234', PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare("
            INSERT INTO member (email, password, username, first_name, last_name, level, is_active, created_at, updated_at)
            VALUES (:email, :password, :username, :first_name, :last_name, :level, 1, :created_at, :updated_at)
        ");

        $stmt->bindValue(':email', 'admin@workspace.local', SQLITE3_TEXT);
        $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
        $stmt->bindValue(':username', 'admin', SQLITE3_TEXT);
        $stmt->bindValue(':first_name', 'Admin', SQLITE3_TEXT);
        $stmt->bindValue(':last_name', 'User', SQLITE3_TEXT);
        $stmt->bindValue(':level', 1, SQLITE3_INTEGER); // ROOT level
        $stmt->bindValue(':created_at', $now, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $now, SQLITE3_TEXT);
        $stmt->execute();

        $db->close();
    }

    /**
     * Create required directories in workspace
     */
    private function createRequiredDirs(string $workspacePath): void
    {
        $dirs = [
            'log',
            'cache',
            'uploads',
            'database',
        ];

        foreach ($dirs as $dir) {
            $path = $workspacePath . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }

        // Create .gitkeep files
        foreach ($dirs as $dir) {
            $keepFile = $workspacePath . '/' . $dir . '/.gitkeep';
            if (!file_exists($keepFile)) {
                touch($keepFile);
            }
        }
    }

    /**
     * Destroy a workspace and clean up all files
     */
    public function destroy(string $workspacePath): bool
    {
        if (!is_dir($workspacePath)) {
            return true;
        }

        // Safety check - only delete within workspace root
        $realPath = realpath($workspacePath);
        $realRoot = realpath($this->workspaceRoot);

        if ($realRoot && $realPath && strpos($realPath, $realRoot) !== 0) {
            throw new \RuntimeException("Refusing to delete path outside workspace root: {$workspacePath}");
        }

        // Remove directory recursively
        $cmd = "rm -rf " . escapeshellarg($workspacePath);
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Get workspace path for a task
     */
    public function getPath(int $memberId, int $taskId): string
    {
        return "{$this->workspaceRoot}/{$memberId}/{$taskId}";
    }

    /**
     * Check if workspace exists
     */
    public function exists(int $memberId, int $taskId): bool
    {
        return is_dir($this->getPath($memberId, $taskId));
    }

    /**
     * Get workspace root directory
     */
    public function getWorkspaceRoot(): string
    {
        return $this->workspaceRoot;
    }

    /**
     * Set workspace root directory
     */
    public function setWorkspaceRoot(string $root): void
    {
        $this->workspaceRoot = $root;
    }

    /**
     * Clean up old/orphaned workspaces
     *
     * @param int $maxAgeDays Maximum age in days before cleanup
     * @return array List of cleaned up paths
     */
    public function cleanupOld(int $maxAgeDays = 7): array
    {
        $cleaned = [];
        $cutoff = time() - ($maxAgeDays * 86400);

        if (!is_dir($this->workspaceRoot)) {
            return $cleaned;
        }

        // Find workspaces older than cutoff
        $memberDirs = glob($this->workspaceRoot . '/*', GLOB_ONLYDIR);

        foreach ($memberDirs as $memberDir) {
            $taskDirs = glob($memberDir . '/*', GLOB_ONLYDIR);

            foreach ($taskDirs as $taskDir) {
                $mtime = filemtime($taskDir);
                if ($mtime < $cutoff) {
                    if ($this->destroy($taskDir)) {
                        $cleaned[] = $taskDir;
                    }
                }
            }

            // Remove empty member directory
            if (is_dir($memberDir) && count(glob($memberDir . '/*')) === 0) {
                rmdir($memberDir);
            }
        }

        return $cleaned;
    }
}
