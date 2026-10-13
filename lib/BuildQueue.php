<?php
namespace app;

/**
 * A project's builds, in order.
 *
 * Every plan builds in its own copies of the app and merges each task into the app as it
 * finishes — and nothing looked across plans. Two plans started together that change the same
 * files were built side by side on the same starting code, and the second one's merges then
 * failed there. So Build asks first:
 *
 *   - does another plan that is building right now still have work on a file this plan names?
 *     Then this plan WAITS for it, and says for which plan and which files;
 *   - is a plan ahead of it in the queue going to change the same files? Then it waits its turn;
 *   - are MAX_BUILDING plans already building? Then it waits for a place.
 *
 * Otherwise it starts, beside what is running. A waiting plan keeps its state (approved, or
 * stalled) and gets a place in the queue (`queue_pos`, 1 = next); when a build ends, the queue
 * is gone through in order and every plan that no longer has a reason to wait is started
 * (advance(), from the orchestrator as it exits). A person can reorder the queue or take a plan
 * out of it.
 *
 * What a plan changes is what its planner wrote down (each task's files). Two plans that collide
 * on a file neither named are not seen here; that is what PlanExecutor::catchUp() is for.
 *
 * Runs on the project's own tasks database (Bean is on it wherever this is called).
 */
class BuildQueue {
    /** Plans of one project building side by side. Each runs up to PlanExecutor::MAX_CONCURRENT agents. */
    public const MAX_BUILDING = 2;

    private const FINISHED = ['merged', 'resolved', 'completed'];

    /** The files a plan's unfinished tasks name. */
    public static function filesOf(int $planId): array {
        $files = [];
        foreach (Bean::find('workbenchtask', 'parent_task_id = ?', [$planId]) as $t) {
            if (in_array((string) $t->status, self::FINISHED, true)) continue;
            foreach ((array) (json_decode((string) ($t->relatedFiles ?? ''), true) ?: []) as $f) {
                $f = trim((string) $f, " \t/");
                if ($f !== '') $files[$f] = true;
            }
        }
        return array_keys($files);
    }

    /** What two file lists have in common: the same path, or one a folder the other is in. */
    public static function shared(array $a, array $b): array {
        $out = [];
        foreach ($a as $x) foreach ($b as $y) {
            if ($x === $y || str_starts_with($y, $x . '/')) $out[$y] = true;
            elseif (str_starts_with($x, $y . '/')) $out[$x] = true;
        }
        return array_keys($out);
    }

    /** The plans whose build is running right now (a row that says "building" with no build behind it is not). */
    public static function building(string $slug, int $except = 0): array {
        $out = [];
        foreach (Bean::find('workbenchtask', "plan_status = 'building' AND id != ? ORDER BY id", [$except]) as $p) {
            if (PlanOrchestrator::running((int) $p->id, $slug)) $out[] = $p;
        }
        return $out;
    }

    /**
     * What a project is still building, read from its board without selecting it — a sentence,
     * or '' when nothing is building or queued. A request made now is made AFTER that work: it
     * is planned once that is in the app, so its plan reads the code it builds on instead of
     * guessing at pages that do not exist yet (and its check does not run against an app that
     * lacks them). $dir is the project's builder folder (its data/workbench.db is the board).
     */
    public static function busyWith(string $dir, string $slug): string {
        $db = rtrim($dir, '/') . '/data/workbench.db';
        if (!is_file($db)) return '';   // no board yet: nothing was ever built here
        $pdo = new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'workbenchtask'")->fetchColumn()) return '';
        $ids = [];
        // a row that says "building" with no build behind it is not one (as building() has it)
        foreach ($pdo->query("SELECT id FROM workbenchtask WHERE plan_status = 'building' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if (PlanOrchestrator::running((int) $id, $slug)) $ids[] = (int) $id;
        }
        $n = count($ids);
        if (in_array('queue_pos', array_column($pdo->query('PRAGMA table_info(workbenchtask)')->fetchAll(\PDO::FETCH_ASSOC), 'name'), true)) {
            foreach ($pdo->query('SELECT id FROM workbenchtask WHERE queue_pos > 0 ORDER BY queue_pos, id')->fetchAll(\PDO::FETCH_COLUMN) as $id) $ids[] = (int) $id;
        }
        if (!$ids) return '';
        $ids = array_values(array_unique($ids));
        return '#' . implode(', #', $ids) . (count($ids) === 1 ? ($n ? ' is still being built' : ' is waiting to be built') : ' are still being built');
    }

    /** The queue, next first. */
    public static function queued(): array {
        if (!in_array('queue_pos', array_keys(Bean::inspect('workbenchtask')), true)) return [];
        return array_values(Bean::find('workbenchtask', 'queue_pos > 0 ORDER BY queue_pos, id'));
    }

    /**
     * Why this plan cannot start now — a sentence for the board — or '' when it can.
     * @param array|null $building for a caller that already knows what is building (tests)
     */
    public static function waitsFor(object $plan, string $slug, int $except = 0, ?array $building = null): string {
        $mine     = self::filesOf((int) $plan->id);
        $building = array_values(array_filter($building ?? self::building($slug, $except), fn($b) => (int) $b->id !== (int) $plan->id));
        foreach ($building as $b) {
            if ($same = self::shared($mine, self::filesOf((int) $b->id))) {
                return "starts after #{$b->id}, which is changing the same files (" . self::some($same) . ')';
            }
        }
        $pos = (int) ($plan->queuePos ?? 0);
        foreach (self::queued() as $q) {
            if ((int) $q->id === (int) $plan->id || ($pos > 0 && (int) $q->queuePos >= $pos)) continue;
            if ($same = self::shared($mine, self::filesOf((int) $q->id))) {
                return "its turn comes after #{$q->id}, which is ahead of it in the queue and changes the same files (" . self::some($same) . ')';
            }
        }
        if (count($building) >= self::MAX_BUILDING) {
            return 'starts when one of the ' . count($building) . ' builds running finishes (#' . implode(', #', array_map(fn($b) => (int) $b->id, $building))
                 . ') — a project builds ' . self::MAX_BUILDING . ' plans at a time';
        }
        return '';
    }

    private static function some(array $files): string {
        return implode(', ', array_slice($files, 0, 3)) . (count($files) > 3 ? ', and ' . (count($files) - 3) . ' more' : '');
    }

    /**
     * Build this plan: now if nothing makes it wait, otherwise in the queue.
     * @return array{state:string, message:string} state: building | queued | failed
     */
    public static function start(object $plan, string $slug, string $dir, int $level, ?string $db = null, int $except = 0, ?array $building = null): array {
        $why = self::waitsFor($plan, $slug, $except, $building);
        if ($why !== '') {
            if ((int) ($plan->queuePos ?? 0) <= 0) {
                $last = 0;
                foreach (self::queued() as $q) $last = max($last, (int) $q->queuePos);
                $plan->queuePos = $last + 1;
            }
            $plan->queueLevel      = $level;
            $plan->progressMessage = 'Queued: ' . $why . '.';
            $plan->updatedAt       = date('Y-m-d H:i:s');
            Bean::store($plan);
            return ['state' => 'queued', 'message' => "Queued — plan #{$plan->id} {$why}. It starts by itself."];
        }
        if (!PlanOrchestrator::launch((int) $plan->id, $slug, $dir, $level, $db)) {
            return ['state' => 'failed', 'message' => 'Could not start the build.'];
        }
        $plan->planStatus      = 'building';
        $plan->status          = 'running';
        $plan->queuePos        = 0;
        $plan->progressMessage = '';
        $plan->updatedAt       = date('Y-m-d H:i:s');
        Bean::store($plan);
        self::renumber();
        return ['state' => 'building', 'message' => 'Build started — up to ' . PlanExecutor::MAX_CONCURRENT . ' agents running.'];
    }

    /**
     * A build ended (or the queue was reordered): start every queued plan that no longer has a
     * reason to wait, in queue order, and say on the others what they wait for now.
     * $except is the plan whose build is ending — its session is still alive while it calls this.
     * @return string[] what happened, a line a plan
     */
    public static function advance(string $slug, string $dir, ?string $db = null, int $except = 0): array {
        $lines = [];
        foreach (self::queued() as $q) {
            $plan = Bean::load('workbenchtask', (int) $q->id);
            // Taken out of the running while it waited: a plan that was deleted, re-planned or built by hand.
            if (!$plan->id || !in_array((string) $plan->planStatus, ['approved', 'stalled'], true)) {
                if ($plan->id) { $plan->queuePos = 0; Bean::store($plan); }
                continue;
            }
            $level = (int) ($plan->queueLevel ?? 0);
            if ($level <= 0) {   // no fallback level: a build runs as whoever queued it
                $lines[] = "#{$plan->id} is queued without the level of who queued it — press Build on it";
                error_log("ERROR BuildQueue::advance: plan #{$plan->id} of {$slug} has no queue_level; it was left in the queue");
                continue;
            }
            $r = self::start($plan, $slug, $dir, $level, $db, $except);
            $lines[] = "#{$plan->id}: " . ($r['state'] === 'building' ? 'started' : ($r['state'] === 'queued' ? (string) $plan->progressMessage : $r['message']));
            if ($r['state'] === 'failed') error_log("ERROR BuildQueue::advance: plan #{$plan->id} of {$slug} was next in the queue and its build could not be started");
        }
        return $lines;
    }

    /** Move a queued plan one place 'up' (sooner) or 'down'. False when it is not queued or already at that end. */
    public static function move(object $plan, string $direction): bool {
        $queue = self::queued();
        $at = null;
        foreach ($queue as $i => $q) if ((int) $q->id === (int) $plan->id) $at = $i;
        $to = $at === null ? null : $at + ($direction === 'up' ? -1 : 1);
        if ($at === null || !isset($queue[$to])) return false;
        [$queue[$at], $queue[$to]] = [$queue[$to], $queue[$at]];
        self::renumber($queue);
        return true;
    }

    /** Take a plan out of the queue. It stays approved (or stalled): Build puts it back. */
    public static function leave(object $plan): void {
        $plan->queuePos = 0;
        $plan->progressMessage = '';
        $plan->updatedAt = date('Y-m-d H:i:s');
        Bean::store($plan);
        self::renumber();
    }

    /** Places are 1, 2, 3… in the order given (the queue's own order when none is). */
    private static function renumber(?array $queue = null): void {
        foreach (array_values($queue ?? self::queued()) as $i => $q) {
            if ((int) $q->queuePos !== $i + 1) { $q->queuePos = $i + 1; Bean::store($q); }
        }
    }
}
