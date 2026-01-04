<?php
/**
 * GitHub Service
 *
 * Handles GitHub API operations for workbench tasks:
 * - Creating pull requests
 * - Merging pull requests (squash merge)
 * - Adding PR comments
 *
 * Uses cURL for HTTP requests (no Guzzle dependency).
 */

namespace app;

use \Flight as Flight;

class GitHubService {

    private string $accessToken;
    private string $owner;
    private string $repo;
    private const API_BASE = 'https://api.github.com';

    /**
     * Create GitHubService for a repository
     *
     * @param string $accessToken GitHub personal access token or OAuth token
     * @param string $owner Repository owner
     * @param string $repo Repository name
     */
    public function __construct(string $accessToken, string $owner, string $repo) {
        $this->accessToken = $accessToken;
        $this->owner = $owner;
        $this->repo = $repo;
    }

    /**
     * Create from team settings
     *
     * @param object $team Team bean with github_token, github_owner, github_repo
     * @return self|null Null if GitHub not configured for team
     */
    public static function fromTeam(object $team): ?self {
        if (empty($team->githubToken) || empty($team->githubOwner) || empty($team->githubRepo)) {
            return null;
        }

        return new self($team->githubToken, $team->githubOwner, $team->githubRepo);
    }

    /**
     * Create from config (for personal tasks without team)
     *
     * @return self|null Null if GitHub not configured globally
     */
    public static function fromConfig(): ?self {
        $config = Flight::get('config');
        $token = $config['github']['token'] ?? null;
        $owner = $config['github']['owner'] ?? null;
        $repo = $config['github']['repo'] ?? null;

        if (!$token || !$owner || !$repo) {
            return null;
        }

        return new self($token, $owner, $repo);
    }

    /**
     * Create a pull request
     *
     * @param string $title PR title
     * @param string $body PR description (markdown)
     * @param string $head Branch with changes
     * @param string $base Branch to merge into (default: main)
     * @param bool $draft Create as draft PR
     * @return array Created PR data
     * @throws \Exception on API error
     */
    public function createPullRequest(
        string $title,
        string $body,
        string $head,
        string $base = 'main',
        bool $draft = false
    ): array {
        return $this->request('POST', "/repos/{$this->owner}/{$this->repo}/pulls", [
            'title' => $title,
            'body' => $body,
            'head' => $head,
            'base' => $base,
            'draft' => $draft,
        ]);
    }

    /**
     * Get a pull request
     *
     * @param int $prNumber PR number
     * @return array PR data
     */
    public function getPullRequest(int $prNumber): array {
        return $this->request('GET', "/repos/{$this->owner}/{$this->repo}/pulls/{$prNumber}");
    }

    /**
     * List open pull requests
     *
     * @param string $state Filter by state: open, closed, all
     * @param int $perPage Results per page
     * @return array List of PRs
     */
    public function listPullRequests(string $state = 'open', int $perPage = 30): array {
        return $this->request('GET', "/repos/{$this->owner}/{$this->repo}/pulls", null, [
            'state' => $state,
            'per_page' => min($perPage, 100),
        ]);
    }

    /**
     * Merge a pull request
     *
     * @param int $prNumber PR number
     * @param string $commitTitle Merge commit title (optional)
     * @param string $commitMessage Merge commit message (optional)
     * @param string $mergeMethod Merge method: merge, squash, rebase
     * @return array Merge result
     * @throws \Exception on merge failure
     */
    public function mergePullRequest(
        int $prNumber,
        string $commitTitle = '',
        string $commitMessage = '',
        string $mergeMethod = 'squash'
    ): array {
        $payload = [
            'merge_method' => $mergeMethod,
        ];

        if ($commitTitle) {
            $payload['commit_title'] = $commitTitle;
        }
        if ($commitMessage) {
            $payload['commit_message'] = $commitMessage;
        }

        return $this->request('PUT', "/repos/{$this->owner}/{$this->repo}/pulls/{$prNumber}/merge", $payload);
    }

    /**
     * Close a pull request without merging
     *
     * @param int $prNumber PR number
     * @return array Updated PR data
     */
    public function closePullRequest(int $prNumber): array {
        return $this->request('PATCH', "/repos/{$this->owner}/{$this->repo}/pulls/{$prNumber}", [
            'state' => 'closed',
        ]);
    }

    /**
     * Add a comment to a pull request
     *
     * @param int $prNumber PR number
     * @param string $body Comment body (markdown)
     * @return array Created comment
     */
    public function addComment(int $prNumber, string $body): array {
        return $this->request('POST', "/repos/{$this->owner}/{$this->repo}/issues/{$prNumber}/comments", [
            'body' => $body,
        ]);
    }

    /**
     * Check if a branch exists
     *
     * @param string $branch Branch name
     * @return bool
     */
    public function branchExists(string $branch): bool {
        try {
            $this->request('GET', "/repos/{$this->owner}/{$this->repo}/branches/{$branch}");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get repository info
     *
     * @return array Repository data
     */
    public function getRepository(): array {
        return $this->request('GET', "/repos/{$this->owner}/{$this->repo}");
    }

    /**
     * Get default branch name
     *
     * @return string Default branch (usually 'main' or 'master')
     */
    public function getDefaultBranch(): string {
        $repo = $this->getRepository();
        return $repo['default_branch'] ?? 'main';
    }

    /**
     * Build a PR body from task data
     *
     * @param array $task Task data
     * @param string $summary Completion summary
     * @return string Markdown PR body
     */
    public static function buildPRBody(array $task, string $summary = ''): string {
        $body = "## Summary\n\n";

        if ($summary) {
            $body .= $summary . "\n\n";
        } elseif (!empty($task['description'])) {
            $body .= $task['description'] . "\n\n";
        }

        if (!empty($task['acceptance_criteria'])) {
            $body .= "## Acceptance Criteria\n\n";
            $body .= $task['acceptance_criteria'] . "\n\n";
        }

        $body .= "---\n\n";
        $body .= "**Task ID**: #{$task['id']}\n";
        $body .= "**Type**: " . ucfirst($task['task_type'] ?? 'feature') . "\n";

        if (!empty($task['tags'])) {
            $tags = is_array($task['tags']) ? $task['tags'] : json_decode($task['tags'], true);
            if ($tags) {
                $body .= "**Tags**: " . implode(', ', $tags) . "\n";
            }
        }

        $body .= "\n---\n";
        $body .= "_Created via Tiknix Workbench_\n";

        return $body;
    }

    /**
     * Make an API request
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request body data
     * @param array $query Query parameters
     * @return array Response data
     * @throws \Exception on error
     */
    private function request(string $method, string $endpoint, ?array $data = null, array $query = []): array {
        $url = self::API_BASE . $endpoint;

        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: Tiknix-Workbench/1.0',
                'Content-Type: application/json',
            ],
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("GitHub API request failed: {$error}");
        }

        $result = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = $result['message'] ?? 'Unknown error';
            $errors = isset($result['errors']) ? json_encode($result['errors']) : '';
            throw new \Exception("GitHub API error ({$httpCode}): {$message} {$errors}");
        }

        return $result ?? [];
    }

    /**
     * Get owner
     */
    public function getOwner(): string {
        return $this->owner;
    }

    /**
     * Get repo
     */
    public function getRepo(): string {
        return $this->repo;
    }
}
