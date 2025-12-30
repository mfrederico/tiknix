<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;

class AskQuestionTool extends BaseTool {

    public static string $name = 'ask_question';

    public static string $description = 'Ask the user a clarifying question. The question will be shown in the task UI and the task will be set to awaiting status until the user responds.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'task_id' => [
                'type' => 'integer',
                'description' => 'The task ID'
            ],
            'question' => [
                'type' => 'string',
                'description' => 'The question to ask the user'
            ],
            'context' => [
                'type' => 'string',
                'description' => 'Optional context or explanation for why this question is needed'
            ],
            'options' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Optional list of suggested answers/options'
            ]
        ],
        'required' => ['task_id', 'question']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        if (!$taskId) {
            throw new \Exception("task_id is required");
        }

        $question = trim($args['question'] ?? '');
        if (empty($question)) {
            throw new \Exception("question is required");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        $accessControl = new \app\TaskAccessControl();
        if (!$accessControl->canEdit((int)$this->member->id, $task)) {
            throw new \Exception("No permission to update task {$taskId}");
        }

        // Build the question message
        $message = "**Question from Claude:**\n\n" . $question;

        if (!empty($args['context'])) {
            $message .= "\n\n*Context:* " . $args['context'];
        }

        if (!empty($args['options']) && is_array($args['options'])) {
            $message .= "\n\n*Suggested options:*\n";
            foreach ($args['options'] as $i => $option) {
                $message .= "- " . $option . "\n";
            }
        }

        // Store as a comment from the system/Claude
        $comment = Bean::dispense('taskcomment');
        $comment->taskId = $taskId;
        $comment->memberId = $this->member->id;
        $comment->content = $message;
        $comment->isFromClaude = 1;
        $comment->isInternal = 0;
        $comment->createdAt = date('Y-m-d H:i:s');
        Bean::store($comment);

        // Update task status to awaiting
        $task->status = 'awaiting';
        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        // Log the question
        $log = Bean::dispense('tasklog');
        $log->taskId = $taskId;
        $log->memberId = $this->member->id;
        $log->logLevel = 'info';
        $log->logType = 'question';
        $log->message = 'Claude asked: ' . substr($question, 0, 100) . (strlen($question) > 100 ? '...' : '');
        $log->createdAt = date('Y-m-d H:i:s');
        Bean::store($log);

        return json_encode([
            'success' => true,
            'task_id' => $taskId,
            'status' => 'awaiting',
            'message' => 'Question posted. Waiting for user response.',
            'comment_id' => $comment->id
        ], JSON_PRETTY_PRINT);
    }
}
