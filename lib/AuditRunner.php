<?php
/**
 * AuditRunner — headless "Definition of Done" QA pass for a completed plan.
 *
 * When a decomposed plan finishes (all subtasks merged into the instance's live
 * branch), this launches a jailed, non-interactive `claude -p` agent whose only
 * job is to VERIFY the running site with Playwright: log in as each pre-created
 * test user level (ROOT/ADMIN/MEMBER), exercise the new interactions the plan
 * introduced, screenshot each, and write a structured manifest to
 * `<instance>/.aibuilder/audit.json`. The control-plane driver (plan-audit.php)
 * consumes that manifest: posts results onto each subtask, reports failures to
 * the firehose, and emails proof-of-life to the owner + shared teams.
 *
 * Mirrors PlanRunner exactly (jail-run.sh when the workspace is a capricorn
 * instance; direct otherwise). The agent READS the site over its PUBLIC url —
 * the jail blocks loopback, and Playwright is registered per-instance as an MCP
 * server, so browser_navigate / browser_click / browser_take_screenshot work
 * against https://<slug>.<app>.com only.
 *
 * The test users are created and torn down by the control-plane driver (via the
 * instance's own clitool), NOT here — so the agent just drives the browser with
 * credentials it is handed, which is far more reliable.
 */

namespace app;

class AuditRunner {

    private string $slug;
    private string $instanceDir;
    private string $baseUrl;
    private int $memberId;
    private int $memberLevel;
    private string $sessionName;

    public function __construct(string $slug, string $instanceDir, string $baseUrl, int $memberId, int $memberLevel = 50) {
        $this->slug        = $slug;
        $this->instanceDir = rtrim($instanceDir, '/');
        $this->baseUrl     = rtrim($baseUrl, '/');
        $this->memberId    = $memberId;
        $this->memberLevel = $memberLevel;
        // Distinct from planner (tiknix-<m>-plan-<slug>) and task sessions.
        $this->sessionName = "tiknix-{$memberId}-audit-{$slug}";
    }

    public function getSessionName(): string { return $this->sessionName; }
    private function abDir(): string { return $this->instanceDir . '/.aibuilder'; }
    public function manifestFile(): string { return $this->abDir() . '/audit.json'; }
    public function logFile(): string  { return $this->abDir() . '/audit.log'; }
    public function requestFile(): string { return $this->abDir() . '/audit-request.md'; }

    /** True while the audit tmux session is alive. */
    public function running(): bool { return TmuxManager::exists($this->sessionName); }

    /** True once the agent has produced a manifest for the driver to consume. */
    public function manifestReady(): bool { return is_file($this->manifestFile()); }

    /** Last N lines of the audit log. */
    public function logTail(int $lines = 60): string {
        $f = $this->logFile();
        if (!is_file($f)) return '';
        $all = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
        return implode("\n", array_slice($all, -$lines));
    }

    /**
     * Launch the headless auditor. Writes the QA brief (containing the base URL,
     * the handed-in test credentials, and the checklist of new interactions),
     * clears any stale manifest, and starts a detached tmux session running
     * `claude -p`. Returns the session name. Throws on setup failure.
     *
     * @param array $creds  ['root'=>['email'=>..,'password'=>..,'level'=>1], 'admin'=>..., 'member'=>...]
     * @param array $checklist  Human-readable lines describing what the plan added (routes, UI, subtasks).
     * @param int   $planId
     */
    public function start(array $creds, array $checklist, int $planId): string {
        if ($this->running()) {
            throw new \Exception('An audit is already running for this instance.');
        }
        $ab = $this->abDir();
        if (!is_dir($ab) && !@mkdir($ab, 0775, true)) {
            throw new \Exception('Could not create .aibuilder dir.');
        }
        @unlink($this->manifestFile());
        @unlink($this->logFile());

        file_put_contents($this->requestFile(), $this->buildAuditRequest($creds, $checklist, $planId));

        $scriptFile = $ab . '/run-audit.sh';
        file_put_contents($scriptFile, $this->buildRunnerScript());
        @chmod($scriptFile, 0755);

        TmuxManager::create($this->sessionName, $scriptFile, $this->instanceDir);
        usleep(400000);
        if (!$this->running()) {
            throw new \Exception('Audit session failed to start (see audit.log).');
        }
        return $this->sessionName;
    }

    public function stop(): bool { return TmuxManager::kill($this->sessionName); }

    /** jail-run.sh path when the workspace is a jailable capricorn instance, else ''. */
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

    /** The detached runner: headless `claude -p` pointed at the brief file. Model = sonnet
     *  (the QA work is procedural browser driving, not deep reasoning). */
    private function buildRunnerScript(): string {
        $ws  = $this->instanceDir;
        $log = $this->logFile();
        $shortPrompt = 'Read the file .aibuilder/audit-request.md and follow its instructions exactly. '
                     . 'You MUST finish by writing the manifest to .aibuilder/audit.json.';
        // Auditor model from the registry's auditor tier (§4 — ideally decorrelated
        // from the worker model). The auditor is instance-level (no per-task engine),
        // so resolve the default engine's auditor tier. Default sonnet: the QA work is
        // procedural browser driving, not deep reasoning.
        $model = EngineRegistry::model(EngineRegistry::defaultEngine(), 'auditor', 'sonnet');

        $jail = $this->jailFor();
        if ($jail !== '') {
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
# Tiknix headless auditor (claude -p) — instance {$this->slug}
export TIKNIX_MEMBER_ID={$this->memberId}
export TIKNIX_MEMBER_LEVEL={$this->memberLevel}
export TIKNIX_SESSION_NAME="{$this->sessionName}"
export TIKNIX_WORKSPACE="{$ws}"
export CLAUDE_CODE_MAX_OUTPUT_TOKENS=250000

echo "[audit] instance {$this->slug} starting \$(date)" | tee {$logArg}
{$runBlock} 2>&1 | tee -a {$logArg}
echo "[audit] exit=\${PIPESTATUS[0]} \$(date)" | tee -a {$logArg}
BASH;
    }

    /**
     * The QA brief. Hands the agent the base URL, per-level credentials (already
     * created for it), the checklist of what changed, the exact screenshot output
     * convention, and the strict manifest schema it must write.
     */
    private function buildAuditRequest(array $creds, array $checklist, int $planId): string {
        $base = $this->baseUrl;
        $checklistMd = $checklist
            ? implode("\n", array_map(fn($l) => '- ' . $l, $checklist))
            : '- (No explicit change list was supplied — explore the site and verify the primary pages render.)';

        // Credentials block (already provisioned by the driver; the agent only logs in with them).
        $credLines = [];
        foreach (['root' => 'ROOT (level 1)', 'admin' => 'ADMIN (level 50)', 'member' => 'MEMBER (level 100)'] as $k => $label) {
            if (empty($creds[$k])) continue;
            $c = $creds[$k];
            $credLines[] = "- **{$label}** — email `{$c['email']}` · password `{$c['password']}`";
        }
        $credsMd = implode("\n", $credLines);

        return <<<MD
# Definition-of-Done Audit — {$this->slug}

You are the **QA agent** for a tiknix instance. A build just finished and merged into
the live site. Your ONLY job is to **verify the running site with Playwright** as three
user levels, capture proof-of-life screenshots, and write a results manifest. You do NOT
edit code.

## Target (PUBLIC url only — the jail cannot reach localhost)

`{$base}`

Use the **Playwright MCP tools** (`browser_navigate`, `browser_click`, `browser_type`,
`browser_snapshot`, `browser_take_screenshot`). Navigate only to `{$base}/...` URLs.

## Test accounts (already created for you)

{$credsMd}

Log in at `{$base}/auth/login` (username = the email). If a **2FA setup/verify** page
blocks an admin/root login: if a "Skip for now" option exists, click it; otherwise record
that level's `login_ok=false` with note "blocked by 2FA policy" and move on — do NOT fail
the whole audit for that.

## What this build changed — verify each of these

{$checklistMd}

## Procedure (repeat for EACH level: root, admin, member)

1. `browser_navigate` to `{$base}/auth/login`; log in with that level's credentials.
2. Take a screenshot of the landing page after login.
3. Visit each changed page/interaction above that this level should be able to reach.
   Click the primary new controls. Take a screenshot of each meaningful state.
4. Note anything broken: a 403/404/500, a PHP error/stack trace on the page, a control
   that does nothing, or a permission that is wrong for the level (e.g. a MEMBER seeing an
   admin-only action). Capture a screenshot of the failure.
5. Log out (`{$base}/auth/logout`) before switching levels.

## Screenshot output convention (IMPORTANT)

Save every screenshot INTO the instance under:

`public/uploads/audit/{$planId}/<level>-<short-label>.png`

e.g. `public/uploads/audit/{$planId}/admin-leads-page.png`. These become web-accessible at
`{$base}/uploads/audit/{$planId}/...` and are attached to the report. Create the directory
if needed (`mkdir -p public/uploads/audit/{$planId}`). Use lowercase, hyphenated labels.

## Deliverable — write `.aibuilder/audit.json` (and nothing else)

When done, write EXACTLY this JSON shape to `.aibuilder/audit.json`:

```json
{
  "plan_id": {$planId},
  "instance": "{$this->slug}",
  "base_url": "{$base}",
  "passed": true,
  "summary": "one sentence: N levels tested, M interactions, P failures",
  "levels": {
    "root":   { "level": 1,   "login_ok": true,  "screens": ["public/uploads/audit/{$planId}/root-dashboard.png"] },
    "admin":  { "level": 50,  "login_ok": true,  "screens": [] },
    "member": { "level": 100, "login_ok": true,  "screens": [] }
  },
  "checks": [
    { "label": "Leads page renders for admin", "level": "admin", "status": "pass",
      "task_ref": "t2", "screens": ["public/uploads/audit/{$planId}/admin-leads-page.png"], "notes": "" }
  ],
  "failures": [
    { "label": "Member sees admin-only Delete", "level": "member", "task_ref": "t2",
      "url": "{$base}/leads", "message": "MEMBER should not see Delete control",
      "screens": ["public/uploads/audit/{$planId}/member-leads-bad.png"] }
  ]
}
```

Rules for the manifest:
- `passed` = `true` only if there are **zero** entries in `failures`.
- `screens` paths are RELATIVE to the instance root (start with `public/uploads/...`).
- `task_ref` — when a check/failure corresponds to one of the bracketed `[t#]` refs in the
  change list above, set `task_ref` to that ref so the result posts onto that subtask. Omit
  if it doesn't map to a specific subtask.
- Keep `summary` to one sentence. Every failure MUST include at least one screenshot.
- Write the file with your Write tool. After writing it, reply `AUDIT_WRITTEN` and stop.
MD;
    }
}
