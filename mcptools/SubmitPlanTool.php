<?php
/**
 * submit_plan — the planner's structured deliverable.
 *
 * Instead of scraping JSON from chat or hand-writing a file, the planner CALLS
 * this tool once with its decomposition. We validate the shape and write it to
 * <instance>/.aibuilder/plan.json; the app's planingest endpoint turns it into a
 * workbench task tree + baseline checkpoint. (The jail can't reach the app DB, so
 * a file is the handoff.)
 */

namespace app\mcptools;

class SubmitPlanTool extends BaseTool {

    public static string $name = 'submit_plan';
    public static string $description = 'Submit your decomposed plan. Call this ONCE, after grounding yourself with codebase_map/whatprovides, with the full task breakdown as a dependency graph. Give every subtask a stable "id" and list any prerequisite ids in "depends_on" — independent tasks (empty depends_on) will be built in parallel, so keep tasks that touch the same files sequential via depends_on. The plan is captured for the operator to review and execute.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'title'   => ['type' => 'string', 'description' => 'Short title for the overall plan'],
            'summary' => ['type' => 'string', 'description' => '1-3 sentence summary of the approach'],
            'subtasks' => [
                'type' => 'array',
                'description' => 'Concrete tasks (smallest sensible units) as a dependency graph',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id'          => ['type' => 'string', 'description' => 'Stable short id for this task (e.g. "t1"), referenced by other tasks depends_on'],
                        'title'       => ['type' => 'string'],
                        'description' => ['type' => 'string', 'description' => 'What to build, in GitHub-flavored Markdown: a one-line summary, then `##` sub-headers, `-` bullet lists, and `inline code` for files/beans/routes — scannable header-first, then details.'],
                        'priority'    => ['type' => 'integer', 'description' => '1 (highest) .. 4 (lowest)'],
                        'engine'      => ['type' => 'string', 'description' => 'claude or qwen — pick qwen for simple mechanical tasks'],
                        'files'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'likely files to touch'],
                        'depends_on'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'ids of tasks that MUST finish before this one (empty = can start immediately / run in parallel)'],
                        'reuses'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'existing primitives this task builds on, as "kind/name" (e.g. "controller/Lead", "model/member", "lib/Mailer"). Empty ONLY for genuinely new ground.'],
                    ],
                    'required' => ['title'],
                ],
            ],
        ],
        'required' => ['title', 'subtasks'],
    ];

    public function execute(array $args): string {
        $title = trim((string)($args['title'] ?? ''));
        $subtasks = $args['subtasks'] ?? [];
        if ($title === '' || !is_array($subtasks) || count($subtasks) === 0) {
            return 'Error: a plan needs a non-empty "title" and at least one item in "subtasks".';
        }
        $plan = [
            'title'    => $title,
            'summary'  => (string)($args['summary'] ?? ''),
            'subtasks' => array_values($subtasks),
        ];
        $dir = dirname(__DIR__) . '/.aibuilder';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (@file_put_contents($dir . '/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return 'Error: could not write the plan file (.aibuilder/plan.json).';
        }
        return 'Plan received: "' . $title . '" with ' . count($subtasks) . ' task(s). Reply PLAN_WRITTEN — the operator can now review and execute it.';
    }
}
