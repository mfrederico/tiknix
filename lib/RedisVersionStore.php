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

    private static $conn = null;
    private static bool $tried = false;
    private static string $failure = '';

    private bool $dead = false;

    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
        private int $db = 0,
        private string $auth = ''
    ) {}

    private function conn() {
        if (self::$tried) {
            $this->dead = (self::$conn === null);
            return self::$conn;
        }
        self::$tried = true;

        if (!class_exists('\Redis')) {
            self::$failure = 'the php redis extension is not loaded';
            $this->dead = true;
            return null;
        }
        try {
            $r = new \Redis();
            // Short timeouts on purpose: a cache must never be what makes a page hang.
            if (!$r->connect($this->host, $this->port, 0.5, null, 0, 0.5)) {
                throw new \RuntimeException('connect returned false');
            }
            if ($this->auth !== '') $r->auth($this->auth);
            if ($this->db > 0) $r->select($this->db);
            self::$conn = $r;
        } catch (\Throwable $e) {
            self::$failure = $e->getMessage();
            self::$conn = null;
            $this->dead = true;
        }
        return self::$conn;
    }

    public function usable(): bool {
        $c = $this->conn();
        if ($c === null) {
            self::reportOnce('CacheVersionStore: cannot reach the version store at '
                . $this->host . ':' . $this->port . ' (' . self::$failure . ') — QUERY CACHING '
                . 'DISABLED. Not falling back to APCu versions: those are invisible to other '
                . 'processes, so a CLI write would stop invalidating web reads without anything '
                . 'saying so.');
            return false;
        }
        return true;
    }

    public function version(string $key): string {
        if (!$this->usable()) return '';
        try {
            $v = self::$conn->get($key);
            if ($v === false || $v === null || $v === '') {
                // Mint a UNIQUE generation, never a counter starting at 1. A version key
                // that expires and restarts at a value a stale payload already recorded
                // would read as a HIT and serve pre-write rows. The 7-day TTL is far
                // longer than any payload TTL, so this is belt and braces.
                $v = ApcuVersionStore::mint();
                self::$conn->set($key, $v, ['nx', 'ex' => self::TTL]);
                $got = self::$conn->get($key);
                if (is_string($got) && $got !== '') $v = $got;   // lost the race: take the winner
            }
            return (string) $v;
        } catch (\Throwable $e) {
            return $this->die($e);
        }
    }

    public function bump(string $key): void {
        if (!$this->usable()) return;
        try {
            self::$conn->set($key, ApcuVersionStore::mint(), ['ex' => self::TTL]);
        } catch (\Throwable $e) {
            $this->die($e);
        }
    }

    /** A store that fails mid-request stops being used, loudly, for the rest of it. */
    private function die(\Throwable $e): string {
        self::$conn = null;
        self::$failure = $e->getMessage();
        $this->dead = true;
        self::reportOnce('CacheVersionStore: version store failed mid-request ('
            . $e->getMessage() . ') — query caching disabled for the rest of this request.');
        return '';
    }

    /** A network service has no process boundary — fpm, CLI and cron all see one namespace. */
    public function isShared(): bool { return true; }

    public function describe(): string {
        return 'redis/valkey ' . $this->host . ':' . $this->port . ' db' . $this->db;
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
