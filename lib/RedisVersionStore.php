<?php
/**
 * Redis/Valkey-backed generation counters. See CacheVersionStore for the design.
 */

namespace app;

/**
 * Valkey/Redis-backed versions — shared by every process on the box.
 *
 * One connection per PHP process, reused by every adapter (a request talking to core's db
 * and two instance databases opens one socket, not three).
 */
class RedisVersionStore implements CacheVersionStore {

    /** Version keys outlive payloads by a wide margin; see version() for why that matters. */
    private const TTL = 604800;   // 7 days

    /**
     * Connections POOLED BY TARGET, not one static for the whole class.
     *
     * They were flat statics, so the first instance to connect owned them and every later
     * instance reused that socket whatever host/port/db it had been given. A store aimed
     * at a dead port therefore reported reachable=true and described an endpoint it was
     * not talking to — which is exactly the lie the admin panel exists to prevent, and it
     * only surfaced because the failure path got tested. Keyed by target, each
     * configuration gets its own connection and its own failure state.
     *
     * Still shared per target, so a request touching core's db and two instance databases
     * opens one socket rather than three.
     *
     * @var array<string,\Redis|null>
     */
    private static array $pool = [];
    /** @var array<string,string> last error per target, for stats() and the log line */
    private static array $failures = [];

    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
        private int $db = 0,
        private string $auth = ''
    ) {}

    /** Identity of the server+db this instance was configured for. */
    private function target(): string {
        return $this->host . ':' . $this->port . '/' . $this->db;
    }

    private function conn() {
        $t = $this->target();
        if (array_key_exists($t, self::$pool)) return self::$pool[$t];

        if (!class_exists('\Redis')) {
            self::$failures[$t] = 'the php redis extension is not loaded';
            return self::$pool[$t] = null;
        }
        try {
            $r = new \Redis();
            // Short timeouts on purpose: a cache must never be what makes a page hang.
            if (!$r->connect($this->host, $this->port, 0.5, null, 0, 0.5)) {
                throw new \RuntimeException('connect returned false');
            }
            if ($this->auth !== '') $r->auth($this->auth);
            if ($this->db > 0) $r->select($this->db);
            return self::$pool[$t] = $r;
        } catch (\Throwable $e) {
            self::$failures[$t] = $e->getMessage();
            return self::$pool[$t] = null;
        }
    }

    public function usable(): bool {
        if ($this->conn() === null) {
            self::reportOnce('CacheVersionStore: cannot reach the version store at '
                . $this->target() . ' (' . (self::$failures[$this->target()] ?? 'unknown')
                . ') — QUERY CACHING DISABLED. Not falling back to APCu versions: those are '
                . 'invisible to other processes, so a CLI write would stop invalidating web '
                . 'reads without anything saying so.');
            return false;
        }
        return true;
    }

    public function version(string $key): string {
        $c = $this->conn();
        if ($c === null || !$this->usable()) return '';
        try {
            $v = $c->get($key);
            if ($v === false || $v === null || $v === '') {
                // Mint a UNIQUE generation, never a counter starting at 1. A version key
                // that expires and restarts at a value a stale payload already recorded
                // would read as a HIT and serve pre-write rows. The 7-day TTL is far
                // longer than any payload TTL, so this is belt and braces.
                $v = ApcuVersionStore::mint();
                $c->set($key, $v, ['nx', 'ex' => self::TTL]);
                $got = $c->get($key);
                if (is_string($got) && $got !== '') $v = $got;   // lost the race: take the winner
            }
            return (string) $v;
        } catch (\Throwable $e) {
            return $this->die($e);
        }
    }

    public function bump(string $key): void {
        $c = $this->conn();
        if ($c === null || !$this->usable()) return;
        try {
            $c->set($key, ApcuVersionStore::mint(), ['ex' => self::TTL]);
        } catch (\Throwable $e) {
            $this->die($e);
        }
    }

    /** A store that fails mid-request stops being used, loudly, for the rest of it. */
    private function die(\Throwable $e): string {
        $t = $this->target();
        self::$pool[$t] = null;              // this target only — not every target
        self::$failures[$t] = $e->getMessage();
        self::reportOnce('CacheVersionStore: version store at ' . $t . ' failed mid-request ('
            . $e->getMessage() . ') — query caching disabled for the rest of this request.');
        return '';
    }

    /** A network service has no process boundary — fpm, CLI and cron all see one namespace. */
    public function isShared(): bool { return true; }

    public function describe(): string {
        return 'redis/valkey ' . $this->host . ':' . $this->port . ' db' . $this->db;
    }

    public function stats(): array {
        $c = $this->conn();
        $reachable = ($c !== null) && $this->usable();
        $keys = null; $server = '';
        if ($reachable) {
            try {
                $keys = $c->dbSize();
                $info = $c->info('server');
                $server = $info['valkey_version'] ?? $info['redis_version'] ?? '';
            } catch (\Throwable $e) { /* stats are cosmetic; never break the page */ }
        }
        return [
            'driver'    => 'valkey',
            'endpoint'  => $this->host . ':' . $this->port . ' db' . $this->db
                         . ($server !== '' ? ' (v' . $server . ')' : ''),
            'reachable' => $reachable,
            'shared'    => true,
            'keys'      => $keys,
            'note'      => $reachable
                ? 'Shared by every process, so CLI writes invalidate web reads.'
                : 'UNREACHABLE - query caching is currently DISABLED (see the log). It does '
                . 'not fall back to apcu, because that would hide cross-process staleness.',
        ];
    }

    private static array $reported = [];

    /** Say it once per process, not once per query — this runs in a hot path. */
    private static function reportOnce(string $msg): void {
        if (isset(self::$reported[$msg])) return;
        self::$reported[$msg] = true;
        try {
            if (class_exists('Flight') && \Flight::has('log')) { \Flight::get('log')->error($msg); return; }
        } catch (\Throwable $e) { /* fall through */ }
        error_log($msg);
    }
}
