<?php
/**
 * AuditRunner — headless "Definition of Done" QA pass for a completed plan.
 *
 * When a decomposed plan finishes (all subtasks merged into the app in its container), this
 * runs the project's own agent IN ITS CONTAINER (clitool --agent-audit, the app's credential)
 * whose only job is to VERIFY the running site with Playwright: log in as each pre-created test
 * user level (ROOT/ADMIN/MEMBER), exercise the new interactions the plan introduced, screenshot
 * each, and write a structured manifest. The browser runs HERE — a per-audit Playwright MCP on
 * 127.0.0.1, allowed only the project's own public origin, reached from the container through an
 * SSH tunnel (tenantRunnerScript). The manifest is delivered to `<workspace>/.aibuilder/audit.json`,
 * which the control-plane driver (plan-audit.php) consumes: posts results onto each subtask,
 * reports failures to the firehose, and emails proof-of-life to the owner + shared teams.
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
    private int $planId = 0;

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

        $this->planId = $planId;
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

    public function stop(): bool {
        // A container's audit runs in its container; the session here only waits for it.
        if ($t = TenantBuilder::bySlug($this->slug)) TenantRun::kill($t, 'tiknix-run-' . $this->tenantAuditId());
        return TmuxManager::kill($this->sessionName);
    }

    /** The container run id of this member's audit on this project. */
    private function tenantAuditId(): string { return 'audit-m' . (int) $this->memberId; }

    /**
     * The audit of a project in its own container: its agent runs THERE (clitool --agent-audit,
     * the app's own credential), driving a browser that runs HERE — a Playwright MCP server on
     * 127.0.0.1:<port>, allowed to open only the project's own public origin, reached from the
     * container through an SSH tunnel (TenantHost::tunnelCommand). Both live for this audit only.
     * Screenshots land in the workspace (the server's --output-dir); unpackAudit() delivers the
     * manifest where plan-audit.php reads it and copies the screenshots into the app.
     */
    private function tenantRunnerScript(object $tenant): string {
        $ws = $this->instanceDir;
        $npx = trim((string) shell_exec('bash -lc ' . escapeshellarg('command -v npx') . ' 2>/dev/null'));
        if ($npx === '') throw new \RuntimeException("npx is not on this machine's PATH — the audit's browser (Playwright MCP) needs Node here");
        $port = 0;
        for ($i = 0; $i < 50 && $port === 0; $i++) {
            $p = random_int(28000, 28999);
            $sock = @fsockopen('127.0.0.1', $p, $e, $es, 0.2);
            if ($sock) { fclose($sock); continue; }
            $port = $p;
        }
        if ($port === 0) throw new \RuntimeException('no free local port for the audit browser');
        $u = parse_url($this->baseUrl);
        $origin = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '');
        $browser = escapeshellarg($npx) . ' -y @playwright/mcp@0.0.83 --headless --isolated --browser chromium --host 127.0.0.1 --port ' . $port
                 . ' --allowed-hosts ' . escapeshellarg("127.0.0.1:{$port}") . ' --allowed-origins ' . escapeshellarg($origin)
                 . ' --output-dir ' . escapeshellarg($ws);
        $out = $ws . '/.aibuilder/audit-result.json';
        $audit = TenantBuilder::tenantCommand($tenant, 'audit', $this->tenantAuditId(), $this->requestFile(), $out,
                                              ['browser-mcp' => "http://127.0.0.1:{$port}/mcp"]);
        $unpack = 'php -r ' . escapeshellarg('require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true)
                . '; exit(\app\TenantBuilder::unpackAudit(' . var_export($out, true) . ', ' . var_export($this->manifestFile(), true)
                . ', ' . var_export($this->slug, true) . ', ' . var_export($ws, true) . ', ' . (int) $this->planId . '));');
        $log = escapeshellarg($this->logFile());
        $blog = escapeshellarg($ws . '/.aibuilder/audit-browser.log');
        $path = escapeshellarg(dirname($npx));
        $tunnel = TenantHost::tunnelCommand($tenant, $port);
        return <<<BASH
#!/bin/bash
# Tiknix audit — {$this->slug}, in its container; the browser runs here (lib/AuditRunner.php)
export PATH={$path}:\$PATH
# Names this tree for the reaper's process sweep (scripts/reap-stale-tasks.php): the browser and
# the tunnel inherit it, so a tree that outlives its session can be identified and ended.
export TIKNIX_SESSION_NAME="{$this->sessionName}"
echo "[audit] instance {$this->slug} starting \$(date); browser on 127.0.0.1:{$port} for {$origin}" | tee {$log}
rm -f {$this->escaped($out)}
{$browser} > {$blog} 2>&1 &
BROWSER=\$!
TUNNEL=
trap 'kill \$BROWSER \$TUNNEL 2>/dev/null' EXIT
for i in \$(seq 1 90); do curl -s -o /dev/null -m 2 http://127.0.0.1:{$port}/mcp && break; sleep 1; done
curl -s -o /dev/null -m 2 http://127.0.0.1:{$port}/mcp || { echo "[audit] ERROR the browser did not start (see {$ws}/.aibuilder/audit-browser.log)" | tee -a {$log}; exit 1; }
{$tunnel} &
TUNNEL=\$!
sleep 3
kill -0 \$TUNNEL 2>/dev/null || { echo "[audit] ERROR the tunnel into the container did not open" | tee -a {$log}; exit 1; }
{ {$audit} > /dev/null; {$unpack}; } 2>&1 | tee -a {$log}
echo "[audit] exit=\${PIPESTATUS[0]} \$(date)" | tee -a {$log}
BASH;
    }

    private function escaped(string $s): string { return escapeshellarg($s); }

    /** The detached runner: headless `claude -p` pointed at the brief file. Model = sonnet
     *  (the QA work is procedural browser driving, not deep reasoning). */
    private function buildRunnerScript(): string {
        $t = TenantBuilder::bySlug($this->slug);
        if (!$t) throw new \RuntimeException("{$this->slug} is not running in its own container — audits run in a project's container");
        return $this->tenantRunnerScript($t);
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

## Target (its PUBLIC url — the browser may open nothing else)

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

Take every screenshot with `browser_take_screenshot`, passing
`filename: "public/uploads/audit/{$planId}/<level>-<short-label>.png"`
(e.g. `public/uploads/audit/{$planId}/admin-leads-page.png`). The browser saves it there itself —
do not create directories or files for screenshots. They become web-accessible at
`{$base}/uploads/audit/{$planId}/...` and are attached to the report. Use lowercase, hyphenated labels.

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
