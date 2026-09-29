<?php
/**
 * Pipeline\Scheduler — the app schedules itself; the platform only ticks.
 *
 * Once a minute something POSTs /pipeline/tick (core's heartbeat for co-located
 * instances, a tenant's own crontab elsewhere) and THIS install decides what is due:
 * it reads its own definitions — its pipelines/ and its enabled concepts' — fires every
 * cron trigger that matches this minute, then wakes the durable objects whose alarms
 * are due. Nothing outside the install reads its files or its flags to make that call.
 *
 * Before this, core's cron globbed every install's pipelines/*.json, opened their
 * databases for concept flags, and POSTed /pipeline/trigger/<slug> per pipeline plus
 * /pipeline/objecttick — the platform reading other installs' files to make a decision
 * only the app can make, and no cron at all for an install on another host.
 *
 * Once per minute per pipeline, claimed BEFORE firing in <root>/cache/pipecron-<slug>.last,
 * so two heartbeats in the same minute (a slow tick overlapping the next) cannot fire a
 * pipeline twice. The claim file is the same one the old cron used, so the cut-over
 * cannot double-fire either.
 */

namespace app\Pipeline;

class Scheduler {

    /**
     * @param string $root  the install
     * @param int    $now   the minute being ticked (tests drive this)
     * @return array{minute:string,checked:int,fired:array<int,array>,skipped:array<int,array>,objects:array}
     */
    public static function tick(string $root, ?int $now = null): array {
        $now = $now ?? time();
        $minute = date('Y-m-d H:i', $now);
        $loader = Loader::forInstall($root);
        $checked = 0; $fired = []; $skipped = [];

        foreach ($loader->all() as $slug => $def) {
            $cron = trim((string) ($def['trigger']['cron'] ?? ''));
            if ($cron === '') continue;
            $checked++;
            if (!Loader::validCron($cron)) {
                $skipped[] = ['slug' => $slug, 'why' => "invalid cron expression '{$cron}'"];
                continue;
            }
            if (!Cron::due($cron, $now)) continue;
            if (!self::claim($root, (string) $slug, $minute)) {
                $skipped[] = ['slug' => $slug, 'why' => 'already fired this minute'];
                continue;
            }
            try {
                $r = (new Dispatcher($root))->dispatch($def, [], 'cron');
                $fired[] = ['slug' => $slug, 'run_id' => (int) ($r['run_id'] ?? 0), 'concept' => $loader->originOf((string) $slug)];
            } catch (\Throwable $e) {
                $fired[] = ['slug' => $slug, 'run_id' => 0, 'error' => $e->getMessage()];
                error_log("ERROR Pipeline\\Scheduler: cron pipeline '{$slug}' could not be dispatched: " . $e->getMessage());
            }
        }

        try {
            $objects = (new ObjectRunner($root))->tick();
        } catch (\Throwable $e) {
            $objects = ['fired' => 0, 'objects' => [], 'error' => $e->getMessage()];
            error_log('ERROR Pipeline\\Scheduler: durable object tick failed: ' . $e->getMessage());
        }

        return ['minute' => $minute, 'checked' => $checked, 'fired' => $fired, 'skipped' => $skipped, 'objects' => $objects];
    }

    /**
     * Claim this minute for a slug. True when this call is the first this minute; false
     * when it was already claimed. Written before the dispatch, never after.
     */
    private static function claim(string $root, string $slug, string $minute): bool {
        $dir = rtrim($root, '/') . '/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Pipeline\\Scheduler: cannot create {$dir} for the once-per-minute claim.");
        }
        $mark = $dir . '/pipecron-' . preg_replace('/[^a-z0-9_-]/i', '', $slug) . '.last';
        if (trim((string) @file_get_contents($mark)) === $minute) return false;
        if (@file_put_contents($mark, $minute) === false) {
            throw new \RuntimeException("Pipeline\\Scheduler: cannot write {$mark}; refusing to fire without a claim.");
        }
        return true;
    }
}
