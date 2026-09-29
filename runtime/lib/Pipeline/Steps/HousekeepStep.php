<?php
/**
 * housekeep — GC step for the core housekeeping durable object: sweeps expired
 * durable objects and prunes old finished pipeline runs, then emits an `__alarm` so
 * the object re-arms itself. Operates on the app it runs in (Runner::root()).
 */

namespace app\Pipeline\Steps;

use app\Pipeline\DurableObject;

class HousekeepStep implements StepInterface {

    public static function type(): string { return 'housekeep'; }

    public static function schema(): array {
        return [
            'summary'  => 'Sweep expired durable objects + prune old pipeline runs (the garbage collector\'s baseline sweep).',
            'internal' => true,   // runtime plumbing — not offered as a general build block
            'fields'   => [
                ['name' => 'prune_days', 'label' => 'Prune runs older than (days)', 'type' => 'number', 'help' => 'Delete completed/failed runs older than this. Default 30.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $days   = max(1, (int) ($config['prune_days'] ?? 30));
        $swept  = DurableObject::sweepExpired();
        $pruned = DurableObject::prunePipeRuns($days);
        // Scheduling is owned by the runtime (ObjectRunner::ensureGarbageCollector), so
        // this step never re-arms — appended cleanup steps can't accidentally break the cadence.
        $out = ['swept_objects' => $swept, 'pruned_runs' => $pruned, 'at' => date('Y-m-d H:i:s')];
        return ['ok' => true, 'output' => $out, 'stdout' => json_encode($out, JSON_UNESCAPED_SLASHES), 'stderr' => '', 'exit' => 0];
    }
}
