<?php
/**
 * IsolatedPool — "is this process already confined to the instance?"
 *
 * An isolated instance runs as its own OS user (tiknix-i<id>), in its own php-fpm pool, with
 * open_basedir set to its tree. That confinement IS the jail, so code running there must not
 * wrap what it launches in jail-run.sh: the script is outside the boundary, it is built to be
 * run by the operator, and jailing a jail is redundant at best.
 *
 * Four launchers ask this question (Pipeline\Dispatcher, PlanExecutor, AuditRunner,
 * PlanRunner) and each used to answer it alone, with
 *
 *     ini_get('open_basedir') !== ''
 *
 * which is a PROXY, and only true in a web request. open_basedir comes from the FPM pool's
 * config; a CLI process started BY that pool — a pipeline worker, and anything the worker
 * launches — reads the CLI php.ini and has none. So a worker fanning out child runs (the
 * lead-machine bulk-draft pipeline does exactly this) saw "not isolated", reached for
 * jail-run.sh as tiknix-i<id>, and would have failed for the first real user to click it.
 *
 * The fact itself is about WHO the process is, not which php.ini it read: the instance tree is
 * owned by the provisioning user, and the pool runs as somebody else. That holds for the web
 * request and for every process descended from it.
 */

namespace app;

class IsolatedPool {

    /** Written at the instance root by provisioning when the instance has its own pool + uid. */
    public const MARKER = '.fpm-isolated';

    /**
     * @param string   $root the instance root
     * @param int|null $euid this process's effective uid — injectable for tests
     */
    public static function inside(string $root, ?int $euid = null): bool {
        // A web request in the pool: the pool config says so directly.
        if ((string) ini_get('open_basedir') !== '') return true;

        $root = rtrim($root, '/');
        if ($root === '' || !@is_file($root . '/' . self::MARKER)) return false;   // not an isolated instance at all

        if ($euid === null) {
            if (!function_exists('posix_geteuid')) {
                throw new \RuntimeException(
                    'IsolatedPool: ext-posix is not loaded, so this process cannot tell whether it is the '
                  . "instance's isolated user. It is required on any host that runs isolated instances.");
            }
            $euid = posix_geteuid();
        }
        $owner = @fileowner($root);
        if ($owner === false) {
            throw new \RuntimeException("IsolatedPool: cannot read the owner of {$root}.");
        }
        // The tree belongs to the provisioning user. Anyone else running here is the pool.
        return $euid !== $owner;
    }

    /**
     * The opposite question, for WRITERS of the instance's own data: is this the tree owner
     * (the provisioning user, on the CLI) acting on an isolated instance? A file that user
     * creates under data/ or secure/ gets its ACL mask from the create mode — SQLite opens at
     * 0644, so mask r-- — and the pool, which is not the owner, is left with a read-only
     * store ("attempt to write a readonly database"). Serenity's connections.db, 2026-09-28.
     * Such a write belongs to the pool: runAsPool() below, or the app itself.
     */
    public static function ownerOnIsolated(string $root, ?int $euid = null): bool {
        $root = rtrim($root, '/');
        if ($root === '' || !@is_file($root . '/' . self::MARKER)) return false;
        if ((string) ini_get('open_basedir') !== '') return false;   // a pool request is never the owner
        if ($euid === null) {
            if (!function_exists('posix_geteuid')) throw new \RuntimeException('IsolatedPool: ext-posix is not loaded; cannot tell the tree owner from the pool user.');
            $euid = posix_geteuid();
        }
        $owner = @fileowner($root);
        if ($owner === false) throw new \RuntimeException("IsolatedPool: cannot read the owner of {$root}.");
        return $euid === $owner;
    }

    /** The pool's OS user (`tiknix-i<id>`), from the socket name; '' when the instance is not isolated. */
    public static function user(string $root): string {
        $root = rtrim($root, '/');
        if (!@is_file($root . '/' . self::MARKER)) return '';
        $name = basename(self::socket($root), '.sock');
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $name)) throw new \RuntimeException("IsolatedPool: socket name '{$name}' is not a pool user name.");
        return $name;
    }

    /** The pool's FastCGI socket, from the marker (`SOCK=/run/php/tiknix-i<id>.sock`). */
    public static function socket(string $root): string {
        $root = rtrim($root, '/');
        $marker = (string) @file_get_contents($root . '/' . self::MARKER);
        if (!preg_match('/^SOCK=(\S+)/m', $marker, $m)) {
            throw new \RuntimeException("IsolatedPool: {$root}/" . self::MARKER . ' names no SOCK=… — provisioning writes it; this instance cannot be reached as its pool user.');
        }
        return $m[1];
    }

    /**
     * Run a piece of application code as the pool user, with the app booted: chdir to the
     * instance, bootstrap, then $body (PHP statements; `use` statements are not allowed —
     * name classes fully). What --build and --concept-seeds do when the tree owner runs them
     * on an isolated instance (§13 C3): every file a seed creates then belongs to the pool.
     *
     * @return array{status:int,output:string}
     */
    public static function runAsPoolBooted(string $root, string $body): array {
        $root = rtrim($root, '/');
        // Under FPM the query cache (APCu, per SAPI) holds the table list, and RedBean's
        // fluid mode reads it: a seed that creates a table is then told the table is still
        // missing and issues CREATE TABLE twice ("table `site` already exists" on two
        // instances' first update, 2026-09-28). Seeds run with the cache OFF for this
        // request and clear it afterwards so the web sees what they built.
        $php = '<?php set_time_limit(0); ini_set("display_errors", "1"); chdir(' . var_export($root, true) . '); '
             . 'require "bootstrap.php"; new \app\Bootstrap(); '
             . '$__ca = \Flight::get("cachedDatabaseAdapter"); if ($__ca instanceof \app\CachedDatabaseAdapter) $__ca->disableCache(); '
             . 'try { ' . $body . ' } finally { if ($__ca instanceof \app\CachedDatabaseAdapter) { $__ca->clearAllCache(); $__ca->enableCache(); } }';
        return self::runAsPool($root, $php);
    }

    /**
     * Run PHP as the instance's pool user, through its php-fpm socket (cgi-fcgi), and return
     * what it printed. The one way a CLI process owned by the provisioning user can create or
     * write the instance's own data without leaving the pool locked out of it. The script is
     * written under the system temp dir, which every pool's open_basedir includes, and
     * removed afterwards. $php is the whole file, `<?php` included; boot the app yourself
     * (`chdir($root); require 'bootstrap.php'; new \app\Bootstrap();`) when it needs one.
     *
     * @return array{status:int,output:string}
     * @throws \RuntimeException when cgi-fcgi or the socket is missing — never a quiet ''
     */
    public static function runAsPool(string $root, string $php): array {
        $root = rtrim($root, '/');
        $sock = self::socket($root);
        if (!file_exists($sock)) throw new \RuntimeException("IsolatedPool: the pool socket {$sock} is not there — is php-fpm running this instance's pool?");
        $bin = trim((string) shell_exec('command -v cgi-fcgi 2>/dev/null'));
        if ($bin === '') throw new \RuntimeException('IsolatedPool: cgi-fcgi is not installed (apt install libfcgi-bin); nothing can run as the pool user from here.');
        $file = sys_get_temp_dir() . '/tiknix-pool-' . bin2hex(random_bytes(6)) . '.php';
        if (file_put_contents($file, $php) !== strlen($php)) throw new \RuntimeException("IsolatedPool: could not write {$file}.");
        @chmod($file, 0644);   // a plain temp file: the pool user must be able to read it (no ACL here)
        try {
            $env = 'SCRIPT_FILENAME=' . escapeshellarg($file) . ' REQUEST_METHOD=GET SCRIPT_NAME=/pool QUERY_STRING= REQUEST_URI=/pool';
            $out = [];
            exec($env . ' ' . escapeshellarg($bin) . ' -bind -connect ' . escapeshellarg($sock) . ' 2>&1', $out, $status);
            $raw = implode("\n", $out);
            // FastCGI answers with headers first; the body is what the script printed.
            $body = preg_replace('/\A(?:[^\r\n]+\r?\n)*?\r?\n/', '', $raw, 1) ?? $raw;
            return ['status' => (int) $status, 'output' => trim($body)];
        } finally {
            @unlink($file);
        }
    }
}
