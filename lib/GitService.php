<?php
/**
 * Git Service
 *
 * Handles local git operations for workbench tasks.
 * Used for branch creation, checkout, and push operations.
 *
 * Adapted from myctobot GitOperations patterns.
 */

namespace app;

use \Flight as Flight;

class GitService {

    private string $repoPath;
    private ?object $logger = null;

    // Base path for project workspaces
    const PROJECTS_BASE = '/projects';

    /**
     * Create GitService for a repository
     *
     * @param string|null $repoPath Path to repository (defaults to project root)
     */
    public function __construct(?string $repoPath = null) {
        $this->repoPath = $repoPath ?? dirname(__DIR__);

        if (class_exists('\Flight') && Flight::has('log')) {
            $this->logger = Flight::get('log');
        }
    }

    /**
     * Get the projects base directory (absolute path)
     *
     * @return string Absolute path to projects folder
     */
    public static function getProjectsBasePath(): string {
        return dirname(__DIR__) . self::PROJECTS_BASE;
    }

    /**
     * Get workspace path for a task
     *
     * @param int $memberId Member ID
     * @param int $taskId Task ID
     * @return string Absolute path to workspace
     */
    public static function getWorkspacePath(int $memberId, int $taskId): string {
        return self::getProjectsBasePath() . "/{$memberId}/{$taskId}";
    }

    /**
     * Clone repository into a project workspace (shallow clone)
     *
     * @param int $memberId Member ID
     * @param int $taskId Task ID
     * @param string|null $remoteUrl Remote URL (defaults to origin of main repo)
     * @param string $baseBranch Branch to clone (defaults to 'main')
     * @return string Path to cloned workspace
     * @throws \Exception on failure
     */
    public function cloneToWorkspace(int $memberId, int $taskId, ?string $remoteUrl = null, string $baseBranch = 'main'): string {
        $workspacePath = self::getWorkspacePath($memberId, $taskId);

        // Get remote URL from main repo if not provided
        if (!$remoteUrl) {
            $remoteUrl = $this->getRemoteUrl();
        }

        $this->log("Cloning to workspace: {$workspacePath} (branch: {$baseBranch})");

        // Create parent directories
        $parentDir = dirname($workspacePath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        // Remove existing workspace if present
        if (is_dir($workspacePath)) {
            $this->removeDirectory($workspacePath);
        }

        // Shallow clone of specific branch
        $result = $this->executeInDir(
            sprintf(
                'git clone --depth 1 --branch %s %s %s 2>&1',
                escapeshellarg($baseBranch),
                escapeshellarg($remoteUrl),
                escapeshellarg($workspacePath)
            ),
            dirname($workspacePath)
        );

        if ($result['code'] !== 0) {
            throw new \Exception("Failed to clone repository: " . $result['output']);
        }

        // Update repoPath to the new workspace
        $this->repoPath = $workspacePath;

        // Copy main project's .claude and .mcp.json to workspace
        $this->copyClaudeFolder($workspacePath);
        $this->copyMcpConfig($workspacePath);

        $this->log("Workspace created at: {$workspacePath}");
        return $workspacePath;
    }

    /**
     * Copy main project's .claude folder to workspace
     *
     * Ensures workspace has current hooks configuration.
     * Hooks use TIKNIX_PROJECT_ROOT env var to find the main project.
     *
     * @param string $workspacePath Path to the workspace
     */
    private function copyClaudeFolder(string $workspacePath): void {
        $workspaceClaudeDir = $workspacePath . '/.claude';
        $mainClaudeDir = dirname(__DIR__) . '/.claude';

        if (!is_dir($mainClaudeDir)) {
            return;
        }

        // Remove workspace's .claude folder if it exists
        if (is_dir($workspaceClaudeDir)) {
            $this->removeDirectory($workspaceClaudeDir);
        } elseif (is_link($workspaceClaudeDir)) {
            unlink($workspaceClaudeDir);
        }

        // Copy main project's .claude to workspace
        mkdir($workspaceClaudeDir, 0755, true);
        foreach (glob($mainClaudeDir . '/*') as $file) {
            $dest = $workspaceClaudeDir . '/' . basename($file);
            if (is_file($file)) {
                copy($file, $dest);
            }
        }
        $this->log("Copied .claude from main project");
    }

    /**
     * Generate .mcp.json for workspace with valid MCP server configurations
     *
     * @param string $workspacePath Path to the workspace
     */
    private function copyMcpConfig(string $workspacePath): void {
        $workspaceMcpJson = $workspacePath . '/.mcp.json';

        // Generate a valid .mcp.json with known-good configurations
        // Only include servers that don't require user-specific authentication
        $mcpConfig = [
            'mcpServers' => [
                // Playwright for browser automation testing
                'playwright' => [
                    'command' => 'npx',
                    'args' => ['@playwright/mcp@latest', '--headless']
                ]
            ]
        ];

        file_put_contents(
            $workspaceMcpJson,
            json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $this->log("Generated .mcp.json for workspace");
    }

    /**
     * Remove a directory recursively
     *
     * @param string $dir Directory to remove
     */
    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Execute command in a specific directory
     *
     * @param string $command Command to execute
     * @param string $dir Directory to execute in
     * @return array ['code' => int, 'output' => string]
     */
    private function executeInDir(string $command, string $dir): array {
        $oldCwd = getcwd();
        chdir($dir);
        exec($command, $output, $code);
        chdir($oldCwd);

        return [
            'code' => $code,
            'output' => implode("\n", $output)
        ];
    }

    /**
     * Create and checkout a new branch
     *
     * @param string $branchName Name of the new branch
     * @param string $baseBranch Branch to create from (default: current branch)
     * @return bool Success
     * @throws \Exception on failure
     */
    public function createBranch(string $branchName, string $baseBranch = 'main'): bool {
        $this->log("Creating branch: {$branchName} from {$baseBranch}");

        // Fetch latest from remote
        $this->execute('git fetch origin 2>&1');

        // Checkout base branch and pull latest
        $result = $this->execute(sprintf(
            'git checkout %s 2>&1',
            escapeshellarg($baseBranch)
        ));

        if ($result['code'] !== 0) {
            throw new \Exception("Failed to checkout {$baseBranch}: " . $result['output']);
        }

        // Pull latest changes
        $this->execute(sprintf(
            'git pull origin %s 2>&1',
            escapeshellarg($baseBranch)
        ));

        // Create and checkout new branch
        $result = $this->execute(sprintf(
            'git checkout -b %s 2>&1',
            escapeshellarg($branchName)
        ));

        if ($result['code'] !== 0) {
            // Branch might already exist - try just checking it out
            $result = $this->execute(sprintf(
                'git checkout %s 2>&1',
                escapeshellarg($branchName)
            ));

            if ($result['code'] !== 0) {
                throw new \Exception("Failed to create/checkout branch {$branchName}: " . $result['output']);
            }
        }

        $this->log("Branch {$branchName} created and checked out");
        return true;
    }

    /**
     * Checkout an existing branch
     *
     * @param string $branchName Branch to checkout
     * @return bool Success
     * @throws \Exception on failure
     */
    public function checkout(string $branchName): bool {
        $result = $this->execute(sprintf(
            'git checkout %s 2>&1',
            escapeshellarg($branchName)
        ));

        if ($result['code'] !== 0) {
            throw new \Exception("Failed to checkout {$branchName}: " . $result['output']);
        }

        return true;
    }

    /**
     * Get current branch name
     *
     * @return string Current branch name
     */
    public function getCurrentBranch(): string {
        $result = $this->execute('git rev-parse --abbrev-ref HEAD 2>&1');

        if ($result['code'] !== 0) {
            throw new \Exception("Failed to get current branch: " . $result['output']);
        }

        return trim($result['output']);
    }

    /**
     * Check if a branch exists locally
     *
     * @param string $branchName Branch name to check
     * @return bool True if exists
     */
    public function branchExists(string $branchName): bool {
        $result = $this->execute(sprintf(
            'git rev-parse --verify %s 2>&1',
            escapeshellarg($branchName)
        ));

        return $result['code'] === 0;
    }

    /**
     * Check if a branch exists on remote
     *
     * @param string $branchName Branch name to check
     * @param string $remote Remote name (default: origin)
     * @return bool True if exists on remote
     */
    public function remoteBranchExists(string $branchName, string $remote = 'origin'): bool {
        $result = $this->execute(sprintf(
            'git ls-remote --heads %s %s 2>&1',
            escapeshellarg($remote),
            escapeshellarg($branchName)
        ));

        return $result['code'] === 0 && !empty(trim($result['output']));
    }

    /**
     * Get list of branches (local and remote)
     *
     * @param bool $includeRemote Include remote branches
     * @param string $remote Remote name for remote branches
     * @return array ['local' => [...], 'remote' => [...], 'all' => [...]]
     */
    public function getBranches(bool $includeRemote = true, string $remote = 'origin'): array {
        $branches = [
            'local' => [],
            'remote' => [],
            'all' => []
        ];

        // Fetch latest from remote first
        if ($includeRemote) {
            $this->execute('git fetch --prune 2>&1');
        }

        // Get local branches
        $result = $this->execute('git branch --format="%(refname:short)" 2>&1');
        if ($result['code'] === 0) {
            $branches['local'] = array_filter(array_map('trim', explode("\n", $result['output'])));
        }

        // Get remote branches
        if ($includeRemote) {
            $result = $this->execute(sprintf(
                'git branch -r --format="%%(refname:short)" 2>&1 | grep "^%s/" | sed "s|^%s/||"',
                $remote,
                $remote
            ));
            if ($result['code'] === 0) {
                $remoteBranches = array_filter(array_map('trim', explode("\n", $result['output'])));
                // Filter out HEAD
                $branches['remote'] = array_filter($remoteBranches, fn($b) => $b !== 'HEAD');
            }
        }

        // Combine and deduplicate
        $branches['all'] = array_unique(array_merge($branches['local'], $branches['remote']));
        sort($branches['all']);

        return $branches;
    }

    /**
     * Push branch to remote
     *
     * @param string $branchName Branch to push
     * @param string $remote Remote name (default: origin)
     * @param bool $setUpstream Set upstream tracking
     * @return bool Success
     * @throws \Exception on failure
     */
    public function push(string $branchName, string $remote = 'origin', bool $setUpstream = true): bool {
        $this->log("Pushing branch {$branchName} to {$remote}");

        $cmd = $setUpstream
            ? sprintf('git push -u %s %s 2>&1', escapeshellarg($remote), escapeshellarg($branchName))
            : sprintf('git push %s %s 2>&1', escapeshellarg($remote), escapeshellarg($branchName));

        $result = $this->execute($cmd);

        if ($result['code'] !== 0) {
            throw new \Exception("Failed to push {$branchName}: " . $result['output']);
        }

        $this->log("Branch {$branchName} pushed successfully");
        return true;
    }

    /**
     * Get list of changed files (staged and unstaged)
     *
     * @return array List of changed file paths
     */
    public function getChangedFiles(): array {
        $result = $this->execute('git status --porcelain 2>&1');

        if ($result['code'] !== 0) {
            return [];
        }

        $files = [];
        $lines = explode("\n", trim($result['output']));

        foreach ($lines as $line) {
            if (empty($line)) continue;
            // Status is first 2 chars, filename starts at position 3
            $files[] = substr($line, 3);
        }

        return $files;
    }

    /**
     * Check if there are uncommitted changes
     *
     * @return bool True if there are uncommitted changes
     */
    public function hasUncommittedChanges(): bool {
        $result = $this->execute('git status --porcelain 2>&1');
        return !empty(trim($result['output']));
    }

    /**
     * Get the last commit hash
     *
     * @param bool $short Return short hash (7 chars)
     * @return string Commit hash
     */
    public function getLastCommitHash(bool $short = false): string {
        $format = $short ? '--short' : '';
        $result = $this->execute("git rev-parse {$format} HEAD 2>&1");

        if ($result['code'] !== 0) {
            throw new \Exception("Failed to get commit hash: " . $result['output']);
        }

        return trim($result['output']);
    }

    /**
     * Get remote URL for origin
     *
     * @return string Remote URL
     */
    public function getRemoteUrl(): string {
        $result = $this->execute('git remote get-url origin 2>&1');

        if ($result['code'] !== 0) {
            throw new \Exception("Failed to get remote URL: " . $result['output']);
        }

        return trim($result['output']);
    }

    /**
     * Parse owner and repo from remote URL
     *
     * @return array ['owner' => string, 'repo' => string]
     */
    public function parseRemoteUrl(): array {
        $url = $this->getRemoteUrl();

        // Handle SSH format: git@github.com:owner/repo.git
        if (preg_match('/git@github\.com:([^\/]+)\/(.+?)(?:\.git)?$/', $url, $matches)) {
            return ['owner' => $matches[1], 'repo' => $matches[2]];
        }

        // Handle HTTPS format: https://github.com/owner/repo.git
        if (preg_match('/github\.com\/([^\/]+)\/(.+?)(?:\.git)?$/', $url, $matches)) {
            return ['owner' => $matches[1], 'repo' => $matches[2]];
        }

        throw new \Exception("Could not parse GitHub owner/repo from URL: {$url}");
    }

    /**
     * Sanitize a string for use in branch names
     *
     * @param string $input String to sanitize
     * @param int $maxLength Maximum length
     * @return string Sanitized string
     */
    public static function sanitizeForBranch(string $input, int $maxLength = 50): string {
        // Convert to lowercase
        $sanitized = strtolower($input);

        // Replace spaces and special chars with hyphens
        $sanitized = preg_replace('/[^a-z0-9]+/', '-', $sanitized);

        // Remove leading/trailing hyphens
        $sanitized = trim($sanitized, '-');

        // Truncate if too long
        if (strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
            $sanitized = rtrim($sanitized, '-');
        }

        return $sanitized ?: 'task';
    }

    /**
     * Generate branch name for a task
     *
     * @param string $username Member username
     * @param int $taskId Task ID
     * @param string $taskTitle Task title
     * @return string Branch name in format: feature/{member}-{task-id}/{sanitized-title}
     */
    public static function generateBranchName(string $username, int $taskId, string $taskTitle): string {
        $sanitizedUsername = self::sanitizeForBranch($username, 20);
        $sanitizedTitle = self::sanitizeForBranch($taskTitle, 40);

        return sprintf(
            'feature/%s-%d/%s',
            $sanitizedUsername,
            $taskId,
            $sanitizedTitle
        );
    }

    /**
     * Execute a git command
     *
     * @param string $command Command to execute
     * @return array ['code' => int, 'output' => string]
     */
    private function execute(string $command): array {
        $oldCwd = getcwd();
        chdir($this->repoPath);

        exec($command, $output, $code);

        chdir($oldCwd);

        return [
            'code' => $code,
            'output' => implode("\n", $output)
        ];
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     */
    private function log(string $message): void {
        if ($this->logger) {
            $this->logger->info("GitService: {$message}");
        }
    }
}
