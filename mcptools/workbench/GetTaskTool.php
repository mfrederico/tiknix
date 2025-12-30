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

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        $accessControl = new \app\TaskAccessControl();
        if (!$accessControl->canView((int)$this->member->id, $task)) {
            throw new \Exception("Access denied to task {$taskId}");
        }

        // Get recent comments (last 10)
        $comments = Bean::getAll(
            "SELECT tc.id, tc.content, tc.is_from_claude, tc.created_at,
                    m.first_name, m.last_name, m.username
             FROM taskcomment tc
             JOIN member m ON tc.member_id = m.id
             WHERE tc.task_id = ?
             ORDER BY tc.created_at DESC
             LIMIT 10",
            [$taskId]
        );

        // Format comments for output
        $formattedComments = [];
        foreach (array_reverse($comments) as $c) {
            $author = $c['is_from_claude'] ? 'Claude' :
                      (trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?:
                      ($c['username'] ?? 'Unknown'));
            $formattedComments[] = [
                'id' => $c['id'],
                'author' => $author,
                'is_from_claude' => (bool)$c['is_from_claude'],
                'content' => $c['content'],
                'created_at' => $c['created_at']
            ];
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
            'updated_at' => $task->updatedAt,
            'recent_comments' => $formattedComments
        ], JSON_PRETTY_PRINT);
    }
}
