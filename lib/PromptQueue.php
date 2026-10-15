<?php
/**
 * PromptQueue — retry a decompose that was refused because the project was busy: another
 * request was being decomposed, or (from Ask) an earlier one was still being built.
 *
 * PlanRunner allows one planner per member+instance, so firing a decompose while another
 * is mid-flight is refused outright. Workbench::decompose() records the prompt BEFORE
 * starting the planner, so the goal survives — but nothing ever went back for it. The
 * goal sat in the prompt log while unrelated branches kept building, and the only recovery
 * was to notice and press the button.
 *
 * ONLY STRAIGHT-THROUGH GOALS ARE QUEUED, and that is the whole policy. "Run it straight
 * through" means "don't stop and ask me", so retrying it later is finishing what was
 * asked. A decompose WITHOUT it produces a draft that waits for approval anyway, so
 * starting one unattended would be inventing an instruction nobody gave — those keep the
 * manual button instead.
 *
 * Bounded on both axes, because an unbounded retry is how a queue becomes a runaway:
 *   - MAX_ATTEMPTS tries, then it stops and reverts to the manual button;
 *   - nothing older than MAX_AGE_HOURS is retried at all, because a goal from days ago is
 *     probably no longer what anyone wants built, and starting it unattended would be a
 *     surprise rather than a service.
 */

namespace app;

class PromptQueue {

    /** Give up after this many automatic attempts; the manual button remains. */
    public const MAX_ATTEMPTS = 3;

    /** Never auto-start a goal older than this. Stale intent is worse than no retry. */
    public const MAX_AGE_HOURS = 12;

    /**
     * Mark a straight-through prompt for retry. No-op for anything else, so callers can
     * call it unconditionally on failure without deciding policy themselves.
     */
    public static function enqueue(int $promptId, bool $autoBuild): bool {
        if ($promptId <= 0 || !$autoBuild) return false;
        return (bool) CoreDb::with(function () use ($promptId) {
            $row = Bean::load('promptlog', $promptId);
            if (!$row->id) return false;
            $row->queuedAt      = date('Y-m-d H:i:s');
            $row->queueAttempts = (int) $row->queueAttempts;   // create the column at 0
            Bean::store($row);
            return true;
        }, false);
    }

    /**
     * What is waiting, for ONE member and optionally one project — oldest first, which is
     * the order it will actually be retried in.
     *
     * Reads the same `queued_at IS NOT NULL` condition drain() does, deliberately: a queue
     * page that decides for itself what "queued" means is one deploy away from showing a
     * list nobody is working through.
     *
     * @return list<array{id:int,title:string,body:string,instance_tag:string,queued_at:string,attempts:int,auto_build:bool}>
     */
    public static function queued(int $memberId, string $instanceTag = ''): array {
        if ($memberId <= 0) return [];
        return (array) CoreDb::with(function () use ($memberId, $instanceTag) {
            $sql  = 'queued_at IS NOT NULL AND member_id = ?';
            $args = [$memberId];
            if ($instanceTag !== '') { $sql .= ' AND instance_tag = ?'; $args[] = $instanceTag; }
            $sql .= ' ORDER BY queued_at ASC';

            $out = [];
            foreach (Bean::find('promptlog', $sql, $args) as $r) {
                $out[] = [
                    'id'           => (int) $r->id,
                    'title'        => (string) $r->title,
                    'body'         => (string) $r->body,
                    'instance_tag' => (string) $r->instanceTag,
                    'queued_at'    => (string) $r->queuedAt,
                    'attempts'     => (int) $r->queueAttempts,
                    'auto_build'   => (bool) $r->autoBuild,
                ];
            }
            return $out;
        }, []);
    }

    /**
     * Change what a WAITING request says — its own member's, and only while it still waits: once
     * its planner has started, the words it started with are the ones being planned. False when
     * it is not that member's or no longer waiting.
     */
    public static function rewrite(int $promptId, int $memberId, string $body, string $title): bool {
        if ($promptId <= 0 || $memberId <= 0 || trim($body) === '') return false;
        return (bool) CoreDb::with(function () use ($promptId, $memberId, $body, $title) {
            $row = Bean::findOne('promptlog', 'id = ? AND member_id = ? AND queued_at IS NOT NULL', [$promptId, $memberId]);
            if (!$row || !$row->id || (string) $row->planUid !== '') return false;
            $row->body  = self::keepAnswers((string) $row->body, $body);
            $row->title = $title;
            Bean::store($row);
            return true;
        }, false);
    }

    /**
     * What its planner already asked and the person answered (AskTriage::QA_HEAD) is part of the request,
     * not of the words being changed: a rewrite keeps it. Replacing the whole body threw the answers away.
     */
    public static function keepAnswers(string $old, string $new): string {
        $head = "\n" . AskTriage::QA_HEAD . "\n";
        if (($at = strpos($old, $head)) === false || str_contains($new, $head)) return $new;
        return rtrim($new) . "\n\n" . ltrim(substr($old, $at));
    }

    /** Stop retrying — the plan landed, it aged out, or it exhausted its attempts. */
    public static function dequeue(int $promptId): void {
        if ($promptId <= 0) return;
        CoreDb::with(function () use ($promptId) {
            $row = Bean::load('promptlog', $promptId);
            if ($row->id) { $row->queuedAt = null; Bean::store($row); }
            return null;
        });
    }

    /**
     * Retry everything whose project is now free. Returns a line per outcome, for the
     * cron log — a queue that drains silently is impossible to trust.
     *
     * Safe to call every minute: a prompt whose planner is still running is left exactly
     * as it is, without consuming an attempt.
     */
    public static function drain(): array {
        $out = [];
        $queued = CoreDb::with(function () {
            $rows = [];
            foreach (Bean::find('promptlog', 'queued_at IS NOT NULL ORDER BY queued_at ASC') as $r) {
                $rows[] = [
                    'id'         => (int) $r->id,
                    'member_id'  => (int) $r->memberId,
                    'body'       => (string) $r->body,
                    'tag'        => (string) $r->instanceTag,
                    'plan_uid'   => (string) $r->planUid,
                    'auto_build' => !empty($r->autoBuild),
                    'attempts'   => (int) $r->queueAttempts,
                    'queued_at'  => (string) $r->queuedAt,
                ];
            }
            return $rows;
        }, []);

        foreach ((array) $queued as $q) {
            $id = $q['id'];

            // Someone ran it in the meantime (the manual button, or a later decompose).
            if ($q['plan_uid'] !== '') { self::dequeue($id); $out[] = "#$id already has a plan — dequeued"; continue; }

            // Policy guard, restated at the point of action: only straight-through goals
            // are ever started unattended, whatever the row happens to say.
            if (!$q['auto_build']) { self::dequeue($id); $out[] = "#$id is not straight-through — dequeued"; continue; }

            if ($q['attempts'] >= self::MAX_ATTEMPTS) {
                self::dequeue($id);
                $out[] = "#$id gave up after {$q['attempts']} attempt(s) — use the button";
                continue;
            }
            $age = (time() - strtotime($q['queued_at'] ?: 'now')) / 3600;
            if ($age > self::MAX_AGE_HOURS) {
                self::dequeue($id);
                $out[] = sprintf('#%d aged out (%.1fh) — use the button', $id, $age);
                continue;
            }

            $slug = (string) (strstr($q['tag'], '.', true) ?: $q['tag']);
            $app  = ltrim((string) strstr($q['tag'], '.'), '.') ?: 'tiknix';
            $dir  = \Model_Instance::dirForSlug((string) $slug, (string) $app);
            $inst = CoreDb::with(fn() => Bean::findOne('instance', 'slug = ? AND app = ?', [$slug, $app]), null);
            // A project in its own container has no app in its folder here (that folder is the
            // builder's workspace): it is gone only when the registry no longer has it.
            $gone = ($inst && $inst->id && \Model_Instance::tenantRow($inst)) ? false : !is_file($dir . '/public/index.php');
            if ($gone) {
                self::dequeue($id);
                $out[] = "#$id project {$q['tag']} is gone — dequeued";
                continue;
            }

            // '' rather than 'claude': an empty engine should fall through to the
            // PROJECT's own .aibuilder/engine (app\AgentContext), not be overruled here.
            $engine = $inst && $inst->id ? (string) ($inst->engine ?? '') : '';

            $runner = new PlanRunner($slug, $dir, $q['member_id'],
                (int) ($inst->memberLevel ?? 50), $engine);
            // In the app's container, on its own agent — as the Builder's own rerun does.
            if ($inst && $inst->id && \Model_Instance::tenantRow($inst)) $runner->useAgent('');

            // STILL busy: leave it queued and do NOT spend an attempt. Attempts are for
            // things that went wrong, not for the condition the queue exists to wait out.
            if ($runner->running()) { $out[] = "#$id still waiting on {$q['tag']}"; continue; }
            // And while an earlier request is still being built: this one is planned against
            // what that leaves in the app, not beside it.
            if (($busy = BuildQueue::busyWith($dir, (string) $slug)) !== '') { $out[] = "#$id still waiting on {$q['tag']}: {$busy}"; continue; }
            // Nor while a planner is still at work in the app's container (the wait for it here
            // can end before it does): cannot ask = still waiting, never "free".
            try { $inApp = $runner->containerPlanner(); }
            catch (\Throwable $e) { $out[] = "#$id still waiting on {$q['tag']}: its container could not be asked (" . $e->getMessage() . ')'; continue; }
            if ($inApp !== '') { $out[] = "#$id still waiting on {$q['tag']}: {$inApp} is still planning there"; continue; }
            // What it waited for is in the app now: its planner is told what that was.
            $runner->builtBefore(BuildQueue::builtSince($dir, (string) $q['queued_at']));
            // A follow-up ("make that bold") names the plan it continues (app\AskStore): its planner is handed what that plan built.
            try { $msg = AskStore::get($dir, $id); }
            catch (\Throwable $e) { $msg = null; $out[] = "#$id: the project's record could not be read for what it continues (" . $e->getMessage() . ')'; }
            if ($msg && (int) ($msg['follows_ref'] ?? 0) > 0) $runner->follows(BuildQueue::builtPlan($dir, (int) $msg['follows_ref']));

            try {
                $runner->start($q['body'], [], true, $id);
                CoreDb::with(function () use ($id) {
                    $row = Bean::load('promptlog', $id);
                    if ($row->id) { $row->queuedAt = null; $row->queueAttempts = (int) $row->queueAttempts + 1; Bean::store($row); }
                    return null;
                });
                $out[] = "#$id started on {$q['tag']} (straight-through)";
            } catch (\Throwable $e) {
                CoreDb::with(function () use ($id) {
                    $row = Bean::load('promptlog', $id);
                    if ($row->id) { $row->queueAttempts = (int) $row->queueAttempts + 1; Bean::store($row); }
                    return null;
                });
                $out[] = "#$id failed: " . $e->getMessage();
            }
        }
        return $out;
    }
}
