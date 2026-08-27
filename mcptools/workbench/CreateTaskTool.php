<?php
/**
 * create_task — file a task on the board from inside a terminal session.
 *
 * The terminal and the board were one-way: an agent could list, read, update and complete
 * tasks, but not create one. So work discovered mid-session had nowhere to go except the
 * conversation itself — and a terminal that disconnects takes that with it. This exists so
 * a session can write down what it found, as a real task, before it is lost.
 *
 * Created PENDING and never started. That is the point: it is a note for later, not a
 * second agent racing the one that filed it.
 *
 * PROJECT SCOPE IS REQUIRED. It writes to the instance's own data/workbench.db, the same
 * board the project's members see. Refused outside a project rather than silently landing
 * a task in core's board, where the people who need it would never look.
 */

namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use app\Bean;
use app\EngineRegistry;

class CreateTaskTool extends BaseTool {

    public static string $name = 'create_task';

    public static string $description =
        'File a new task on this project\'s board for someone to start later. Use it to capture '
      . 'work you found but were not asked to do, or to save context before it is lost. The task '
      . 'is created pending — it does not start anything.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'title' => [
                'type' => 'string',
                'description' => 'One line naming the work, as it will read on the board.',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'The full brief in Markdown: what to do, why, which files, and '
                    . 'anything you learned here that a fresh session would otherwise have to rediscover. '
                    . 'This is the part that survives a disconnect — be specific.',
            ],
            'priority' => [
                'type' => 'integer',
                'description' => '1 (highest) .. 4 (lowest). Defaults to 3.',
            ],
            'engine' => [
                'type' => 'string',
                'description' => 'Optional engine for this task. Omit to inherit the project\'s, '
                    . 'which is almost always right.',
            ],
            'related_files' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Files the work touches, so whoever picks it up starts in the right place.',
            ],
        ],
        'required' => ['title', 'description'],
    ];

    public function execute(array $args): string {
        $projectScoped = $this->selectWorkbenchDb();
        if (!$this->member) {
            throw new \Exception('Authentication required');
        }
        if (!$projectScoped) {
            // Not a fallback to core's board: a task filed where nobody looks is worse than
            // a refusal, because the caller believes it was recorded.
            throw new \Exception(
                'create_task only works inside a project. This session is not scoped to one, '
              . 'so there is no project board to file against.'
            );
        }

        $title = trim((string) ($args['title'] ?? ''));
        $desc  = trim((string) ($args['description'] ?? ''));
        if ($title === '') throw new \Exception('title is required');
        if ($desc === '')  throw new \Exception('description is required — a task with no brief cannot be picked up later');

        $priority = (int) ($args['priority'] ?? 3);
        if ($priority < 1 || $priority > 4) $priority = 3;

        $task = Bean::dispense('workbenchtask');
        $task->title       = $title;
        $task->description = $desc;
        $task->status      = 'pending';       // filed, not started
        $task->priority    = $priority;
        $task->taskType    = 'task';
        $task->memberId    = (int) $this->member->id;
        $task->createdAt   = date('Y-m-d H:i:s');
        $task->updatedAt   = date('Y-m-d H:i:s');

        /* Engine: what was asked for, else what THIS session is running on, else nothing.
         * The jail exports ENGINE, so a task filed from a z.ai terminal defaults to z.ai —
         * the member's own choice, already expressed by the session they are sitting in.
         * Left unset when unknown, so it inherits the project default at run time rather
         * than pinning a provider nobody picked. */
        $engine = trim((string) ($args['engine'] ?? '')) ?: trim((string) getenv('ENGINE'));
        if ($engine !== '' && EngineRegistry::isValid($engine)) {
            $task->engine = $engine;
        }

        if (!empty($args['related_files']) && is_array($args['related_files'])) {
            $files = array_values(array_filter(array_map(
                fn($f) => trim((string) $f),
                $args['related_files']
            )));
            if ($files) $task->relatedFiles = json_encode($files);
        }

        $id = Bean::store($task);

        return json_encode([
            'success'  => true,
            'task_id'  => (int) $id,
            'status'   => 'pending',
            'engine'   => $task->engine ?? null,
            'message'  => "Filed task #{$id} on this project's board. It is pending and will not start on its own.",
        ], JSON_PRETTY_PRINT);
    }
}
