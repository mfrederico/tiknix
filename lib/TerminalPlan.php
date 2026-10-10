<?php
/**
 * TerminalPlan — a plan handed in from an app's terminal, received as a DRAFT in the Builder.
 *
 * The Builder's planner hands its plan in through `submit_plan`, which writes a file into the
 * planner's own workspace for core to ingest. An agent in a project's TERMINAL has no such
 * workspace: its `submit_plan` answered "TIKNIX_WORKSPACE is not set", and a person who had
 * worked a plan out with their terminal agent had no way to get it built (support ticket #13,
 * 2026-10-06). From a terminal the tool now sends the plan to core over the app's broker key
 * (POST /brokerinfo/plan), and this puts it where every other plan lives — the project's own
 * workbench.db — as a draft owned by the project's owner. Nothing is built until a person
 * approves it on the Builder, exactly like a planner's plan.
 *
 * The project is the broker key's, never something the body names.
 */
namespace app;

final class TerminalPlan {

    /** Plans a project's terminals may hand in per hour: a loop that keeps submitting stops here. */
    public const PER_HOUR = 6;

    /**
     * @return array{plan:int,title:string,tasks:int}
     * @throws \InvalidArgumentException the plan is not one (400)
     * @throws \RuntimeException it cannot be received now (409/429), with the reason
     */
    public static function receive(object $inst, array $plan): array {
        $owner = (int) ($inst->memberId ?? 0);
        if (!$inst->id || $owner <= 0) throw new \RuntimeException("Project #{$inst->id} has no owner on Tiknix to hold a plan for.");
        if (!PlanIngestor::isValidPlan($plan)) throw new \InvalidArgumentException('Not a plan: it needs a title and at least one subtask, each with an id and a title.');
        unset($plan['instance'], $plan['member_id'], $plan['agent']);   // decided here, never by the sender
        // The same shape a planner's plan is held to (app\PlanShape): no errand tasks, and no two
        // unchained tasks on one file — that plan stops at its second merge.
        if (($why = PlanShape::refusal((array) $plan['subtasks'])) !== '') throw new \InvalidArgumentException($why);

        $app = (string) ($inst->app ?: \Model_Instance::DEFAULT_APP);
        $db  = rtrim(\Model_Instance::dirForSlug((string) $inst->slug, $app), '/') . '/data/workbench.db';
        if (!is_file($db) || filesize($db) === 0) {
            throw new \RuntimeException("This project's Builder board has not been opened yet, so there is nowhere to put a plan. Open the Builder for this project once on tiknix.com, then submit the plan again.");
        }

        $key = 'ws:' . $inst->slug;   // the sidecar's own name for this database (WorkbenchDb::key)
        if (!Bean::hasDatabase($key)) Bean::addDatabase($key, 'sqlite:' . $db);
        Bean::selectDatabase($key);
        try {
            // A board that has never held a task has no table yet, and one that has never held a
            // terminal's plan has no `source` column: either way none were handed in this hour.
            $cols = in_array('workbenchtask', Bean::inspect(), true) ? array_keys(Bean::inspect('workbenchtask')) : [];
            $recent = in_array('source', $cols, true)
                ? (int) Bean::getCell("SELECT COUNT(*) FROM workbenchtask WHERE source = 'terminal' AND created_at > ?", [date('Y-m-d H:i:s', time() - 3600)]) : 0;
            if ($recent >= self::PER_HOUR) {
                throw new \RuntimeException('This project has handed in ' . self::PER_HOUR . ' plans from a terminal in the last hour; that is the limit. Review the drafts already on the Builder.');
            }
            $res = PlanIngestor::ingest($inst, $plan, $owner, '', $app);
            $parent = Bean::load('workbenchtask', (int) $res['parent']['id']);
            $parent->source = 'terminal';   // where it came from, said on the plan
            Bean::store($parent);
            return ['plan' => (int) $parent->id, 'title' => (string) $parent->title, 'tasks' => count($res['subtasks'])];
        } finally {
            Bean::selectDatabase('default');
        }
    }
}
