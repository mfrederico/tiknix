<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;

class UpdateTaskTool extends BaseTool {

    public static string $name = 'update_task';

    public static string $description = 'Update a workbench task. Use to report progress, set status, or record results.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'task_id' => [
                'type' => 'integer',
                'description' => 'The task ID'
            ],
            'status' => [
                'type' => 'string',
                'description' => 'New status',
                'enum' => ['running', 'completed', 'failed', 'paused']
            ],
            'branch_name' => [
                'type' => 'string',
                'description' => 'Git branch name'
            ],
            'pr_url' => [
                'type' => 'string',
                'description' => 'Pull request URL'
            ],
            'progress_message' => [
                'type' => 'string',
                'description' => 'Progress update message'
            ],
            'error_message' => [
                'type' => 'string',
                'description' => 'Error message (for failed status)'
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
            throw new \Exception("No permission to update task {$taskId}");
        }

        // Update allowed fields
        $allowedFields = ['status', 'branchName', 'prUrl', 'progressMessage', 'errorMessage'];
        $updated = [];

        foreach ($allowedFields as $field) {
            $argKey = $this->camelToSnake($field);
            if (isset($args[$argKey])) {
                $task->$field = $args[$argKey];
                $updated[] = $argKey;
            }
        }

        if (empty($updated)) {
            throw new \Exception("No valid fields to update");
        }

        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        return json_encode([
            'success' => true,
            'task_id' => $taskId,
            'updated_fields' => $updated
        ], JSON_PRETTY_PRINT);
    }

    private function camelToSnake(string $input): string {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }
}
