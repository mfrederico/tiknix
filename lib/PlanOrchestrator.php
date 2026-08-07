<?php
/**
 * PlanOrchestrator — the ONE way to start the detached worktree orchestrator for a plan.
 *
 * There were four hand-rolled copies of this launch block (the Workbench sidecar, the
 * Advanced Builder sidecar, core's Firehose idle-sweep, and the audit's deferred-fix
 * spawn), and they had already drifted apart in the way that matters most:
 *
 *   - two of them exported TIKNIX_WORKBENCH_DB so the orchestrator (and every per-task
 *     agent it spawns) writes plan state to the INSTANCE's data/workbench.db;
 *   - two of them did not, so a plan launched down those paths wrote to core's db —
 *     invisible on the board it was launched from.
 *
 * That is the same failure family that made sidecar decomposes vanish. A launcher is
 * exactly the kind of code that gets copied and then quietly diverges, so there is now
 * one of it.
 *
 * It also keeps the rule that a launcher must never report success for a command that
 * cannot run: if scripts/plan-orchestrate.php is not where we think it is, this logs at
 * ERROR and returns false rather than starting a tmux session that dies in under a
 * second and leaves the plan sitting at "building" with no explanation.
 */

namespace app;

class PlanOrchestrator {

    /**
     * tmux session name for a plan's orchestrator. Single source for every caller.
     *
     * The slug is what keeps two projects' plan 26 apart — see
     * TmuxManager::buildPlanSessionName for why that matters. It is optional only so
     * a caller that genuinely has no instance in hand still gets the legacy name
     * rather than a broken one; every caller that CAN name the project MUST.
     */
    public static function sessionName(int $planId, string $slug = ''): string {
        return TmuxManager::buildPlanSessionName($planId, $slug);
    }

    /**
     * The live session for this plan, scoped or legacy, or '' when nothing is running.
     *
     * Both shapes are checked because an orchestrator started before the rename is
     * still running under the old name, and treating it as absent would launch a
     * SECOND orchestrator against the same plan: two tickers reaping and merging the
     * same worktrees. The legacy probe costs one tmux call and stops being reachable
     * on its own as those sessions end.
     */
    public static function liveSession(int $planId, string $slug = ''): string {
        $scoped = self::sessionName($planId, $slug);
        if (TmuxManager::exists($scoped)) return $scoped;
        $legacy = TmuxManager::legacyPlanSessionName($planId);
        if ($legacy !== $scoped && TmuxManager::exists($legacy)) return $legacy;
        return '';
    }

    /** Is this plan's orchestrator alive right now? */
    public static function running(int $planId, string $slug = ''): bool {
        return self::liveSession($planId, $slug) !== '';
    }

    /** Stop this plan's orchestrator, whichever name it is running under. */
    public static function stop(int $planId, string $slug = ''): bool {
        $live = self::liveSession($planId, $slug);
        return $live !== '' ? TmuxManager::kill($live) : false;
    }

    /**
     * Launch the orchestrator for a plan. Returns true if it is running when we return
     * (including "it was already running" — the caller asked for it to be building, and
     * it is). Returns false ONLY when the build genuinely did not start, and logs why.
     *
     * @param int         $planId      plan parent task id
     * @param string      $slug        instance slug
     * @param string      $instanceDir absolute instance directory
     * @param int         $level       member level to run the plan's endpoints at
     * @param string      $model       worker model for the orchestrator (claude-valid)
     * @param string|null $workbenchDb the instance's tasks db. Pass it EXPLICITLY when you
     *                                 know it (CLI callers that resolved it themselves);
     *                                 null falls back to the ambient TIKNIX_WORKBENCH_DB,
     *                                 which is right for the web paths where the sidecar
     *                                 base controller already putenv'd it.
     */
    public static function launch(
        int $planId,
        string $slug,
        string $instanceDir,
        int $level = 50,
        string $model = 'sonnet',
        ?string $workbenchDb = null
    ): bool {
        $planId = (int) $planId;
        if ($planId <= 0) { self::fail('orchestrator launch without a plan id', []); return false; }

        // Already building — under either name. Checked before the scoped name is
        // built so a pre-rename orchestrator is not joined by a second one.
        if (self::running($planId, $slug)) return true;
        $session = self::sessionName($planId, $slug);

        $dir = rtrim($instanceDir, '/');
        if (!is_dir($dir)) {
            self::fail('orchestrator launch: instance dir missing', ['dir' => $dir, 'plan' => $planId]);
            return false;
        }

        // The orchestrator CLI lives in CORE. dirname(__DIR__) is core root by
        // construction — this class only ever lives in core's lib — which is why the
        // sidecars no longer have to know where core is to start a build.
        $orchestrator = dirname(__DIR__) . '/scripts/plan-orchestrate.php';
        if (!is_file($orchestrator)) {
            self::fail('orchestrator script missing', ['looked_for' => $orchestrator, 'plan' => $planId]);
            return false;
        }

        $cmd = 'php ' . escapeshellarg($orchestrator)
             . ' --plan=' . $planId
             . ' --slug=' . escapeshellarg($slug)
             . ' --dir='  . escapeshellarg($dir)
             . ' --model=' . escapeshellarg($model)
             . ' --level=' . $level;

        $ab = $dir . '/.aibuilder';
        if (!is_dir($ab) && !@mkdir($ab, 0775, true) && !is_dir($ab)) {
            self::fail('orchestrator launch: cannot create .aibuilder', ['dir' => $ab, 'plan' => $planId]);
            return false;
        }

        // tmux does not inherit our environment, so the tasks db has to be written into
        // the script. Explicit argument wins over the ambient env: a CLI that resolved
        // the db itself (--db, or derived from the instance dir) knows better than an
        // env var it may have inherited from somewhere else entirely.
        $db = $workbenchDb !== null && $workbenchDb !== ''
            ? $workbenchDb
            : (string) (getenv('TIKNIX_WORKBENCH_DB') ?: '');
        $export = $db !== '' ? 'export TIKNIX_WORKBENCH_DB=' . escapeshellarg($db) . "\n" : '';

        $scriptFile = $ab . '/run-orchestrator.sh';
        $written = @file_put_contents(
            $scriptFile,
            "#!/bin/bash\n" . $export . $cmd . ' 2>&1 | tee ' . escapeshellarg($ab . '/orchestrator.log') . "\n"
        );
        if ($written === false) {
            self::fail('orchestrator launch: cannot write runner script', ['file' => $scriptFile, 'plan' => $planId]);
            return false;
        }
        @chmod($scriptFile, 0755);

        // TmuxManager::create THROWS on failure rather than returning false. Callers of
        // this method are told they get a boolean, and several of them turn that boolean
        // into a user-facing message — so the exception is caught here and reported as
        // the documented false, with the tmux error preserved in the log.
        try {
            if (!TmuxManager::create($session, $scriptFile, $dir)) {
                self::fail('orchestrator launch: tmux refused the session', ['session' => $session, 'plan' => $planId]);
                return false;
            }
        } catch (\Throwable $e) {
            self::fail('orchestrator launch: tmux failed', [
                'session' => $session, 'plan' => $planId, 'error' => $e->getMessage(),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Say it loudly wherever anyone is listening. This runs from web requests AND from
     * bootstrap-less CLIs, so it uses only Flight::get (a core Flight method, never a
     * mapped one) and always echoes to STDERR under CLI, where the caller's log is a
     * tee'd file somebody will actually read.
     */
    private static function fail(string $msg, array $ctx): void {
        $line = $msg . ($ctx ? ' ' . json_encode($ctx) : '');
        try {
            $log = \Flight::get('log');
            if ($log) { $log->error($msg, $ctx); }
            else { error_log('[PlanOrchestrator] ' . $line); }
        } catch (\Throwable $e) {
            error_log('[PlanOrchestrator] ' . $line);
        }
        if (php_sapi_name() === 'cli' && defined('STDERR')) {
            fwrite(STDERR, "[PlanOrchestrator] ERROR: " . $line . "\n");
        }
    }
}
