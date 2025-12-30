<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;

class GetTaskTool extends BaseTool {

    public static string $name = 'get_task';

    public static string $description = 'Get details of a specific workbench task.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'task_id' => [
                'type' => 'integer',
                'description' => 'The task ID'
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
        if (!$accessControl->canView($taskId)) {
            throw new \Exception("Access denied to task {$taskId}");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        return json_encode([
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'task_type' => $task->taskType,
            'status' => $task->status,
            'priority' => $task->priority,
            'acceptance_criteria' => $task->acceptanceCriteria,
            'related_files' => json_decode($task->relatedFiles, true) ?: [],
            'tags' => json_decode($task->tags, true) ?: [],
            'member_id' => $task->memberId,
            'team_id' => $task->teamId,
            'branch_name' => $task->branchName,
            'pr_url' => $task->prUrl,
            'created_at' => $task->createdAt,
            'updated_at' => $task->updatedAt
        ], JSON_PRETTY_PRINT);
    }
}
