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

    /**
     * Post-build: apply the DB seed scripts the plan introduced (database/seeds/*.php)
     * against the LIVE instance, then rebuild the permission cache. Plan branches never
     * carry the binary DB (reapTask discards it), so DB / permission changes are
     * expressed as committed, idempotent seed scripts and applied here — once, ledgered
     * so a resumed orchestrator never double-applies. Returns human-readable log lines.
     */
    public function finalize(): array {
        $log = [];
        $seedDir    = $this->instanceDir . '/database/seeds';
        $ledgerFile = $this->instanceDir . '/.aibuilder/plan-' . $this->planId . '-seeds.txt';
        $applied    = is_file($ledgerFile)
            ? array_values(array_filter(array_map('trim', explode("\n", (string)file_get_contents($ledgerFile)))))
            : [];
        $appliedSet = array_flip($applied);

        if (is_dir($seedDir)) {
            $seeds = glob($seedDir . '/*.php') ?: [];
            sort($seeds);
            foreach ($seeds as $seed) {
                $name = basename($seed);
                if (isset($appliedSet[$name])) { $log[] = "seed {$name}: already applied"; continue; }
                $out = []; $code = 0;
                exec('cd ' . escapeshellarg($this->instanceDir) . ' && php ' . escapeshellarg($seed) . ' 2>&1', $out, $code);
                $tail = trim(implode(' ', array_slice($out, -2)));
                $log[] = "seed {$name}: " . ($code === 0 ? 'ok' : 'FAILED') . ($tail !== '' ? ' — ' . $tail : '');
                if ($code === 0) { $applied[] = $name; }
            }
            @file_put_contents($ledgerFile, implode("\n", array_values(array_unique($applied))) . "\n");
        } else {
            $log[] = 'no database/seeds/ — nothing to apply';
        }

        // Rebuild the permission cache so any new authcontrol rows take effect at once
        // (a direct DB insert doesn't bump the APCu cache version on its own).
        $rc = $this->instanceDir . '/scripts/resetcache.php';
        if (is_file($rc)) {
            $out = []; $code = 0;
            exec('cd ' . escapeshellarg($this->instanceDir) . ' && php ' . escapeshellarg($rc) . ' 2>&1', $out, $code);
            $log[] = 'resetcache: ' . ($code === 0 ? 'ok' : 'FAILED');
        }
        return $log;
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
        $this->logEvent($t, 'info', 'Build agent started on ' . $branch . ' (engine ' . ($t->engine ?: 'claude') . ')');
        return true;
    }

    /** Agent finished: commit its changes, merge back, unlock dependents. */
    private function reapTask($t): void {
        $base   = $this->baseBranch();
        $branch = (string)$t->worktreeBranch;
        $wtRel  = '.aibuilder/wt/task-' . (int)$t->id;

        // The force-tracked SQLite DB is runtime state, not a build artifact. Discard
        // any writes the agent's app made to it in the worktree so plan branches never
        // carry (and merge-clobber) the binary DB. Intentional DB / permission changes
        // travel as database/seeds/*.php (committed code), applied to the LIVE instance
        // in finalize(). Best-effort: only if a tracked DB was actually modified.
        $modDb = $this->gitAt($wtRel, ['ls-files', '-m', '--', '*.db', '*.sqlite']);
        $dbFiles = array_values(array_filter(array_map('trim', explode("\n", (string)$modDb['out']))));
        if ($dbFiles !== []) {   // check out the exact files (a wildcard that matches none aborts the whole checkout)
            $this->gitAt($wtRel, array_merge(['checkout', '--'], $dbFiles));
        }

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
        // Refuse only on UNEXPECTED local edits (a terminal edit mid-run). The live
        // SQLite DB is force-tracked (for checkpoint/rollback) yet written on every
        // request, so it is perpetually "modified" — that expected churn is not a
        // reason to refuse. Plan branches never diff the DB (reapTask discards it),
        // so the locally-modified DB can't collide with the merge.
        $dirt = $this->significantDirt();
        if ($dirt !== []) {
            return ['status' => 'failed', 'out' => 'instance working tree has uncommitted edits ('
                . implode(', ', array_slice($dirt, 0, 5)) . '); commit or checkpoint them, then re-run'];
        }
        $m = $this->gitRaw('merge --no-ff -m ' . escapeshellarg('merge ' . $branch) . ' ' . escapeshellarg($branch));
        if ($m['ok']) return ['status' => 'merged', 'out' => ''];
        // Conflict or other failure — abort to keep base pristine.
        $this->git(['merge', '--abort']);
        $isConflict = stripos($m['out'], 'conflict') !== false;
        return ['status' => $isConflict ? 'conflict' : 'failed', 'out' => trim($m['out'])];
    }

    /**
     * Working-tree paths with local edits that AREN'T expected runtime churn.
     * The force-tracked SQLite DB(s) are rewritten on every web request, so
     * `git status` always shows them modified; ignore *.db / *.sqlite so a live
     * request can't spuriously trip the dirty-tree guard mid-merge.
     */
    private function significantDirt(): array {
        $r = $this->git(['status', '--porcelain']);
        if (!$r['ok']) return [];
        $out = [];
        foreach (explode("\n", trim($r['out'])) as $line) {
            if ($line === '') continue;
            $path = trim(substr($line, 3));                  // strip "XY " status prefix
            if (strpos($path, ' -> ') !== false) {           // rename: keep the new path
                $path = substr($path, strpos($path, ' -> ') + 4);
            }
            if (preg_match('/\.(db|sqlite)$/', $path)) continue;   // expected live-DB churn
            $out[] = $path;
        }
        return $out;
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
        $d = json_decode(((string)$t->dependsOn) ?? '', true);
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

        $labels = [
            'merged'   => ['info',    'Merged into the instance base branch'],
            'conflict' => ['warning', 'Merge conflict — left on its branch for manual review'],
            'failed'   => ['error',   'Build failed'],
        ];
        [$lvl, $msg] = $labels[$status] ?? ['info', 'Finished: ' . $status];
        if ($note !== '') $msg .= ' — ' . mb_substr($note, 0, 300);
        $this->logEvent($t, $lvl, $msg);
    }

    private function fail($t, string $note): void { $this->finish($t, 'failed', $note); }

    /**
     * Append a tasklog row for a subtask so the Workbench "Recent Logs" panel
     * reflects the orchestrator path too — it previously only logged the manual
     * "Run with Claude" flow, leaving every plan-built task blank. Never throws:
     * a logging failure must not break the build loop.
     */
    private function logEvent($t, string $level, string $message): void {
        try {
            $log = R::dispense('tasklog');
            $log->taskId    = (int)$t->id;
            $log->memberId  = ((int)$t->memberId) ?: null;
            $log->logLevel  = $level;              // info | warning | error
            $log->logType   = 'orchestrator';
            $log->message   = $message;
            $log->createdAt = date('Y-m-d H:i:s');
            R::store($log);
        } catch (\Throwable $e) { /* swallow — logging is best-effort */ }
    }

    // ---- agent invocation --------------------------------------------------

    /** The jailed agent runner script (detached in tmux). */
    protected function buildRunnerScript(string $wtRel, string $wtAbs, string $session): string {
        $mainProjectRoot = dirname(__DIR__);
        $log = $wtAbs . '/.aibuilder/agent.log';
        $jail = $this->jailFor();
        // bypassPermissions must be explicit here: JAIL_CMD replaces jail-run.sh's
        // own `claude --permission-mode bypassPermissions` wrapper.
        // stream-json (+ required --verbose) so each tool use / message is emitted
        // as its own JSON line to agent.log as work happens — otherwise plain `-p`
        // buffers and the log stays empty until the agent exits, leaving the UI
        // unable to show what the agent is CURRENTLY doing. reapTask never parses
        // this log (it merges on git diff), so the format change is display-only.
        $inner = 'cd ' . $wtRel . ' && claude --permission-mode bypassPermissions -p '
               . escapeshellarg('Read .aibuilder/task.md and implement it fully in this working directory, following the codebase conventions. Do not touch files outside your task. When finished, stop.')
               . ' --model ' . escapeshellarg($this->engineModel)
               . ' --output-format stream-json --verbose';

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
        $files = json_decode(((string)$t->relatedFiles) ?? '', true);
        $files = is_array($files) ? implode("\n", array_map(fn($f) => "- $f", $files)) : '';
        $title = (string)$t->title;
        $desc  = (string)$t->description;
        $reuse = $this->reuseBrief($t);
        return <<<MD
# Build task: {$title}

You are one of several agents building a larger plan. Implement ONLY this task, in
this working directory (a git worktree of the instance). Another process will
commit and merge your work — you just make the code changes.

## What to build

{$desc}

## Likely files

{$files}
{$reuse}
## Rules
- Follow the existing codebase conventions (FlightPHP controllers, RedBeanPHP via
  the Bean wrapper, the project's CLAUDE.md standards). Use the tiknix MCP
  (reuse_digest / codebase_map / whatprovides / describe) to check conventions
  before writing. If a "Reuse these" section is present above, build ON those
  primitives — extend them, do not create parallel duplicates.
- Stay within the scope of THIS task. Do not edit files owned by other tasks.
- Write any summary, notes, or final message in **Markdown** (`##` sub-headers,
  `-` lists, `` `code` `` for files/beans/routes) — it renders in the task view,
  so keep it scannable header-first.
- Do not run git, do not push, do not start servers. Just implement, then stop.
- Database / permission changes: do NOT write to the database directly, do NOT run
  migration or seed scripts, and do NOT edit conf/config*.ini. The live SQLite DB is
  discarded from your worktree, so direct writes will NOT persist. If this task needs
  a DB or permission change (e.g. an authcontrol route entry to make a page public),
  write an IDEMPOTENT PHP seed script to database/seeds/<descriptive-name>.php using
  the \\app\\Bean wrapper (Bean::findOne / dispense / store). The orchestrator runs
  every database/seeds/*.php against the live instance after your work merges (with the
  instance root as CWD), then rebuilds the permission cache — you do NOT run it.
  The seed file lives TWO levels below the instance root, so bootstrap the app with
  EXACTLY this (do not add a chdir, the CWD is already the instance root):
      require_once __DIR__ . '/../../bootstrap.php';
      \$app = new \\app\\Bootstrap();
  A wrong relative depth (e.g. '/../bootstrap.php') will fatal — the seed is two dirs deep.
MD;
    }

    /**
     * Expand a task's declared `reuses` (["controller/Lead","model/member",...])
     * into a focused detail block for the build brief, using the same
     * Introspector that backs the tiknix MCP tools — pointed at the instance base
     * (not the worktree) so it reflects the live primitives + schema. Deterministic:
     * the builder agent gets the exact routes/columns/methods of what it extends,
     * rather than being told to go look. Never throws — empty on any failure.
     */
    private function reuseBrief($t): string {
        $reuses = json_decode((string)$t->reuses, true);
        if (!is_array($reuses) || !$reuses) return '';
        try {
            $file = dirname(__DIR__) . '/mcptools/Introspector.php';
            if (is_file($file)) require_once $file;
            $cls = 'app\\mcptools\\Introspector';
            if (!class_exists($cls)) return '';
            $intro = new $cls($this->instanceDir);
        } catch (\Throwable $e) { return ''; }

        $lines = [];
        foreach ($reuses as $r) {
            $r = trim((string)$r);
            if ($r === '') continue;
            // "kind/name" — honor the kind prefix so model/member doesn't also pull the Member controller.
            $kind = ''; $name = $r;
            if (strpos($r, '/') !== false) { [$kind, $name] = explode('/', $r, 2); $kind = strtolower(trim($kind)); }
            try { $hits = $intro->describe($name); } catch (\Throwable $e) { $hits = []; }
            $kindMap = ['controller' => 'controller', 'controllers' => 'controller', 'model' => 'model', 'models' => 'model', 'lib' => 'lib', 'libs' => 'lib', 'service' => 'lib'];
            $want = $kindMap[$kind] ?? '';
            if ($want !== '') $hits = array_values(array_filter($hits, fn($h) => ($h['kind'] ?? '') === $want));
            if (!$hits) { $lines[] = "- **{$r}** — not found in the current codebase; verify it exists before assuming."; continue; }
            foreach ($hits as $h) {
                if (($h['kind'] ?? '') === 'controller') {
                    $routes = array_map(fn($x) => $x['method'] . ($x['level'] !== null ? "[{$x['level']}]" : ''), $h['routes']);
                    $lines[] = "- **controller/{$h['name']}** ({$h['path']}) — routes: " . implode(', ', array_slice($routes, 0, 16));
                } elseif (($h['kind'] ?? '') === 'model') {
                    $cols = array_column($h['columns'], 'name');
                    $rel  = array_map(fn($x) => '→' . $x['belongsTo'], $h['relations']);
                    $lines[] = "- **model/{$h['name']}** (table {$h['table']}) — cols: " . implode(', ', $cols) . ($rel ? '  rel: ' . implode(' ', $rel) : '');
                } elseif (($h['kind'] ?? '') === 'lib') {
                    $lines[] = "- **lib/{$h['name']}** ({$h['path']}) — methods: " . implode(', ', array_slice($h['methods'], 0, 16));
                }
            }
        }
        if (!$lines) return '';
        return "\n## Reuse these — build on them, do not reinvent\n" . implode("\n", $lines) . "\n";
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
