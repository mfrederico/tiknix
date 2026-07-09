<?php
/**
 * PlanRunner — headless "decompose a goal into a multi-agent plan" pass.
 *
 * Unlike ClaudeRunner (interactive TUI in the browser terminal), this runs
 * `claude -p` (print / non-interactive) in a detached tmux session against a
 * single instance. The planner is instructed to ground itself with the tiknix
 * MCP (codebase_map / whatprovides / describe) and then call the submit_plan
 * MCP tool, which writes <instance>/.aibuilder/plan.json. The app's
 * planingest() endpoint turns that file into a reviewable workbench task tree.
 *
 * The planner only READS the codebase and WRITES the plan file — it does not
 * build anything. Execution of the plan is a separate step (the worktree
 * orchestrator, Phase 2).
 *
 * Jailing mirrors ClaudeRunner exactly: when the workspace is a capricorn
 * instance we run inside jail-run.sh; otherwise (an isolated clone) we run
 * direct, relying on the PreToolUse security-sandbox hook for confinement.
 */

namespace app;

class PlanRunner {

    private string $slug;
    private string $instanceDir;
    private int $memberId;
    private int $memberLevel;
    private string $engine;
    private string $sessionName;

    public function __construct(string $slug, string $instanceDir, int $memberId, int $memberLevel = 50, string $engine = 'claude') {
        $this->slug        = $slug;
        $this->instanceDir = rtrim($instanceDir, '/');
        $this->memberId    = $memberId;
        $this->memberLevel = $memberLevel;
        $this->engine      = $engine;
        // Distinct from task sessions (tiknix-<m>-task-<id>) so it never collides.
        $this->sessionName = "tiknix-{$memberId}-plan-{$slug}";
    }

    public function getSessionName(): string { return $this->sessionName; }
    private function abDir(): string { return $this->instanceDir . '/.aibuilder'; }
    public function planFile(): string { return $this->abDir() . '/plan.json'; }
    public function logFile(): string  { return $this->abDir() . '/planner.log'; }
    public function requestFile(): string { return $this->abDir() . '/plan-request.md'; }

    /** True while the planner tmux session is alive. */
    public function running(): bool { return TmuxManager::exists($this->sessionName); }

    /** True once the planner has produced a plan file for ingest. */
    public function planReady(): bool { return is_file($this->planFile()); }

    /** Last N lines of the planner log for the UI. */
    public function logTail(int $lines = 40): string {
        $f = $this->logFile();
        if (!is_file($f)) return '';
        $all = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
        return implode("\n", array_slice($all, -$lines));
    }

    /**
     * Launch the headless planner. Writes the request brief, clears any stale
     * plan, and starts a detached tmux session running `claude -p`. Returns the
     * session name. Throws on setup failure.
     */
    public function start(string $goal): string {
        if ($this->running()) {
            throw new \Exception('A planner is already running for this instance.');
        }
        $ab = $this->abDir();
        if (!is_dir($ab) && !@mkdir($ab, 0775, true)) {
            throw new \Exception('Could not create .aibuilder dir.');
        }
        // Fresh slate: drop a prior plan/log so status polling is unambiguous.
        @unlink($this->planFile());
        @unlink($this->logFile());

        file_put_contents($this->requestFile(), $this->buildPlanRequest($goal));

        $script = $this->buildRunnerScript();
        $scriptFile = $ab . '/run-planner.sh';
        file_put_contents($scriptFile, $script);
        @chmod($scriptFile, 0755);

        TmuxManager::create($this->sessionName, $scriptFile, $this->instanceDir);
        usleep(400000);
        if (!$this->running()) {
            throw new \Exception('Planner session failed to start (see planner.log).');
        }
        return $this->sessionName;
    }

    /** Kill the planner session (cancel). */
    public function stop(): bool { return TmuxManager::kill($this->sessionName); }

    /**
     * jail-run.sh path when the workspace is a jailable capricorn instance,
     * else '' (run direct). Mirrors ClaudeRunner::jailFor.
     */
    private function jailFor(): string {
        $root = '/var/www/html/default';
        $real = realpath($this->instanceDir) ?: $this->instanceDir;
        if (strpos(basename($real), '.') === false) return '';
        if (strpos($real, $root . '/') !== 0) return '';
        if (!is_file("$real/public/index.php")) return '';
        $cfg = @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
        $binDir = rtrim($cfg['ops']['bin_dir'] ?? '/home/ubuntu/capricorn/bin', '/');
        $script = "$binDir/jail-run.sh";
        return is_file($script) ? $script : '';
    }

    /**
     * The detached runner script. Headless `claude -p` with a tiny, quote-safe
     * positional prompt that points at the full brief file (so no long/complex
     * text has to survive escaping through jail-run.sh). Planner model = opus.
     */
    private function buildRunnerScript(): string {
        $mainProjectRoot = dirname(__DIR__);
        $ws  = $this->instanceDir;
        $log = $this->logFile();
        // Kept minimal + quote-safe: the real instructions live in plan-request.md,
        // which the planner reads with its own Read tool inside the workspace.
        $shortPrompt = 'Read the file .aibuilder/plan-request.md and follow its instructions exactly. You MUST finish by calling the submit_plan tool.';
        $model = 'opus';

        $jail = $this->jailFor();
        if ($jail !== '') {
            // jail-run.sh <workspace> -- <claude args>. The jail itself runs
            //   claude --permission-mode bypassPermissions <our args>
            // (see capricorn/bin/jail-run.sh:152), so we only add -p + model —
            // permissions are already bypassed and creds are the instance's own.
            $runBlock = escapeshellarg($jail) . ' ' . escapeshellarg($ws)
                      . ' -- -p ' . escapeshellarg($shortPrompt) . ' --model ' . escapeshellarg($model);
        } else {
            $claude = 'claude -p ' . escapeshellarg($shortPrompt)
                    . ' --model ' . escapeshellarg($model) . ' --dangerously-skip-permissions';
            $runBlock = 'cd ' . escapeshellarg($ws) . " && " . $claude;
        }

        $logArg = escapeshellarg($log);
        return <<<BASH
#!/bin/bash
# Tiknix headless planner (claude -p) — instance {$this->slug}
export TIKNIX_MEMBER_ID={$this->memberId}
export TIKNIX_MEMBER_LEVEL={$this->memberLevel}
export TIKNIX_SESSION_NAME="{$this->sessionName}"
export TIKNIX_PROJECT_ROOT="{$mainProjectRoot}"
export TIKNIX_WORKSPACE="{$ws}"
export CLAUDE_CODE_MAX_OUTPUT_TOKENS=250000

echo "[planner] instance {$this->slug} starting \$(date)" | tee {$logArg}
{$runBlock} 2>&1 | tee -a {$logArg}
echo "[planner] exit=\${PIPESTATUS[0]} \$(date)" | tee -a {$logArg}
# Exit cleanly (no blocking read): the session ending is the "done" signal that
# planstatus polls; plan.json + planner.log persist for ingest and review.
BASH;
    }

    /**
     * The decomposition brief. Strict, JSON-tool-terminated (myctobot pattern),
     * tiknix-flavored: ground first, then submit a dependency graph where
     * independent tasks can run in parallel (they will, in isolated git
     * worktrees), and file-overlapping tasks are chained via depends_on.
     */
    private function buildPlanRequest(string $goal): string {
        $goal = trim($goal);
        return <<<MD
# AI Builder — Plan Decomposition

You are the **planning agent** for a tiknix instance. Your ONLY job is to turn the
goal below into a concrete, buildable multi-agent plan. You do NOT write code or
edit files — you produce a plan that other agents will build.

## Goal

{$goal}

## How to work

1. **Ground yourself first.** Use the `tiknix` MCP tools before planning:
   - `codebase_map` — get the lay of the land (controllers, models, libs, config).
   - `whatprovides("<concept>")` — find where a concept already lives.
   - `describe("<name>")` — routes/columns/methods for a specific primitive.
   Reuse existing conventions (FlightPHP controllers, RedBeanPHP beans, the Bean
   wrapper). Do NOT reinvent what already exists.

2. **Decompose into the smallest sensible tasks.** Each task is one focused unit
   of work a single agent can complete and commit on its own.

3. **Express dependencies as a graph.** Every task gets a stable `id` (e.g. "t1").
   List prerequisite ids in `depends_on`.
   - Tasks with **no** shared files and no ordering constraint should have an
     EMPTY `depends_on` — they will be built **in parallel, in isolated git
     worktrees**, then merged.
   - Tasks that touch the **same files**, or need another task's output, MUST be
     chained via `depends_on` so they run sequentially and don't collide on merge.

4. **Pick an engine per task.** `claude` for anything requiring judgement;
   `qwen` only for simple mechanical edits.

## Deliverable

When (and only when) you have grounded yourself and decided the breakdown, call
the **`submit_plan`** MCP tool exactly once with:

- `title` — short name for the whole plan
- `summary` — 1-3 sentences on the approach
- `subtasks` — the array of tasks, each with: `id`, `title`, `description`,
  `priority` (1 highest .. 4 lowest), `engine`, `files` (likely paths), and
  `depends_on` (array of prerequisite ids).

Do not ask the operator questions — make reasonable assumptions and note them in
the relevant task descriptions. After `submit_plan` returns, reply `PLAN_WRITTEN`
and stop.
MD;
    }
}
