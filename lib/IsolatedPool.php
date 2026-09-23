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
}
