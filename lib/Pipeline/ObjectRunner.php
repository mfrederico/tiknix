<?php
/**
 * Pipeline\ObjectRunner — delivers messages/alarms to durable objects and runs their
 * handler pipeline. The pipeline IS the object's onMessage/onAlarm handler: it runs
 * with the object's persisted `state`, the incoming `message`, and `trigger`
 * ('message'|'alarm') in the bag ({state.x}, {message.x}, {trigger}).
 *
 * State writeback convention: the handler's final output (an object) is shallow-merged
 * into the object's state. Two control keys steer the lifecycle:
 *   "__alarm": "+5 minutes" | <ts> | null   → setAlarm / clearAlarm
 *   "__destroy": true                        → delete the object
 *
 * Serialization: every delivery takes the object's single-writer lease first (with a
 * short spin-retry so concurrent messages queue instead of dropping), reloads fresh
 * state under the lock, runs, saves, releases. That's the "one invocation at a time".
 */

namespace app\Pipeline;

use RedBeanPHP\R;

class ObjectRunner {

    private string $root;

    public function __construct(string $root) {
        $this->root = rtrim($root, '/');
    }

    /** Deliver to object (slug,key). $trigger = 'message' | 'alarm'. */
    public function deliver(string $slug, string $key, array $message = [], string $trigger = 'message'): array {
        // SQLite instances: let writers wait on a busy DB instead of erroring (no-op elsewhere).
        try { R::exec('PRAGMA busy_timeout = 5000'); } catch (\Throwable $e) {}

        $def = (new Loader($this->root))->get($slug);
        if (!$def) throw new \RuntimeException("no such pipeline '$slug'");

        $obj = DurableObject::open('pipe:' . $slug, $key, $slug);
        if (!$this->acquireWithRetry($obj)) {
            return ['ok' => false, 'busy' => true, 'error' => 'object is busy'];
        }
        try {
            $obj->reload();                                   // fresh state under the lock
            if ($trigger === 'alarm') $obj->clearAlarm();     // consume the alarm (one-shot unless re-set)

            $extra = ['state' => $obj->state(), 'message' => $message, 'trigger' => $trigger,
                      'object' => ['type' => $obj->type(), 'key' => $obj->key()]];
            $res = (new Executor($this->root))->run($def, [], 'object:' . $trigger, $extra);

            $destroyed = $this->applyOutcome($obj, $res['output'] ?? null);
            if (!$destroyed) $obj->save();

            return ['ok' => (($res['status'] ?? '') === 'completed'), 'run_id' => (int) ($res['run_id'] ?? 0),
                    'status' => (string) ($res['status'] ?? ''), 'trigger' => $trigger,
                    'destroyed' => $destroyed, 'state' => $destroyed ? null : $obj->state(), 'wake_at' => $obj->wakeAt()];
        } finally {
            $obj->release();
        }
    }

    /** Fire onAlarm for every due object in this instance. */
    public function tick(): array {
        $this->ensureGarbageCollector();      // core dogfoods the runtime: a scheduled GC object
        $fired = [];
        $loader = new Loader($this->root);
        foreach (DurableObject::due() as $b) {
            $slug = (string) $b->slug; $key = (string) $b->objKey;
            if ($slug === '') continue;
            if (!$loader->get($slug)) { \app\Bean::trash($b); continue; }   // orphaned (handler pipeline gone) → GC it
            try { $r = $this->deliver($slug, $key, [], 'alarm'); $fired[] = ['type' => $b->type, 'key' => $key, 'ok' => $r['ok'] ?? false]; }
            catch (\Throwable $e) { $fired[] = ['type' => $b->type, 'key' => $key, 'error' => $e->getMessage()]; }
        }
        return ['fired' => count($fired), 'objects' => $fired];
    }

    private function acquireWithRetry(DurableObject $obj, int $tries = 50): bool {
        for ($i = 0; $i < $tries; $i++) {
            if ($obj->acquire()) return true;
            usleep(20000);   // 20ms; ~1s max wait — messages queue rather than drop
        }
        return false;
    }

    /** Interpret handler output: control keys (__alarm/__destroy) then merge into state. Returns true if destroyed. */
    private function applyOutcome(DurableObject $obj, $output): bool {
        $out = is_string($output) ? json_decode($output, true) : $output;
        if (!is_array($out)) return false;   // non-object output → no state change
        if (array_key_exists('__destroy', $out)) {
            if (!empty($out['__destroy'])) { $obj->destroy(); return true; }
            unset($out['__destroy']);
        }
        if (array_key_exists('__alarm', $out)) { $obj->setAlarm($out['__alarm']); unset($out['__alarm']); }
        if (array_key_exists('__ttl', $out))   { $obj->setTtl((int) $out['__ttl']); unset($out['__ttl']); }
        if ($out) $obj->mergeState($out);
        return false;
    }

    /**
     * Keep the instance's `garbagecollector` object scheduled. The runtime owns the
     * cadence (not the handler's output), so it survives any cleanup steps a user
     * appends: whenever the object is missing or unarmed, we arm it for the next
     * `gc_interval`. The tick then fires it when due; after it runs (unarmed again),
     * the next tick re-arms it. Self-healing, and immune to step-order changes.
     */
    private function ensureGarbageCollector(): void {
        $def = (new Loader($this->root))->get('garbagecollector');
        if (!$def) return;
        $obj = \app\Bean::findOne('dobject', 'type = ?', ['pipe:garbagecollector']);
        if ($obj && $obj->id && (int) $obj->wakeAt > 0) return;   // already scheduled
        $interval = trim((string) ($def['gc_interval'] ?? '')) ?: '+1 hour';
        DurableObject::arm('pipe:garbagecollector', 'main', 'garbagecollector', $interval);
    }
}
