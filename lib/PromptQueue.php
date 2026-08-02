<?php
/**
 * PromptQueue — retry a decompose that was refused because the project was busy.
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
            if (!is_file($dir . '/public/index.php')) {
                self::dequeue($id);
                $out[] = "#$id project {$q['tag']} is gone — dequeued";
                continue;
            }

            $inst = CoreDb::with(fn() => Bean::findOne('instance', 'slug = ? AND app = ?', [$slug, $app]), null);
            $engine = $inst && $inst->id ? (string) ($inst->engine ?: 'claude') : 'claude';

            $runner = new PlanRunner($slug, $dir, $q['member_id'],
                (int) ($inst->memberLevel ?? 50), $engine);

            // STILL busy: leave it queued and do NOT spend an attempt. Attempts are for
            // things that went wrong, not for the condition the queue exists to wait out.
            if ($runner->running()) { $out[] = "#$id still waiting on {$q['tag']}"; continue; }

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
