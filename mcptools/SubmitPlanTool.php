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
                        /* NAMES NO VENDOR. This used to read "the frontier engine (claude) for
                           judgement work", so a planner running on another provider dutifully
                           stamped every judgement task with claude — a decompose on z.ai
                           produced a plan that would build on Anthropic, because the schema
                           told it to. The registry knows which engines exist; the description
                           asks for them by name instead of hardcoding one. */
                        'engine'      => ['type' => 'string', 'description' => 'Optional engine override for this task. OMIT IT unless you have a specific reason: the task then runs on the project\'s own engine, which is the choice its owner already made, and the one you are running on now. Set it only to move a single task to a cheaper or more capable engine registered on this instance. Naming an engine you are not sure is registered makes the task run somewhere nobody chose; an unregistered value is ignored and the project\'s engine is used.'],
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
        // WHERE the plan goes is the planner's workspace, not this tool's install dir.
        //
        // dirname(__DIR__) is whichever MCP server answered the call. Over stdio that is
        // the instance's own server and happens to be right; over HTTP it is CORE's
        // gateway, and a plan for an instance was written into core's .aibuilder/ where
        // that instance's ingest never looked. The planner exports TIKNIX_WORKSPACE, and a
        // stdio server inherits it, so that is the authoritative answer.
        //
        // No workspace means we cannot know who this plan is for. REFUSE rather than
        // guess: a plan written to the wrong tree is worse than one not written, because
        // the planner reports success and the goal silently never arrives.
        $ws = trim((string) getenv('TIKNIX_WORKSPACE'));
        if ($ws === '' || !is_dir($ws)) {
            return 'Error: submit_plan cannot tell which project this plan is for '
                 . '(TIKNIX_WORKSPACE is not set). This tool must be called by the planner '
                 . 'through the project\'s own MCP server, not over the shared HTTP gateway.';
        }

        // Stamp the target INTO the plan. A file that names its own instance can be routed
        // or rejected if it ever turns up somewhere unexpected, instead of being an
        // anonymous plan.json whose owner has to be worked out from timestamps.
        $plan['instance']  = basename($ws);
        $plan['member_id'] = (int) getenv('TIKNIX_MEMBER_ID');
        $plan['written_at'] = date('c');

        $dir = rtrim($ws, '/') . '/.aibuilder';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return 'Error: could not create ' . $dir;
        }

        // UNIQUE per plan. PlanRunner locks per member+instance, so two members sharing an
        // instance can plan at the same time — with one fixed plan.json the second write
        // clobbered the first and a whole decomposition vanished with no error anywhere.
        $file = $dir . '/' . $plan['member_id'] . '-' . date('Ymd-His') . '-'
              . substr(bin2hex(random_bytes(4)), 0, 6) . '.plan.json';
        if (@file_put_contents($file, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return 'Error: could not write the plan file (' . basename($file) . ').';
        }
        return 'Plan received: "' . $title . '" with ' . count($subtasks) . ' task(s). Reply PLAN_WRITTEN — the operator can now review and execute it.';
    }
}
