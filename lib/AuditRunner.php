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
    /** One checker of several working at once (Plan and Fan, step five): its level, '' for the only one. */
    private string $part;

    public function __construct(string $slug, string $instanceDir, string $baseUrl, int $memberId, int $memberLevel = 50, string $part = '') {
        if ($part !== '' && !preg_match('/^[a-z]{2,12}$/', $part)) throw new \InvalidArgumentException("'{$part}' is not a check part (a level: root, admin, member)");
        $this->part        = $part;
        $this->slug        = $slug;
        $this->instanceDir = rtrim($instanceDir, '/');
        $this->baseUrl     = rtrim($baseUrl, '/');
        $this->memberId    = $memberId;
        $this->memberLevel = $memberLevel;
        // Distinct from planner (tiknix-<m>-plan-<slug>) and task sessions.
        $this->sessionName = "tiknix-{$memberId}-audit-{$slug}" . ($part !== '' ? "-{$part}" : '');
    }

    public function getSessionName(): string { return $this->sessionName; }
    private function abDir(): string { return $this->instanceDir . '/.aibuilder'; }
    private function sfx(): string { return $this->part !== '' ? '-' . $this->part : ''; }
    public function manifestFile(): string { return $this->abDir() . '/audit' . $this->sfx() . '.json'; }
    public function logFile(): string  { return $this->abDir() . '/audit' . $this->sfx() . '.log'; }
    public function requestFile(): string { return $this->abDir() . '/audit-request' . $this->sfx() . '.md'; }

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
    public function start(array $creds, array $checklist, int $planId, string $size = 'large'): string {
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
        file_put_contents($this->requestFile(), $this->buildAuditRequest($creds, $checklist, $planId, $size));

        $scriptFile = $ab . '/run-audit' . $this->sfx() . '.sh';
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
    private function tenantAuditId(): string { return 'audit-m' . (int) $this->memberId . $this->sfx(); }

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
        $port = 0;
        for ($i = 0; $i < 50 && $port === 0; $i++) {
            $p = random_int(28000, 28999);
            $sock = @fsockopen('127.0.0.1', $p, $e, $es, 0.2);
            if ($sock) { fclose($sock); continue; }
            $port = $p;
        }
        if ($port === 0) throw new \RuntimeException('no free local port for the audit browser');
        // Screenshots: the browser saves UNNAMED ones here (a named file it resolves against the
        // agent's working directory, which is in the container — not this machine).
        $shotsDir = $ws . '/public/uploads/audit/' . (int) $this->planId;
        if (!is_dir($shotsDir) && !@mkdir($shotsDir, 0775, true)) throw new \RuntimeException("could not create {$shotsDir}");
        $u = parse_url($this->baseUrl);
        $origin = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '');
        // The browser runs on the QA host (lib/TenantBrowser.php), like every browser the platform
        // runs for an app — not on this machine. What it saves is fetched here when the audit ends.
        $qaDir = '/srv/qa/audits/' . $this->slug . '-' . (int) $this->planId . $this->sfx();
        $browser = TenantBrowser::qaBrowserCommand($port, array_merge([$origin], TenantBrowser::PAGE_ASSETS), $qaDir);
        $collect = 'php -r ' . escapeshellarg('require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; new \app\Bootstrap(); '
                 . 'try { \app\TenantBrowser::collect(' . var_export($qaDir, true) . ', ' . var_export($shotsDir, true) . '); } catch (\Throwable $e) { fwrite(STDERR, "[audit] ERROR " . $e->getMessage() . "\n"); exit(1); }');
        $out = $ws . '/.aibuilder/audit-result' . $this->sfx() . '.json';
        $audit = TenantBuilder::tenantCommand($tenant, 'audit', $this->tenantAuditId(), $this->requestFile(), $out,
                                              ['browser-mcp' => "http://127.0.0.1:{$port}/mcp"]);
        $unpack = 'php -r ' . escapeshellarg('require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true)
                . '; exit(\app\TenantBuilder::unpackAudit(' . var_export($out, true) . ', ' . var_export($this->manifestFile(), true)
                . ', ' . var_export($this->slug, true) . ', ' . var_export($ws, true) . ', ' . (int) $this->planId . '));');
        $log = escapeshellarg($this->logFile());
        $blog = escapeshellarg($ws . '/.aibuilder/audit-browser' . $this->sfx() . '.log');
        $tunnel = TenantHost::tunnelCommand($tenant, $port);
        $stop = TenantBrowser::qaBrowserStop($port);
        return <<<BASH
#!/bin/bash
# Tiknix audit — {$this->slug}, in its container; its browser runs on the QA host (lib/AuditRunner.php)
# Names this tree for the reaper's process sweep (scripts/reap-stale-tasks.php): the browser and
# the tunnel inherit it, so a tree that outlives its session can be identified and ended.
export TIKNIX_SESSION_NAME="{$this->sessionName}"
echo "[audit] instance {$this->slug} starting \$(date); browser on the QA host, carried here on 127.0.0.1:{$port}, for {$origin}" | tee {$log}
rm -f {$this->escaped($out)}
{$browser} > {$blog} 2>&1 &
BROWSER=\$!
TUNNEL=
# however this ends, the browser on the QA host goes too (ending the connection alone left it running)
stop_qa() { {$stop} >> {$blog} 2>&1; }
trap 'kill \$BROWSER \$TUNNEL 2>/dev/null; stop_qa' EXIT
for i in \$(seq 1 90); do curl -s -o /dev/null -m 2 http://127.0.0.1:{$port}/mcp && break; kill -0 \$BROWSER 2>/dev/null || break; sleep 1; done
curl -s -o /dev/null -m 2 http://127.0.0.1:{$port}/mcp || { echo "[audit] ERROR the browser did not start (see {$ws}/.aibuilder/audit-browser.log)" | tee -a {$log}; exit 1; }
{$tunnel} &
TUNNEL=\$!
sleep 3
kill -0 \$TUNNEL 2>/dev/null || { echo "[audit] ERROR the tunnel into the container did not open" | tee -a {$log}; exit 1; }
{ {$audit} > /dev/null; {$collect}; {$unpack}; } 2>&1 | tee -a {$log}
echo "[audit] exit=\${PIPESTATUS[0]} \$(date)" | tee -a {$log}
BASH;
    }

    private function escaped(string $s): string { return escapeshellarg($s); }

    /**
     * How many checkers to run at once: one per level for a LARGE request, never more than the agent
     * may run at once (its "Tasks at once"; 0 = no limit of its own), and one when the fan is off.
     */
    public static function fanWidth(string $size, int $levels, int $agentCap, bool $on): int {
        if (!$on || $size !== 'large' || $levels < 2) return 1;
        $w = $agentCap > 0 ? min($levels, $agentCap) : $levels;
        return $w >= $levels ? $levels : 1;   // a level per checker, or one checker for them all — never a level left out
    }

    /**
     * The manifests of checkers that worked at once, one per level, as the one manifest the report
     * reads: every level, check, failure and design note; passed only when every part passed. A part
     * that produced nothing is a failure of the check, named — never a pass by omission.
     * @param array<string,array|null> $parts  level => its manifest (null: it produced none)
     */
    public static function mergeManifests(array $parts, int $planId, string $slug, string $base): array {
        $m = ['plan_id' => $planId, 'instance' => $slug, 'base_url' => $base, 'passed' => true, 'summary' => '', 'levels' => [], 'checks' => [], 'failures' => [], 'design' => []];
        $said = [];
        foreach ($parts as $level => $p) {
            if (!is_array($p)) {
                $m['passed'] = false;
                $m['failures'][] = ['label' => "The {$level} check produced no result", 'level' => (string) $level, 'url' => $base, 'message' => "The checker for {$level} ended without writing what it found; that level was not checked.", 'screens' => []];
                $said[] = "{$level}: no result";
                continue;
            }
            if (empty($p['passed'])) $m['passed'] = false;
            foreach ((array) ($p['levels'] ?? []) as $k => $v) $m['levels'][$k] = $v;
            foreach (['checks', 'failures', 'design'] as $k) foreach ((array) ($p[$k] ?? []) as $e) $m[$k][] = $e;
            if (trim((string) ($p['summary'] ?? '')) !== '') $said[] = "{$level}: " . trim((string) $p['summary']);
        }
        if ($m['failures']) $m['passed'] = false;
        $m['summary'] = count($parts) . ' checkers at once — ' . implode(' · ', $said);
        return $m;
    }

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
    /**
     * Plan and Fan, step three: how much the check looks at follows the request's class (PlanRank::rollup):
     *   small   the pages the change list names, as the ONE level they are for, desktop and phone
     *   medium  those pages, as every level that can reach them, desktop and phone
     *   large   the full pass: every level, the pages and what they lead to, then the design look
     * A plan with no scores has no class and gets the full pass (said in the brief, not assumed small).
     */
    public const SIZES = ['small', 'medium', 'large'];

    public static function sizeSection(string $size): string {
        if (!in_array($size, self::SIZES, true)) throw new \InvalidArgumentException("'{$size}' is not a check size (" . implode(', ', self::SIZES) . ')');
        if ($size === 'small') return <<<MD
## How much to check: a SMALL request

This request was scored small. Check ONLY the pages the change list names, as the ONE level each is
for (a member's page as the member, an admin page as the admin) — log in as that level alone; do not
log in as the others, and leave them out of `levels`. Look at each named page at desktop width
(1280×800) and phone width (390×844), one screenshot each. Do not explore further, and skip the
separate "look at each changed page" pass below: one `design` line per named page is enough.
MD;
        if ($size === 'medium') return <<<MD
## How much to check: a MEDIUM request

This request was scored medium. Check the pages the change list names, as EACH level that can reach
them (and as a level that must NOT reach an admin page, to see it refused). Do not explore pages the
change did not touch. Then do the "look at each changed page" pass below for the named pages only.
MD;
        return <<<MD
## How much to check: a LARGE request

This request was scored large (or was not scored): the full pass, every level, below.
MD;
    }

    private function buildAuditRequest(array $creds, array $checklist, int $planId, string $size = 'large'): string {
        $base = $this->baseUrl;
        $sizeMd = self::sizeSection($size) . ($this->part !== '' ? "\n\nYou are ONE of several checkers working at the same time: you check as **" . strtoupper($this->part)
            . "** only (the one account below). The others check the other levels; do not try to.\n\n"
            . "Nobody else checks what " . strtoupper($this->part) . " can DO. Every change in the list above that this level can reach is yours to exercise "
            . "end to end: create one, edit it, delete it, and check what it changed (a sale lowers the stock it uses; deleting it puts the stock back). "
            . "Use throwaway names (\"QA test …\") and delete what you made. Looking at a page is not checking it. If this level is kept out of a page, "
            . "check that it is refused, and move on." : '');
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
`browser_snapshot`, `browser_take_screenshot`, `browser_resize`). Navigate only to `{$base}/...` URLs.

## Test accounts (already created for you)

{$credsMd}

Log in at `{$base}/auth/login` (username = the email). If a **2FA setup/verify** page
blocks an admin/root login: if a "Skip for now" option exists, click it; otherwise record
that level's `login_ok=false` with note "blocked by 2FA policy" and move on — do NOT fail
the whole audit for that.

## What this build changed — verify each of these

{$checklistMd}

{$sizeMd}

## Procedure (repeat for EACH level: root, admin, member — fewer when the size above says so)

1. `browser_navigate` to `{$base}/auth/login`; log in with that level's credentials.
2. Take a screenshot of the landing page after login.
3. Visit each changed page/interaction above that this level should be able to reach.
   Click the primary new controls. Take a screenshot of each meaningful state.
4. Note anything broken: a 403/404/500, a PHP error/stack trace on the page, a control
   that does nothing, or a permission that is wrong for the level (e.g. a MEMBER seeing an
   admin-only action). Capture a screenshot of the failure.
5. Log out (`{$base}/auth/logout`) before switching levels.

## Then look at each changed page as the person it is for

Working is not the same as usable. After the procedure above, as the level a page is FOR (a
member's page as the member), open each changed page that a person uses at a desktop width
(`browser_resize` to 1280×800) and at a phone width (390×844), take a screenshot of each, and
judge what you SEE against these — one line per page in the manifest's `design` list:

- Is it obvious within a few seconds what the page is and what the ONE main thing to do is?
- Is there more than one loud (filled, coloured) button competing for attention?
- Does the list show only what tells one row from another, in words a person would say —
  no column names, raw statuses (`is_active`, `pending_review`) or machine dates?
- Does the form ask only what is needed now, in the order a person thinks of it, every field labelled?
- On the phone: does anything run off the side, need sideways scrolling, or become too small to tap?
- When there is nothing yet, does the page say what belongs there and how to add the first one?
- Does anything look unlike the rest of the app (its own colours, a different kind of table or button)?

These are observations, NOT failures: a page that works but reads badly goes in `design` with
`"verdict": "needs work"` and what you saw; it never goes in `failures` and never makes `passed` false.
Be specific ("three filled buttons in the header: New, Import, Export") — never "could be cleaner".
Resize back to 1280×800 when done.

## Screenshot output convention (IMPORTANT)

Take every screenshot with `browser_take_screenshot` and **no `filename`** — the browser runs
elsewhere and names and saves the file itself; its reply tells you the saved file's name. Record
each one in the manifest as `public/uploads/audit/{$planId}/<that file name>`. Do not create
directories or files for screenshots. They become web-accessible at
`{$base}/uploads/audit/{$planId}/...` and are attached to the report.

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
  ],
  "design": [
    { "page": "/leads", "level": "admin", "verdict": "needs work", "task_ref": "t2",
      "notes": ["three filled buttons in the header: New, Import, Export", "on a phone the table scrolls sideways"],
      "screens": ["public/uploads/audit/{$planId}/admin-leads-phone.png"] },
    { "page": "/leads/edit", "level": "admin", "verdict": "ok", "notes": [], "screens": [] }
  ]
}
```

Rules for the manifest:
- `passed` = `true` only if there are **zero** entries in `failures`. Entries in `design` never change it.
- `screens` paths are RELATIVE to the instance root (start with `public/uploads/...`).
- `task_ref` — when a check/failure corresponds to one of the bracketed `[t#]` refs in the
  change list above, set `task_ref` to that ref so the result posts onto that subtask. Omit
  if it doesn't map to a specific subtask.
- Keep `summary` to one sentence. Every failure MUST include at least one screenshot.
- Write the file with your Write tool. After writing it, reply `AUDIT_WRITTEN` and stop.
MD;
    }
}
