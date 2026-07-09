<?php
/**
 * PlanExecutor — runs an approved plan as parallel, dependency-ordered build
 * agents in git worktrees of ONE instance.
 *
 * Model (verified against capricorn/bin/jail-run.sh):
 *   - Each subtask gets a git worktree at <instance>/.aibuilder/wt/task-<id> on a
 *     branch plan-<planId>/task-<id>, cut from the instance's current base branch.
 *   - A jailed `claude -p` agent runs with cwd = that worktree (via jail-run.sh's
 *     JAIL_CMD), so the shared .git is reachable and edits are isolated per task.
 *   - When an agent exits, the orchestrator commits whatever it changed, merges
 *     the branch back into the base branch, and unlocks dependents.
 *   - A task is "ready" only when every task in its depends_on is merged, so
 *     dependents build on top of their prerequisites' merged code. Independent
 *     tasks (empty depends_on) run in parallel, capped at MAX_CONCURRENT.
 *
 * runOnce() is one tick (reap → launch). A thin script loops it until the plan
 * reaches a terminal state; that loop runs detached so it survives the browser.
 */

namespace app;

use RedBeanPHP\R;

class PlanExecutor {

    public const MAX_CONCURRENT = 3;   // hard cap — protects the operator's Claude quota

    private int $planId;
    private string $slug;
    private string $instanceDir;
    private int $memberLevel;
    private string $engineModel;       // worker model, e.g. "sonnet"

    public function __construct(int $planId, string $slug, string $instanceDir, int $memberLevel = 50, string $engineModel = 'sonnet') {
        $this->planId      = $planId;
        $this->slug        = $slug;
        $this->instanceDir = rtrim($instanceDir, '/');
        $this->memberLevel = $memberLevel;
        $this->engineModel = $engineModel;
    }

    // ---- public API --------------------------------------------------------

    /** The plan (parent) bean. */
    public function plan() { return R::load('workbenchtask', $this->planId); }

    /** Subtasks of this plan, priority-ordered. */
    public function subtasks(): array {
        return R::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [$this->planId]);
    }

    /**
     * One orchestration tick: reap finished agents (commit + merge), then launch
     * as many ready tasks as the concurrency cap allows. Returns a status summary.
     */
    public function runOnce(): array {
        $tasks = $this->subtasks();
        $byId  = [];
        foreach ($tasks as $t) $byId[(int)$t->id] = $t;

        // 1) Reap: any running task whose agent session has ended.
        foreach ($tasks as $t) {
            if ($t->status === 'running' && !$this->sessionAlive((string)$t->agentSession)) {
                $this->reapTask($t);
            }
        }

        // 2) Launch: fill open slots with ready tasks (deps all merged).
        $running = $this->countByStatus($this->subtasks(), 'running');
        $slots   = self::MAX_CONCURRENT - $running;
        if ($slots > 0) {
            foreach ($this->subtasks() as $t) {
                if ($slots <= 0) break;
                if ($t->status !== 'pending') continue;
                if (!$this->depsMerged($t, $byId)) continue;
                if ($this->launchTask($t)) { $slots--; }
            }
        }

        // 3) Roll up plan state.
        $fresh = $this->subtasks();
        $counts = ['pending'=>0,'running'=>0,'merged'=>0,'failed'=>0,'conflict'=>0];
        foreach ($fresh as $t) { $counts[$t->status] = ($counts[$t->status] ?? 0) + 1; }
        $total    = count($fresh);
        $terminal = ($counts['merged'] + $counts['failed'] + $counts['conflict']);
        $done     = ($total > 0 && $terminal >= $total);
        // Deadlock guard: nothing running, nothing launchable, but not all merged.
        $stalled  = (!$done && $counts['running'] === 0 && $counts['pending'] > 0
                     && !$this->anyLaunchable($fresh, $byId));

        return ['done' => $done || $stalled, 'stalled' => $stalled, 'counts' => $counts, 'total' => $total];
    }

    // ---- task lifecycle ----------------------------------------------------

    /** Create the worktree + brief and spawn the jailed agent. */
    private function launchTask($t): bool {
        $base   = $this->baseBranch();
        $branch = 'plan-' . $this->planId . '/task-' . (int)$t->id;
        $wtRel  = '.aibuilder/wt/task-' . (int)$t->id;
        $wtAbs  = $this->instanceDir . '/' . $wtRel;

        // Fresh worktree cut from the CURRENT base (which now includes merged deps).
        $this->git(['worktree', 'remove', '--force', $wtRel]);   // best-effort clean
        $this->git(['branch', '-D', $branch]);
        $add = $this->git(['worktree', 'add', '-b', $branch, $wtRel, $base]);
        if (!$add['ok']) { $this->fail($t, 'worktree add failed: ' . $add['out']); return false; }

        // Brief + MCP config live under the worktree's .aibuilder (gitignored, not committed).
        @mkdir($wtAbs . '/.aibuilder', 0775, true);
        file_put_contents($wtAbs . '/.aibuilder/task.md', $this->buildTaskBrief($t));
        if (is_file($this->instanceDir . '/.mcp.json')) {
            @copy($this->instanceDir . '/.mcp.json', $wtAbs . '/.mcp.json');
        }

        $session = 'tiknix-plan' . $this->planId . '-task' . (int)$t->id;
        $script  = $this->buildRunnerScript($wtRel, $wtAbs, $session);
        $scriptFile = $wtAbs . '/.aibuilder/run-agent.sh';
        file_put_contents($scriptFile, $script);
        @chmod($scriptFile, 0755);

        if (!TmuxManager::create($session, $scriptFile, $this->instanceDir)) {
            $this->fail($t, 'could not start agent session');
            return false;
        }

        $t->status         = 'running';
        $t->worktreeBranch = $branch;       // fluid
        $t->agentSession   = $session;      // fluid
        $t->startedAt      = date('Y-m-d H:i:s');
        R::store($t);
        return true;
    }

    /** Agent finished: commit its changes, merge back, unlock dependents. */
    private function reapTask($t): void {
        $base   = $this->baseBranch();
        $branch = (string)$t->worktreeBranch;
        $wtRel  = '.aibuilder/wt/task-' . (int)$t->id;

        // Commit whatever the agent produced (don't rely on the agent committing).
        $this->gitAt($wtRel, ['add', '-A']);
        $staged = $this->gitAt($wtRel, ['diff', '--cached', '--quiet']);
        if ($staged['ok']) {   // exit 0 = no staged changes
            $this->finish($t, 'failed', 'agent produced no changes');
            $this->cleanupWorktree($wtRel, $branch, false);
            return;
        }
        $author = '-c user.email=aibuilder@tiknix.local -c user.name=aibuilder';
        $msg = 'plan-' . $this->planId . ' task-' . (int)$t->id . ': ' . substr((string)$t->title, 0, 72);
        $commit = $this->gitAtRaw($wtRel, $author . ' commit -q -m ' . escapeshellarg($msg));
        if (!$commit['ok']) { $this->finish($t, 'failed', 'commit failed: ' . $commit['out']); $this->cleanupWorktree($wtRel, $branch, false); return; }

        // Merge the branch into base (the main working tree is on base).
        $merge = $this->mergeBack($branch, $base);
        if ($merge['status'] === 'merged')        $this->finish($t, 'merged', '');
        elseif ($merge['status'] === 'conflict')  $this->finish($t, 'conflict', $merge['out']);
        else                                      $this->finish($t, 'failed', $merge['out']);

        $this->cleanupWorktree($wtRel, $branch, $merge['status'] === 'merged');
    }

    /** Merge a task branch into base; abort cleanly on conflict. */
    private function mergeBack(string $branch, string $base): array {
        // Refuse if the main tree is dirty (a terminal edit mid-run) — surface it.
        $dirty = $this->git(['status', '--porcelain']);
        if ($dirty['ok'] && trim($dirty['out']) !== '') {
            return ['status' => 'failed', 'out' => 'instance working tree is dirty; commit/checkpoint it, then re-run'];
        }
        $m = $this->gitRaw('merge --no-ff -m ' . escapeshellarg('merge ' . $branch) . ' ' . escapeshellarg($branch));
        if ($m['ok']) return ['status' => 'merged', 'out' => ''];
        // Conflict or other failure — abort to keep base pristine.
        $this->git(['merge', '--abort']);
        $isConflict = stripos($m['out'], 'conflict') !== false;
        return ['status' => $isConflict ? 'conflict' : 'failed', 'out' => trim($m['out'])];
    }

    private function cleanupWorktree(string $wtRel, string $branch, bool $dropBranch): void {
        $this->git(['worktree', 'remove', '--force', $wtRel]);
        if ($dropBranch && $branch !== '') $this->git(['branch', '-D', $branch]);
    }

    // ---- readiness / status helpers ---------------------------------------

    private function depsMerged($t, array $byId): bool {
        foreach ($this->deps($t) as $depId) {
            $dep = $byId[$depId] ?? null;
            if (!$dep || $dep->status !== 'merged') return false;
        }
        return true;
    }

    private function anyLaunchable(array $tasks, array $byId): bool {
        foreach ($tasks as $t) {
            if ($t->status === 'pending' && $this->depsMerged($t, $byId)) return true;
        }
        return false;
    }

    private function deps($t): array {
        $d = json_decode((string)$t->dependsOn, true);
        return is_array($d) ? array_map('intval', $d) : [];
    }

    private function countByStatus(array $tasks, string $status): int {
        $n = 0; foreach ($tasks as $t) if ($t->status === $status) $n++; return $n;
    }

    private function finish($t, string $status, string $note): void {
        $t->status       = $status;
        $t->completedAt  = date('Y-m-d H:i:s');
        if ($note !== '') $t->errorMessage = mb_substr($note, 0, 1000);
        R::store($t);
    }

    private function fail($t, string $note): void { $this->finish($t, 'failed', $note); }

    // ---- agent invocation --------------------------------------------------

    /** The jailed agent runner script (detached in tmux). */
    protected function buildRunnerScript(string $wtRel, string $wtAbs, string $session): string {
        $mainProjectRoot = dirname(__DIR__);
        $log = $wtAbs . '/.aibuilder/agent.log';
        $jail = $this->jailFor();
        // bypassPermissions must be explicit here: JAIL_CMD replaces jail-run.sh's
        // own `claude --permission-mode bypassPermissions` wrapper.
        $inner = 'cd ' . $wtRel . ' && claude --permission-mode bypassPermissions -p '
               . escapeshellarg('Read .aibuilder/task.md and implement it fully in this working directory, following the codebase conventions. Do not touch files outside your task. When finished, stop.')
               . ' --model ' . escapeshellarg($this->engineModel);

        if ($jail !== '') {
            $run = 'JAIL_CMD=' . escapeshellarg($inner) . ' ' . escapeshellarg($jail) . ' ' . escapeshellarg($this->instanceDir);
        } else {
            // Non-jailed fallback (isolated clone): run inner directly in the instance.
            $run = 'cd ' . escapeshellarg($this->instanceDir) . ' && ' . $inner;
        }
        $logArg = escapeshellarg($log);
        return <<<BASH
#!/bin/bash
export TIKNIX_MEMBER_LEVEL={$this->memberLevel}
export TIKNIX_PROJECT_ROOT="{$mainProjectRoot}"
export TIKNIX_WORKSPACE="{$this->instanceDir}"
export CLAUDE_CODE_MAX_OUTPUT_TOKENS=250000
echo "[agent] {$session} start \$(date)" | tee {$logArg}
{$run} 2>&1 | tee -a {$logArg}
echo "[agent] {$session} exit=\${PIPESTATUS[0]} \$(date)" | tee -a {$logArg}
BASH;
    }

    private function buildTaskBrief($t): string {
        $files = json_decode((string)$t->relatedFiles, true);
        $files = is_array($files) ? implode("\n", array_map(fn($f) => "- $f", $files)) : '';
        $title = (string)$t->title;
        $desc  = (string)$t->description;
        return <<<MD
# Build task: {$title}

You are one of several agents building a larger plan. Implement ONLY this task, in
this working directory (a git worktree of the instance). Another process will
commit and merge your work — you just make the code changes.

## What to build

{$desc}

## Likely files

{$files}

## Rules
- Follow the existing codebase conventions (FlightPHP controllers, RedBeanPHP via
  the Bean wrapper, the project's CLAUDE.md standards). Use the tiknix MCP
  (codebase_map / whatprovides / describe) to check conventions before writing.
- Stay within the scope of THIS task. Do not edit files owned by other tasks.
- Do not run git, do not push, do not start servers. Just implement, then stop.
MD;
    }

    // ---- git plumbing ------------------------------------------------------

    private function baseBranch(): string {
        $r = $this->git(['rev-parse', '--abbrev-ref', 'HEAD']);
        $b = trim($r['out']);
        return $b !== '' && $b !== 'HEAD' ? $b : 'master';
    }

    /** git -C <instance> <args…> (array form, auto-escaped). */
    private function git(array $args): array {
        return $this->gitRaw(implode(' ', array_map('escapeshellarg', $args)));
    }
    private function gitRaw(string $argStr): array {
        $cmd = 'git -C ' . escapeshellarg($this->instanceDir) . ' ' . $argStr . ' 2>&1';
        exec($cmd, $out, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $out)];
    }
    /** git -C <instance>/<wtRel> — operate inside a worktree. */
    private function gitAt(string $wtRel, array $args): array {
        return $this->gitAtRaw($wtRel, implode(' ', array_map('escapeshellarg', $args)));
    }
    private function gitAtRaw(string $wtRel, string $argStr): array {
        $cmd = 'git -C ' . escapeshellarg($this->instanceDir . '/' . $wtRel) . ' ' . $argStr . ' 2>&1';
        exec($cmd, $out, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $out)];
    }

    private function sessionAlive(string $session): bool {
        return $session !== '' && TmuxManager::exists($session);
    }

    /** jail-run.sh path when the instance is jailable, else '' (mirrors ClaudeRunner). */
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
}
