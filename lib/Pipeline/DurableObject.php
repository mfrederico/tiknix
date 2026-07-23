<?php
/**
 * Pipeline\DurableObject — a PHP "durable object" (FPM/cron model). An addressable,
 * stateful actor keyed by (type, key), with its state persisted in the instance DB
 * and a single-writer lease lock so only ONE invocation runs against its storage at
 * a time (php-fpm has real concurrency, unlike a Cloudflare isolate — the lock is
 * what buys the guarantee). Alarms are a `wake_at` the cron tick scans.
 *
 * PHP is already "hibernated by default": every request is cold, state only lives in
 * storage. So an object is just a row; a message/alarm is a wake → load → run → save.
 *
 * bean `dobject`: type, obj_key, slug (handler), state_json, wake_at, locked_at,
 * lock_token, created_at, updated_at.  Times are unix seconds (0 = none).
 */

namespace app\Pipeline;

use app\Bean;
use RedBeanPHP\R;

class DurableObject {

    /** Seconds a lease is honored before it's considered stale (crashed holder). */
    public const LEASE_TTL = 30;

    private $bean;
    private array $state;
    private string $lockToken = '';

    private function __construct($bean) {
        $this->bean = $bean;
        $this->state = json_decode((string) $bean->stateJson, true) ?: [];
    }

    /** Load-or-create the object (type,key); (re)records its handler slug. */
    public static function open(string $type, string $key, string $slug = ''): self {
        $b = Bean::findOne('dobject', 'type = ? AND obj_key = ?', [$type, $key]);
        if (!$b || !$b->id) {
            $b = Bean::dispense('dobject');
            $b->type = $type; $b->objKey = $key; $b->slug = $slug;
            $b->stateJson = '{}'; $b->wakeAt = 0; $b->lockedAt = 0; $b->lockToken = ''; $b->expiresAt = 0;
            $b->createdAt = time(); $b->updatedAt = time();
            Bean::store($b);
        } elseif ($slug !== '' && (string) $b->slug !== $slug) {
            $b->slug = $slug; Bean::store($b);
        }
        return new self($b);
    }

    public function id(): int { return (int) $this->bean->id; }
    public function type(): string { return (string) $this->bean->type; }
    public function key(): string { return (string) $this->bean->objKey; }
    public function slug(): string { return (string) $this->bean->slug; }
    public function state(): array { return $this->state; }
    public function wakeAt(): int { return (int) $this->bean->wakeAt; }

    /**
     * Atomic single-writer lease. A conditional UPDATE (compare-and-set) so two
     * concurrent workers can never both win. Returns true if we hold the lock.
     */
    public function acquire(): bool {
        $now = time(); $stale = $now - self::LEASE_TTL; $token = bin2hex(random_bytes(8));
        try {
            $aff = (int) R::exec(
                'UPDATE dobject SET locked_at = ?, lock_token = ? WHERE id = ? AND (locked_at = 0 OR locked_at < ?)',
                [$now, $token, $this->id(), $stale]
            );
        } catch (\Throwable $e) {
            return false;   // transient contention (e.g. SQLITE_BUSY) — the retry loop re-tries
        }
        if ($aff === 1) { $this->lockToken = $token; return true; }
        return false;
    }

    public function release(): void {
        if ($this->lockToken === '') return;
        R::exec('UPDATE dobject SET locked_at = 0, lock_token = ? WHERE id = ? AND lock_token = ?',
            ['', $this->id(), $this->lockToken]);
        $this->lockToken = '';
    }

    /** Re-read fresh state from storage — call AFTER acquiring the lock (avoids a lost update). */
    public function reload(): void {
        $b = R::load('dobject', $this->id());
        if ($b->id) { $this->bean = $b; $this->state = json_decode((string) $b->stateJson, true) ?: []; }
    }

    public function setState(array $state): void { $this->state = $state; }
    public function mergeState(array $patch): void { $this->state = array_merge($this->state, $patch); }

    /** Schedule the next self-wake. $when: unix ts | relative string ("+5 minutes") | 0/'' to clear. */
    public function setAlarm($when): void {
        $ts = is_int($when) ? $when : (is_numeric($when) ? (int) $when : (int) strtotime((string) $when));
        $this->bean->wakeAt = ($ts > 0) ? $ts : 0;
    }
    public function clearAlarm(): void { $this->bean->wakeAt = 0; }

    /** Opt into a TTL — the object is GC'd once expired. $seconds<=0 clears it (lives forever). */
    public function setTtl(int $seconds): void {
        $this->bean->expiresAt = $seconds > 0 ? time() + $seconds : 0;
    }

    /** GC: delete objects whose TTL has expired (only ones that opted into a TTL). Returns count. */
    public static function sweepExpired(int $limit = 500): int {
        $expired = Bean::find('dobject', 'expires_at > 0 AND expires_at <= ? ORDER BY expires_at LIMIT ' . (int) $limit, [time()]);
        $n = 0;
        foreach ($expired as $o) { Bean::trash($o); $n++; }
        return $n;
    }

    /** GC: prune finished pipeline runs (+ their step-runs) older than $days. Returns runs removed. */
    public static function prunePipeRuns(int $days = 30, int $limit = 500): int {
        if ($days < 1) return 0;
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $runs = Bean::find('piperun', "status IN ('completed','failed') AND finished_at != '' AND finished_at < ? ORDER BY id LIMIT " . (int) $limit, [$cutoff]);
        $n = 0;
        foreach ($runs as $r) {
            foreach (Bean::find('pipesteprun', 'run_id = ?', [(int) $r->id]) as $s) Bean::trash($s);
            Bean::trash($r); $n++;
        }
        return $n;
    }

    public function save(): void {
        $this->bean->stateJson = json_encode($this->state, JSON_UNESCAPED_SLASHES);
        $this->bean->updatedAt = time();
        Bean::store($this->bean);
    }

    public function destroy(): void { Bean::trash($this->bean); }

    /**
     * Ensure (type,key) exists and is scheduled: if it's currently UNARMED, set its
     * alarm to $when. Used to keep a recurring object (the garbage collector) on a
     * fixed cadence independent of what its handler outputs — so appended steps can't
     * break the schedule. No-op if the object is busy (a run/tick will re-schedule).
     */
    public static function arm(string $type, string $key, string $slug, $when): void {
        $o = self::open($type, $key, $slug);
        if (!$o->acquire()) return;
        try {
            $o->reload();
            if ($o->wakeAt() <= 0) { $o->setAlarm($when); $o->save(); }
        } finally {
            $o->release();
        }
    }

    /** Objects whose alarm is due (for the tick). */
    public static function due(?int $now = null): array {
        $now = $now ?? time();
        return Bean::find('dobject', 'wake_at > 0 AND wake_at <= ? ORDER BY wake_at', [$now]);
    }
}
