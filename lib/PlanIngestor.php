<?php

namespace app;

use RedBeanPHP\R;
use app\EngineRegistry;

/**
 * PlanIngestor — turn a decomposed plan (from the planner's plan.json) into a
 * workbench task tree (parent + subtasks with a dependency DAG), tagged to an
 * instance. Shared by the AI Builder web endpoint and the headless CLI ingester
 * so both produce identical trees.
 *
 * The file hand-off uses an ATOMIC CLAIM (rename) so the browser poll and the
 * server-side (planner-exit) ingester can never double-ingest the same plan.
 */
class PlanIngestor
{
    /**
     * Atomically claim a plan.json for ingestion. Returns the claimed path (to
     * read + delete when done), or null if it is gone / already claimed by the
     * other ingester. rename() is atomic on a single filesystem, so exactly one
     * caller wins.
     */
    public static function claim(string $planFile): ?string
    {
        if (!is_file($planFile)) return null;
        $claim = $planFile . '.ingesting';
        return @rename($planFile, $claim) ? $claim : null;
    }

    /**
     * Every unclaimed plan file in an instance, oldest first.
     *
     * There used to be exactly one name — plan.json — which meant two members planning the
     * same shared instance at once silently overwrote each other (PlanRunner locks per
     * MEMBER, not per instance, so both are allowed to run). Plans are now written under
     * unique names and ingested one at a time.
     *
     * The bare plan.json is still matched so a plan written by an older planner, or one
     * recovered by hand, is not stranded by this change.
     */
    public static function pending(string $instanceDir): array
    {
        $dir   = rtrim($instanceDir, '/') . '/.aibuilder';
        $files = array_merge(
            glob($dir . '/*.plan.json') ?: [],
            is_file($dir . '/plan.json') ? [$dir . '/plan.json'] : []
        );
        // Oldest first: if two landed, the one that has been waiting longer goes first.
        usort($files, fn($a, $b) => (int) @filemtime($a) <=> (int) @filemtime($b));
        return $files;
    }

    /** {title, subtasks:[...]} shape check. */
    public static function isValidPlan($plan): bool
    {
        return is_array($plan) && !empty($plan['title'])
            && !empty($plan['subtasks']) && is_array($plan['subtasks']);
    }

    /**
     * Persist a decomposed plan as a workbench task tree.
     *
     * @param object $inst          the instance bean (slug, id, engine, app)
     * @param array  $plan          decoded plan {title, summary, subtasks:[...]}
     * @param int    $memberId      owner
     * @param string $checkpointTag optional pre-plan baseline checkpoint tag
     * @param string $app           app suffix for the instance tag (default tiknix)
     * @return array {parent, checkpoint, subtasks[]}
     */
    public static function ingest($inst, array $plan, int $memberId, string $checkpointTag = '', string $app = 'tiknix'): array
    {
        $tag = $inst->slug . '.' . $app;
        $now = date('Y-m-d H:i:s');

        $parent = R::dispense('workbenchtask');
        $parent->title          = mb_substr((string)$plan['title'], 0, 200);
        $parent->description    = (string)($plan['summary'] ?? '');
        $parent->taskType       = 'feature';
        $parent->priority       = 2;
        $parent->status         = 'pending';
        $parent->instanceId     = (int)$inst->id;
        $parent->instanceTag    = $tag;
        $parent->engine         = $inst->engine;
        $parent->memberId       = $memberId;
        $parent->planCheckpoint = $checkpointTag;
        // A plan's identity that does NOT depend on which database it lives in.
        //
        // Row ids are only unique inside one instance's workbench.db, and those files get
        // rebuilt — discotuba's has been — so "plan #12" can later mean a different plan
        // entirely. Anything outside this database that wants to name a plan (core's prompt
        // log does) needs a handle that cannot be reassigned, and a foreign key cannot span
        // the two databases anyway. Minted once, here, and never reused.
        $parent->planUid        = bin2hex(random_bytes(8));
        $parent->planStatus     = 'draft';
        $parent->createdAt      = $now;
        // updatedAt is NOT optional: the task board sorts by it (order_by defaults to
        // 'updated_at DESC'), and RedBean's fluid mode only creates a column when
        // something writes it. Omitting it left a freshly-decomposed project with a
        // table that had no updated_at at all — so the board's own query referenced a
        // missing column, RedBean suppressed the error the way fluid mode does, and the
        // board showed "No Tasks Found" next to a counter reading ten.
        $parent->updatedAt      = $now;
        R::store($parent);

        // Pass 1: create every subtask, remembering the planner's stable ref.
        $rows = [];
        $refMap = [];
        $i = 0;
        foreach ($plan['subtasks'] as $st) {
            if (empty($st['title'])) continue;
            $i++;
            $ref = trim((string)($st['id'] ?? '')) ?: ('t' . $i);
            $t = R::dispense('workbenchtask');
            $t->title        = mb_substr((string)$st['title'], 0, 200);
            $t->description  = (string)($st['description'] ?? '');
            $t->taskType     = 'feature';
            $t->priority     = (int)($st['priority'] ?? 3);
            $t->status       = 'pending';
            $t->parentTaskId = (int)$parent->id;
            $t->instanceId   = (int)$inst->id;
            $t->instanceTag  = $tag;
            $t->engine       = EngineRegistry::coerce($st['engine'] ?? null, (string)$inst->engine);
            $t->relatedFiles = json_encode(is_array($st['files'] ?? null) ? array_values($st['files']) : []);
            $t->reuses       = json_encode(is_array($st['reuses'] ?? null) ? array_values($st['reuses']) : []);
            $t->planRef      = $ref;
            $t->memberId     = $memberId;
            $t->createdAt    = $now;
            $t->updatedAt    = $now;   // see the parent: the board sorts on this
            R::store($t);
            $refMap[$ref] = (int)$t->id;
            $rows[] = [$t, $st, $ref];
        }

        // Pass 2: resolve depends_on (planner refs) to concrete db task ids.
        $subs = [];
        foreach ($rows as [$t, $st, $ref]) {
            $deps = [];
            foreach ((array)($st['depends_on'] ?? []) as $d) {
                $d = trim((string)$d);
                if ($d !== '' && isset($refMap[$d]) && $refMap[$d] !== (int)$t->id) {
                    $deps[] = $refMap[$d];
                }
            }
            $deps = array_values(array_unique($deps));
            $t->dependsOn = json_encode($deps);
            R::store($t);
            $subs[] = [
                'id' => (int)$t->id, 'ref' => $ref, 'title' => $t->title,
                'priority' => (int)$t->priority, 'engine' => $t->engine, 'depends_on' => $deps,
                'reuses' => json_decode((string)$t->reuses, true) ?: [],
            ];
        }

        return [
            'parent'     => ['id' => (int)$parent->id, 'title' => $parent->title],
            'checkpoint' => $checkpointTag,
            'subtasks'   => $subs,
        ];
    }
}
