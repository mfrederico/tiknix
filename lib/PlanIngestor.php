<?php

namespace app;

use RedBeanPHP\R;

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
        $parent->planStatus     = 'draft';
        $parent->createdAt      = $now;
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
            $t->engine       = in_array($st['engine'] ?? '', ['claude', 'qwen'], true) ? $st['engine'] : $inst->engine;
            $t->relatedFiles = json_encode(is_array($st['files'] ?? null) ? array_values($st['files']) : []);
            $t->planRef      = $ref;
            $t->memberId     = $memberId;
            $t->createdAt    = $now;
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
            ];
        }

        return [
            'parent'     => ['id' => (int)$parent->id, 'title' => $parent->title],
            'checkpoint' => $checkpointTag,
            'subtasks'   => $subs,
        ];
    }
}
