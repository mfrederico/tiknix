<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;
use \app\GitHubService;

class CompleteTaskTool extends BaseTool {

    public static string $name = 'complete_task';

    public static string $description = 'Report task work is done and await further instructions. Task remains open for user review. If GitHub is configured, a PR will be auto-created.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'task_id' => [
                'type' => 'integer',
                'description' => 'The task ID'
            ],
            'pr_url' => [
                'type' => 'string',
                'description' => 'Pull request URL (if applicable)'
            ],
            'branch_name' => [
                'type' => 'string',
                'description' => 'Git branch name'
            ],
            'summary' => [
                'type' => 'string',
                'description' => 'Summary of what was accomplished'
            ]
        ],
        'required' => ['task_id']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        if (!$taskId) {
            throw new \Exception("task_id is required");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        $accessControl = new \app\TaskAccessControl();
        if (!$accessControl->canEdit((int)$this->member->id, $task)) {
            throw new \Exception("No permission to complete task {$taskId}");
        }

        // Update task - set to awaiting (not completed - user must explicitly complete)
        $task->status = 'awaiting';
        $task->updatedAt = date('Y-m-d H:i:s');

        if (isset($args['pr_url'])) {
            $task->prUrl = $args['pr_url'];
        }
        if (isset($args['branch_name'])) {
            $task->branchName = $args['branch_name'];
        }
        if (isset($args['results'])) {
            $task->resultsJson = is_string($args['results'])
                ? $args['results']
                : json_encode($args['results']);
        }

        Bean::store($task);

        // If summary provided, add it as a comment from Claude
        if (!empty($args['summary'])) {
            $comment = Bean::dispense('taskcomment');
            $comment->taskId = $taskId;
            $comment->memberId = $this->member->id;
            $comment->isFromClaude = true;
            $comment->content = "**Task Completion Summary:**\n\n" . $args['summary'];
            $comment->createdAt = date('Y-m-d H:i:s');
            Bean::store($comment);
        }

        // Auto-create PR if conditions are met
        $prCreated = false;
        $prError = null;

        if (!empty($task->branchName) && empty($task->prUrl)) {
            try {
                $prCreated = $this->createPullRequest($task, $args['summary'] ?? '');
            } catch (\Exception $e) {
                $prError = $e->getMessage();
            }
        }

        // Log status change
        $log = Bean::dispense('tasklog');
        $log->taskId = $taskId;
        $log->memberId = $this->member->id;
        $log->logLevel = 'info';
        $log->logType = 'status_change';
        $log->message = 'Work completed - awaiting review/further instructions';
        if ($prCreated) {
            $log->message .= ' (PR created)';
        } elseif ($prError) {
            $log->message .= " (PR failed: {$prError})";
        }
        $log->createdAt = date('Y-m-d H:i:s');
        Bean::store($log);

        $response = [
            'success' => true,
            'task_id' => $taskId,
            'status' => 'awaiting',
            'message' => 'Task work reported. Awaiting user review or further instructions.',
            'pr_url' => $task->prUrl
        ];

        if ($prCreated) {
            $response['pr_created'] = true;
        }
        if ($prError) {
            $response['pr_error'] = $prError;
        }

        return json_encode($response, JSON_PRETTY_PRINT);
    }

    /**
     * Create a pull request for the task
     *
     * @param object $task The task bean
     * @param string $summary Completion summary
     * @return bool True if PR was created
     */
    private function createPullRequest(object $task, string $summary = ''): bool {
        // Get GitHub service from team or global config
        $github = null;

        if ($task->teamId) {
            $team = Bean::load('team', $task->teamId);
            $github = GitHubService::fromTeam($team);
        }

        if (!$github) {
            $github = GitHubService::fromConfig();
        }

        if (!$github) {
            // GitHub not configured - silently skip
            return false;
        }

        // Get base branch
        $baseBranch = 'main';
        if ($task->teamId) {
            $team = $team ?? Bean::load('team', $task->teamId);
            if (!empty($team->defaultBranch)) {
                $baseBranch = $team->defaultBranch;
            }
        }

        // Build PR title and body
        $prTitle = $this->buildPRTitle($task);
        $prBody = GitHubService::buildPRBody([
            'id' => $task->id,
            'description' => $task->description,
            'acceptance_criteria' => $task->acceptanceCriteria,
            'task_type' => $task->taskType,
            'tags' => $task->tags,
        ], $summary);

        // Create the PR
        $pr = $github->createPullRequest(
            $prTitle,
            $prBody,
            $task->branchName,
            $baseBranch,
            false // not a draft
        );

        if (!empty($pr['html_url'])) {
            $task->prUrl = $pr['html_url'];
            $task->prNumber = $pr['number'];
            Bean::store($task);

            // Log PR creation
            $log = Bean::dispense('tasklog');
            $log->taskId = $task->id;
            $log->memberId = $this->member->id;
            $log->logLevel = 'info';
            $log->logType = 'github';
            $log->message = "Pull request created: {$pr['html_url']}";
            $log->createdAt = date('Y-m-d H:i:s');
            Bean::store($log);

            return true;
        }

        return false;
    }

    /**
     * Build PR title from task
     */
    private function buildPRTitle(object $task): string {
        $typePrefix = match($task->taskType) {
            'bugfix' => 'fix',
            'feature' => 'feat',
            'refactor' => 'refactor',
            'security' => 'security',
            'docs' => 'docs',
            'test' => 'test',
            default => 'feat',
        };

        return "{$typePrefix}: {$task->title}";
    }
}
