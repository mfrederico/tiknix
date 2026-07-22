<?php
/**
 * Pipeline\Dispatcher — run a pipeline in the BACKGROUND so a long run (esp. `agent`
 * steps) never blocks a web/MCP request. Creates a 'queued' piperun, then spawns a
 * detached `php scripts/pipeline-run.php --run=<id>` — inside the instance's jail
 * (jail-run.sh's JAIL_CMD, mirroring PlanExecutor) when the app root is a capricorn
 * instance, else direct (hook-sandboxed). Returns { run_id, status } immediately;
 * poll pipeline_run_get. Short pipelines can still run sync via Runner::run().
 */

namespace app\Pipeline;

use \Flight as Flight;

class Dispatcher {

    private string $root;

    public function __construct(string $root) {
        $this->root = rtrim($root, '/');
    }

    /** Queue a run + spawn its background worker. Returns the run summary. */
    public function dispatch(array $def, array $context = [], string $source = 'trigger'): array {
        $run = (new Executor($this->root))->newRun($def, $context, $source, 'queued');
        $runId = (int) $run->id;

        $script = $this->root . '/scripts/pipeline-run.php';
        $inner  = 'php ' . escapeshellarg($script) . ' --run=' . $runId;
        $log    = escapeshellarg((string) $run->runDir . '/worker.log');

        $jail = $this->jailFor();
        if ($jail !== '') {
            // jail-run.sh <workspace> runs JAIL_CMD inside the bwrap jail.
            $cmd = 'JAIL_CMD=' . escapeshellarg($inner) . ' ' . escapeshellarg($jail) . ' ' . escapeshellarg($this->root);
        } else {
            $cmd = 'cd ' . escapeshellarg($this->root) . ' && ' . $inner;
        }
        // Detach: nohup + background so the web/MCP request returns now.
        @exec('nohup bash -lc ' . escapeshellarg($cmd . ' >> ' . $log . ' 2>&1') . ' > /dev/null 2>&1 &');

        return ['run_id' => $runId, 'run_uid' => (string) $run->runUid, 'status' => 'queued',
                'poll' => 'pipeline_run_get(run_id)'];
    }

    /** jail-run.sh path when the app root is a jailable capricorn instance, else ''. */
    private function jailFor(): string {
        $base = '/var/www/html/default';
        $real = realpath($this->root) ?: $this->root;
        if (strpos(basename($real), '.') === false) return '';        // <slug>.<app> dirs only
        if (strpos($real, $base . '/') !== 0) return '';
        if (!is_file("$real/public/index.php")) return '';
        $ini = @parse_ini_file($this->root . '/conf/aibuilder.ini', true) ?: [];
        $binDir = rtrim($ini['ops']['bin_dir'] ?? '/home/ubuntu/capricorn/bin', '/');
        $script = "$binDir/jail-run.sh";
        return is_file($script) ? $script : '';
    }
}
