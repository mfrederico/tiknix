<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;

class CompleteTaskTool extends BaseTool {

    public static string $name = 'complete_task';

    public static string $description = 'Report task work is done and await further instructions. Task remains open for user review.';

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

        $accessControl = new \app\TaskAccessControl($this->member->id);
        if (!$accessControl->canEdit($taskId)) {
            throw new \Exception("No permission to complete task {$taskId}");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
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

        // Log status change
        $log = Bean::dispense('tasklog');
        $log->taskId = $taskId;
        $log->memberId = $this->member->id;
        $log->logLevel = 'info';
        $log->logType = 'status_change';
        $log->message = 'Work completed - awaiting review/further instructions';
        $log->createdAt = date('Y-m-d H:i:s');
        Bean::store($log);

        return json_encode([
            'success' => true,
            'task_id' => $taskId,
            'status' => 'awaiting',
            'message' => 'Task work reported. Awaiting user review or further instructions.',
            'pr_url' => $task->prUrl
        ], JSON_PRETTY_PRINT);
    }
}
