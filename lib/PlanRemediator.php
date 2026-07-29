<?php
/**
 * PlanRemediator — what happens when a plan ends with work that did not land.
 *
 * There are already two layers below this one, and the policy here only makes sense
 * against them:
 *
 *   1. PlanExecutor::autoRetry — MECHANICAL failures (dirty tree, worktree, dead
 *      session). It corrects and re-queues, up to MAX_AUTO_RETRIES, and stops early if
 *      the identical failure repeats. Anything it hands back is, by construction, NOT
 *      mechanically fixable.
 *   2. The orchestrator — drives to a terminal state and marks the plan.
 *
 * So a failure arriving here has already survived the retry that would have fixed a
 * flake. What is left is a plan that was WRONG: a spec a worker could not implement, or
 * a merge that cannot be reconciled. The response to a wrong plan is a better plan, not
 * another run of the same one — which is why this re-plans rather than retries.
 *
 * THE BOUND IS THE WHOLE POLICY. Re-planning costs a frontier model run and can fail the
 * same way twice, so a plan produced BY remediation never triggers another one: one
 * automatic attempt, then a human. That is enforced by a link (`replanOf`) on the new
 * plan rather than a counter, so the chain is visible on the board and cannot be lost.
 *
 * It also does NOT supersede the failed plan. Deleting the evidence of what went wrong,
 * moments before asking someone to trust the replacement, is the wrong instinct.
 */
namespace app;

use RedBeanPHP\R;

class PlanRemediator {

    /** One automatic re-plan. After that a person decides. */
    public const MAX_REPLANS = 1;

    /**
     * Decide and act, after a plan has reached a terminal state.
     *
     * @param object $parent    the plan bean (in the instance's tasks db)
     * @param array  $failures  [['title'=>..,'why'=>..], ...]
     * @return array ['action' => 'none'|'replan'|'escalate', 'why' => string, 'session' => string]
     */
    public static function afterPlan($parent, array $failures, string $slug, string $dir,
                                     int $memberId, int $memberLevel, string $engine): array {
        if (!$failures) return ['action' => 'none', 'why' => 'nothing failed'];

        $cfg = @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
        if (!filter_var($cfg['plan']['auto_replan'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return ['action' => 'escalate', 'why' => 'auto-replan is switched off'];
        }

        // Already the product of a re-plan → a person decides. This is the loop guard.
        if ((int) ($parent->replanOf ?? 0) > 0) {
            return ['action' => 'escalate',
                    'why' => 'this plan was already a re-plan of #' . (int) $parent->replanOf
                           . ' and still has failures'];
        }

        $goalFile = rtrim($dir, '/') . '/.aibuilder/plan-goal.md';
        $goal = is_file($goalFile) ? trim((string) file_get_contents($goalFile)) : '';
        if ($goal === '') {
            // Without the original ask, a "re-plan" would be a new plan invented from a
            // failure list — which is not the same thing and not what anyone asked for.
            return ['action' => 'escalate', 'why' => 'the original goal was not kept, so it cannot be re-planned'];
        }

        $runner = new PlanRunner($slug, $dir, $memberId, $memberLevel, $engine);
        if ($runner->running()) {
            return ['action' => 'escalate', 'why' => 'a planner is already running for this project'];
        }

        try {
            // Carry the straight-through choice into the re-plan. Someone who said
            // "don't stop and ask me" did not mean "…unless the first attempt fails",
            // and a re-plan that quietly waits for a click is the one outcome that
            // looks identical to nothing happening. Bounded either way: the replanOf
            // chain still allows exactly one automatic attempt.
            $runner->start(self::buildGoal($goal, $parent, $failures), [], !empty($parent->autoBuild));
        } catch (\Throwable $e) {
            return ['action' => 'escalate', 'why' => 'could not start the planner: ' . $e->getMessage()];
        }

        // Mark the plan we are re-planning FROM, so the next ingest can link the new one
        // to it and this guard trips if the second attempt fails too.
        $parent->replanRequestedAt = date('Y-m-d H:i:s');
        R::store($parent);

        return ['action' => 'replan',
                'why' => count($failures) . ' subtask(s) failed after mechanical retries were exhausted',
                'session' => $runner->getSessionName()];
    }

    /**
     * The goal for the second attempt: the ORIGINAL ask, plus what happened when it was
     * tried. Not a list of bugs to fix — the planner's job is to decompose the same goal
     * better, knowing where the first decomposition broke.
     */
    private static function buildGoal(string $goal, $parent, array $failures): string {
        $out = [$goal, '', '---', '',
            '## A previous attempt at this goal partly failed',
            '',
            'A plan was decomposed from the goal above and built. Most of it landed; the',
            'work below did not, and the mechanical retries that fix flaky runs were',
            'already exhausted on it — so these are failures of the PLAN, not of the run.',
            '',
        ];
        foreach ($failures as $f) {
            $why = trim((string) ($f['why'] ?? ''));
            $out[] = '- **' . trim((string) ($f['title'] ?? 'untitled task')) . '**';
            if ($why !== '') $out[] = '  - reported: `' . mb_substr(preg_replace('/\s+/', ' ', $why), 0, 400) . '`';
        }
        $out[] = '';
        $out[] = 'Decompose the SAME goal again, taking that into account. Where a subtask';
        $out[] = 'failed because it was under-specified, specify it. Where it failed because';
        $out[] = 'it depended on something that did not exist yet, order it correctly. Do not';
        $out[] = 'plan work that already landed — read the codebase first and REUSE it.';
        $out[] = '';
        $out[] = 'If the goal itself cannot be built as written, say so in the plan summary';
        $out[] = 'rather than decomposing around it.';

        return implode("\n", $out);
    }
}
