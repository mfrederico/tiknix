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
            'summary' => 'Sweep expired durable objects + prune old pipeline runs (core housekeeping).',
            'fields'  => [
                ['name' => 'prune_days', 'label' => 'Prune runs older than (days)', 'type' => 'number', 'help' => 'Delete completed/failed runs older than this. Default 30.'],
                ['name' => 'reschedule', 'label' => 'Re-arm alarm', 'type' => 'text', 'help' => 'When to run next, e.g. "+1 hour" — emitted as __alarm so the object self-perpetuates.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $days   = max(1, (int) ($config['prune_days'] ?? 30));
        $swept  = DurableObject::sweepExpired();
        $pruned = DurableObject::prunePipeRuns($days);
        $out = ['swept_objects' => $swept, 'pruned_runs' => $pruned, 'at' => date('Y-m-d H:i:s')];
        $reschedule = trim((string) ($config['reschedule'] ?? ''));
        if ($reschedule !== '') $out['__alarm'] = $reschedule;
        return ['ok' => true, 'output' => $out, 'stdout' => json_encode($out, JSON_UNESCAPED_SLASHES), 'stderr' => '', 'exit' => 0];
    }
}
