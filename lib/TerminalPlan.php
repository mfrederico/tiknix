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
     * @return array{plan:int,title:string,tasks:int,deepening?:int} plan is 0 and `deepening` the number
     *         of marked tasks when the plan is being planned more deeply first: it reaches the board
     *         as a draft when that finishes (a few minutes)
     * @throws \InvalidArgumentException the plan is not one (400)
     * @throws \RuntimeException it cannot be received now (409/429), with the reason
     */
    /**
     * Start the deeper planning of a terminal's plan and its ingest, detached: the same two
     * passes a planner's own plan gets (scripts/plan-deepen.php), then scripts/plan-ingest.php.
     * It runs as this project's planner — the session name is the planner's, so the Builder
     * shows planning in progress and a second planning run waits its turn.
     *
     * @return string '' when it was started, else why it could not be (the caller then ingests
     *                the plan as it was written, and says so on it)
     */
    private static function deepenFirst(object $inst, array $plan, int $owner, string $app, string $db): string {
        if (!TenantBuilder::bySlug((string) $inst->slug)) return 'this project is not running in its own container';
        $dir = rtrim(\Model_Instance::dirForSlug((string) $inst->slug, $app), '/');
        $ab  = $dir . '/.aibuilder';
        if (!is_dir($ab) && !@mkdir($ab, 0775, true)) return "could not create {$ab}";
        $engine = (string) ($inst->engine ?: 'claude');
        $runner = new PlanRunner((string) $inst->slug, $dir, $owner, 50, $engine);
        if ($runner->running()) return 'a planner is already working on this project';

        $stamp = $owner . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $draft = "{$ab}/{$stamp}.plan.draft.json";
        $final = "{$ab}/{$stamp}.plan.json";
        $goalF = "{$ab}/{$stamp}.goal.md";
        $plan['instance'] = basename($dir); $plan['member_id'] = $owner; $plan['written_at'] = date('c');
        // What the deeper passes are told the plan is FOR: its own words. (There is no typed goal.)
        $goal = '# ' . (string) $plan['title'] . "\n\n" . trim((string) ($plan['summary'] ?? ''))
              . "\n\nThis plan was worked out in the project's terminal with its owner and handed in whole. Keep every task that is not marked complex as it is written — its wording was agreed. Split only the tasks marked complex, and re-check the order.";
        if (file_put_contents($draft, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || file_put_contents($goalF, $goal) === false) return "could not write the plan into {$ab}";

        $q = fn(string $v) => escapeshellarg($v);
        $root = dirname(__DIR__); $log = $q($runner->logFile()); $session = $runner->getSessionName();
        $n = count(PlanRunner::flagged($plan));
        $script = <<<BASH
#!/bin/bash
# Tiknix: a plan handed in from {$inst->slug}'s terminal, planned more deeply before its owner sees it (lib/TerminalPlan.php)
export TIKNIX_MEMBER_ID={$owner}
export TIKNIX_MEMBER_LEVEL=50
export TIKNIX_SESSION_NAME="{$session}"
export TIKNIX_PROJECT_ROOT="{$root}"
export TIKNIX_WORKSPACE="{$dir}"
echo "[planner] a plan from the terminal: {$n} task(s) marked as more than one — planning them more deeply \$(date)" | tee {$log}
php {$q($root . '/scripts/plan-deepen.php')} --slug={$q((string) $inst->slug)} --dir={$q($dir)} --member={$owner} --level=50 --engine={$q($engine)} --mode=flagged --draft={$q($draft)} --goal-file={$q($goalF)} 2>&1 | tee -a {$log}
if [ ! -f {$q($final)} ]; then
  echo "[planner] ERROR the deeper planning left no plan — handing in the plan as it was written" | tee -a {$log}
  mv {$q($draft)} {$q($final)}
fi
php {$q($root . '/scripts/plan-ingest.php')} --slug={$q((string) $inst->slug)} --dir={$q($dir)} --member={$owner} --app={$q($app)} --db={$q($db)} --source=terminal --file={$q($final)} 2>&1 | tee -a {$log}
rm -f {$q($goalF)}
echo "[planner] done \$(date)" | tee -a {$log}
BASH;
        $file = "{$ab}/run-terminal-plan.sh";
        if (file_put_contents($file, $script) === false || !chmod($file, 0755)) { @unlink($draft); @unlink($goalF); return "could not write {$file}"; }
        TmuxManager::create($session, $file, $dir);
        usleep(400000);
        if (!TmuxManager::exists($session)) { @unlink($draft); @unlink($goalF); return 'the planning session did not start'; }
        return '';
    }

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
            // Tasks marked as more than one are planned more deeply BEFORE the owner sees the plan, as
            // the Builder's own planner does for the plans it writes — so what is approved is the
            // whole list, and no task has to split itself halfway through the build.
            $flagged = PlanRunner::flagged($plan);
            if ($flagged) {
                $why = self::deepenFirst($inst, $plan, $owner, $app, $db);
                if ($why === '') return ['plan' => 0, 'title' => (string) $plan['title'], 'tasks' => count($plan['subtasks']), 'deepening' => count($flagged)];
                // It could not be started. The plan is not held back for that: it goes in as written,
                // saying so — its marked tasks then split themselves when they are built.
                $plan['planning'] = ['mode' => 'flagged', 'passes' => 1, 'flagged' => $flagged, 'split' => 0, 'before' => count($plan['subtasks']), 'after' => count($plan['subtasks']),
                                     'error' => 'not planned more deeply: ' . $why, 'still_complex' => $flagged];
                \Flight::get('log')?->error('TerminalPlan: a plan with tasks marked complex was not deepened: ' . $why, ['instance' => $inst->slug]);
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
