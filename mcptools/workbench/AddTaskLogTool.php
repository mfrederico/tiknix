<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;

class AddTaskLogTool extends BaseTool {

    public static string $name = 'add_task_log';

    public static string $description = 'Add a log entry to a task. With as_reply: true it is the agent\'s reply at the end of a turn instead: saved in the task\'s conversation (as Claude), and a task still marked running moves to awaiting — the user\'s turn. The session\'s stop hook sends this; an agent rarely needs to.';

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
            ],
            'as_reply' => [
                'type' => 'boolean',
                'description' => 'The agent\'s end-of-turn reply: a conversation comment, and running → awaiting'
            ]
        ],
        'required' => ['task_id']
    ];

    public function execute(array $args): string {
        $projectScoped = $this->selectWorkbenchDb();   // project: write to ITS OWN workbench.db
        if (!$this->member) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        $message = $args['message'] ?? '';
        $level = $args['level'] ?? 'info';
        $type = $args['type'] ?? 'general';

        // A reply may be empty (only tool output, or nothing worth keeping): the task is
        // still handed back. Any other log entry needs its message.
        if (!$taskId || (!$message && empty($args['as_reply']))) {
            throw new \Exception("task_id and message are required");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        if (!$this->mayUseTask($projectScoped, $task)) {
            throw new \Exception("Access denied to task {$taskId}");
        }

        if (!empty($args['as_reply'])) return $this->reply($task, (string) $message, $projectScoped);

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

    /**
     * The agent's reply at the end of a turn (scripts/hooks/workbench-response-capture.php).
     *
     * The two things that hook used to write straight into workbench.db — which a JAILED
     * session cannot see, so neither happened there: the reply in the task's conversation
     * (a taskcomment from Claude, not a log line — the page shows those as the thread), and
     * the hand-back: a task still `running` becomes `awaiting`. Only from running, so a late
     * reply never undoes a status someone has already set.
     */
    private function reply(object $task, string $text, bool $projectScoped): string {
        if (!$this->mayUseTask($projectScoped, $task, 'edit')) {
            throw new \Exception("No permission to update task {$task->id}");
        }
        $commentId = 0;
        if (trim($text) !== '') {
            $max = 2000;
            $comment = Bean::dispense('taskcomment');
            $comment->taskId = (int) $task->id;
            $comment->memberId = $task->memberId;          // shown as the task owner's agent
            $comment->content = strlen($text) > $max ? substr($text, 0, $max) . "\n\n... [truncated]" : $text;
            $comment->isFromClaude = 1;
            $comment->isInternal = 0;
            $comment->createdAt = date('Y-m-d H:i:s');
            $commentId = (int) Bean::store($comment);
        }

        $released = false;
        if ((string) $task->status === 'running') {
            $task->status = 'awaiting';
            $task->progressMessage = 'Waiting for user input';
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);
            $released = true;
        }
        return json_encode(['success' => true, 'comment_id' => $commentId, 'task_id' => (int) $task->id,
                            'status' => (string) $task->status, 'released' => $released], JSON_PRETTY_PRINT);
    }
}
