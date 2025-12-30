<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;

class ListTasksTool extends BaseTool {

    public static string $name = 'list_tasks';

    public static string $description = 'List workbench tasks visible to the authenticated user.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'status' => [
                'type' => 'string',
                'description' => 'Filter by status',
                'enum' => ['pending', 'queued', 'running', 'completed', 'failed', 'paused']
            ],
            'team_id' => [
                'type' => 'integer',
                'description' => 'Filter by team ID (null for personal tasks)'
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of tasks to return (default: 20)'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required");
        }

        $status = $args['status'] ?? null;
        $teamId = isset($args['team_id']) ? (int)$args['team_id'] : null;
        $limit = min((int)($args['limit'] ?? 20), 100);

        $accessControl = new \app\TaskAccessControl();
        $filters = [];
        if ($status) {
            $filters['status'] = $status;
        }
        if ($teamId !== null) {
            $filters['team_id'] = $teamId;
        }
        $tasks = $accessControl->getVisibleTasks((int)$this->member->id, $filters);

        // Apply limit
        $tasks = array_slice($tasks, 0, $limit);

        $result = [];
        foreach ($tasks as $task) {
            $result[] = [
                'id' => $task->id,
                'title' => $task->title,
                'task_type' => $task->taskType,
                'status' => $task->status,
                'priority' => $task->priority,
                'team_id' => $task->teamId,
                'created_at' => $task->createdAt,
                'updated_at' => $task->updatedAt
            ];
        }

        return json_encode([
            'count' => count($result),
            'tasks' => $result
        ], JSON_PRETTY_PRINT);
    }
}
