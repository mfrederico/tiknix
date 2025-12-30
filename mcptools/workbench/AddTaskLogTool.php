<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;

class AddTaskLogTool extends BaseTool {

    public static string $name = 'add_task_log';

    public static string $description = 'Add a log entry to a task.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'task_id' => [
                'type' => 'integer',
                'description' => 'The task ID'
            ],
            'level' => [
                'type' => 'string',
                'description' => 'Log level',
                'enum' => ['debug', 'info', 'warning', 'error']
            ],
            'message' => [
                'type' => 'string',
                'description' => 'Log message'
            ],
            'type' => [
                'type' => 'string',
                'description' => 'Log type (general, status_change, etc.)'
            ],
            'context' => [
                'type' => 'object',
                'description' => 'Additional context data'
            ]
        ],
        'required' => ['task_id', 'message']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        $message = $args['message'] ?? '';
        $level = $args['level'] ?? 'info';
        $type = $args['type'] ?? 'general';

        if (!$taskId || !$message) {
            throw new \Exception("task_id and message are required");
        }

        $accessControl = new \app\TaskAccessControl($this->member->id);
        if (!$accessControl->canView($taskId)) {
            throw new \Exception("Access denied to task {$taskId}");
        }

        // Validate level
        $validLevels = ['debug', 'info', 'warning', 'error'];
        if (!in_array($level, $validLevels)) {
            $level = 'info';
        }

        $log = Bean::dispense('tasklog');
        $log->taskId = $taskId;
        $log->memberId = $this->member->id;
        $log->logLevel = $level;
        $log->logType = $type;
        $log->message = $message;
        $log->contextJson = isset($args['context'])
            ? json_encode($args['context'])
            : null;
        $log->createdAt = date('Y-m-d H:i:s');
        Bean::store($log);

        return json_encode([
            'success' => true,
            'log_id' => $log->id,
            'task_id' => $taskId
        ], JSON_PRETTY_PRINT);
    }
}
