# Tiknix Security Readiness — audit + plan (2026-09-11)

Three parallel audits ahead of paid signups: an external OWASP probe of tiknix.com and the
sidecars, a process-isolation audit of how projects are separated on the host, and a
cross-tenant (BOLA/IDOR) audit of the application and billing service. All read-only; no
exploitation, code-confirmed plus a few harmless live GETs.

This file records what was found, what has already been fixed, and the ordered plan for the
rest. It is the single source of truth for "is tiknix ready for members to feel confident".

---

## Already fixed and live (this session)

| # | Was | Now | Evidence |
|---|-----|-----|----------|
| Archive leak | 13 deleted projects published their own DB (password hashes, reset tokens, API keys) at `https://<slug>.tiknix.com/<slug>.zip`, unauthenticated | Moved to `secure/archives/` (mode 600, not web-served); `ProvisionService::archiveInstance` writes there now, not `public/` | `aspire.zip` 200 → 404; 0 left under any `public/` |
| Billing SSO impersonation | An app secret could sign an SSO URL naming ANY customer and get their session + cards | An existing email is honoured only if already linked to the signed tenant | cross-business URL → 303 login, 0 links forged; own customer → dashboard. `a3c22e7` |
| 2FA bypass (C1) | Password + one GET to `/auth/twofaconfirmsaved` = ROOT session | Requires POST + CSRF + a verified-setup session key | live replay: GET bounces to setup, session stays anonymous. `ae71d3b` |
| Pipeline RCE demo (C3) | `demo-hello.json` interpolated `{context.who}` into a shell command, exposed as API | Greeting is fixed text; name flows through a non-shell template | `ae71d3b` (ShellStep itself still needs argv — see plan) |
| Core control-plane DB | `database/tiknix.db` mode 0777, world-writable (member hashes, `instance.app_key`) | 600, owner-only; `core.tiknix` copy too | `stat` 777 → 600, site still 200 |
| Debug disclosure (H1) | `environment=development, debug=1` leaked stack traces + full paths to visitors | `production` + `debug=0` | `/index/privacy` leak markers → none; `APP_SESSION` now `Secure` |
| Backup exposure | `*.bak-*` (DB snapshots, `stripe.ini` with live keys) untracked-but-addable, some 0644/0664 | gitignored in both repos, chmod 600 | `de92c55`, `5782a98` |

---

## CRITICAL — do before opening signups

### C4. CSRF is bypassable app-wide (patch ready, sandbox-blocked)
`Control::validateCSRF()` branches on `Flight::request()->method`, which Flight derives from
`?_method=` / `X-HTTP-Method-Override` (CVE-2026-42551). A cross-site POST to any handler
with `?_method=GET` skips validation. **Confirmed live**: `POST /auth/dologin?_method=GET`
with a bogus token reached "Invalid credentials" instead of "Security validation failed".
Blast radius: 51 call sites, 14 controllers.

Fix — in `controls/BaseControls/Control.php`, `validateCSRF()`:
```php
$verb = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));
if ($verb !== 'GET') {   // was: if (Flight::request()->method !== 'GET') {
```
Patch is written and dry-run-clean at
`scratchpad/c4-csrf-method-override.patch`. The write is blocked by the tiknix sandbox hook
(base controllers require ADMIN) — apply it yourself. Same one-line idea belongs in the
sidecar kit's `Kernel` and billing's CSRF check.

### C2. Permission layer fails OPEN
`PermissionCache.php:131` — a route with no `authcontrol` row returns
`$userLevel <= LEVELS['PUBLIC']`, i.e. reachable by guests. Every new controller ships
public; a lost row silently opens a route. All 31 currently-uncovered routes happen to
self-gate (both audits checked each), so nothing is exposed **today** — but the default is
backwards and one new controller changes that.
Fix: `return $userLevel <= self::defaultLevelFor($control, $method);` (deny by default), and
seed real rows via `PermissionCache::seedRule()`.

### C3. ShellStep passes substituted tokens into a shell
Beyond the demo file: `ShellStep` runs `bash -lc escapeshellarg($cmd)` where `$cmd` was
already token-substituted **unescaped** by `Vars.php`. Any member who can author a pipeline
(self-service `pk_` key, `pipeline::mintkey` at level 100) has command execution as the web
user, on core and every clone. Fix: give ShellStep `command` + `args`, pass argv to
`proc_open`, never substitute into the command string.

### App-secret / broker-key custody (both audits, independently)
`conf/config.ini` `[billing] app_secret` is **byte-identical** to the billing `tiknix` app
`api_secret`, and it ships inside instance clones at mode 0644 (e.g.
`campground-rater-3a1999`, owned by a member). Any project owner can read it and drive the
billing SSO (now identity-locked, but still) and app-authority endpoints. Same shape for
`conf/broker.ini` (raw broker token in every clone, and `Mcp::handleToolsCall` never
enforces the key's `scopes`, so a broker key acts as its full member on core).
Fix: stop shipping `app_secret`/broker tokens into clones; derive per-instance; rotate both;
enforce `scopes`/`key_class` in `handleToolsCall` before dispatch.

---

## HIGH — before or immediately after launch

- **H/self-grant (`POST /member/settings`)** — a member can POST `feature.mcp=1` etc. into
  their own settings; `Feature::stored()` reads those same rows, unlocking key-minting,
  invites, email, sidecars. Allowlist writable keys; reject `^feature\.`.
- **Firehose shared ingest key** — every instance shares one key, so anyone can inject a
  task into another tenant's `workbench.db`; with `auto_triage`, launches an agent on the
  victim's repo. Per-instance ingest key; reject a mismatched `instance` tag.
- **`provision::call` `is_root`** — caller-supplied `is_root:true` in the signed payload
  grants delete of any tenant's project. Read level from core's member row, not the payload.
- **MCP pipeline tools unscoped** — `pipeline_run/continue/get/list` have no level check
  (siblings `_set/_delete` call `requireAdmin`); a level-100 member runs the install's
  automations with its stored connector creds. `requireAdmin()` on the mutating tools.
- **MCP HTTP Basic auth** — accepts username+password, no rate limit, bypasses the `mcp`
  feature grant and 2FA. Drop Basic auth or rate-limit + gate it.
- **SSRF in pipeline HTTP step** — `HttpStep` checks only `^https?://` then follows
  redirects; `169.254.169.254`, `127.0.0.1`, LXC NAT all reachable.
  `RestConnector.php:529` already has the right `NO_PRIV_RANGE|NO_RES_RANGE` guard — reuse.
- **SQLi via `?order_by=`** — `WorkbenchAccess.php:272` interpolates raw `$orderBy` into
  `ORDER BY`; SQLite subselects make a boolean exfil oracle over `workbench.db`. Latent twin
  in `TaskAccessControl.php:414`. Allowlist columns.
- **Reflected XSS into a ROOT session** — `layout.php` toast uses `addslashes` (doesn't
  escape `<`/`/`), tainted by `Mcptools`/`Hooks` `getParam('name')`. `json_encode` with
  `JSON_HEX_TAG`. Plus stored-XSS gaps (`Communications`, `Webhook` regex sanitizers →
  `views/teams/view.php`) and `javascript:` surviving the regexes.
- **Sidecar session cookies** — `WORKBENCH/EXPLORER/PUBLISHER/PIPELINES_SESSION` have no
  Secure/HttpOnly/SameSite (kit applies hardening only inside the `cookie_domain` branch,
  which only `shop` sets). Move `session_set_cookie_params` outside that branch in the kit.
- **Security headers** — only `x-content-type-options` + HSTS present; no CSP,
  X-Frame-Options, Referrer-Policy, Permissions-Policy; `server:` leaks the openresty
  version. Set at the nginx layer (operator; sandbox-blocked here).
- **Team management + several admin/apikey/contact actions lack CSRF**, some act on GET
  (`Admin.php:360` deletes an `authcontrol` row via bare GET — which via C2 makes the route
  public). Add CSRF; make them POST.
- **Mailgun signature skippable** — `if ($signingKey !== '' && !empty($sig))`: omit the
  `signature` object and verification is skipped. Absent signature = 403. Attachment writer
  has no extension allowlist and writes under a web-served path.
- **Upgrade Flight ≥ 3.18.1** — `flightphp/core v3.17.0` carries 5 advisories (this is the
  root of C4 and H1). Same in billing-service.

---

## Process isolation — the structural verdict

**One OS trust domain today.** Every instance, core, and every sidecar runs as
`uid 1000 ubuntu` in one `php-fpm` pool with `open_basedir` unset; every instance's DB,
config and broker key is owned by `ubuntu` and readable by the others. So filesystem
permissions provide **zero** isolation between projects — code executing in one member's
project can read every other member's data. The genuine, good boundary is the **bwrap jail**
around a *running agent* (drops caps, no `/home` `/etc` `/root`, own tmux socket in the
Builder path) — but the artifact it leaves runs unjailed in the shared pool, and the jail
shares the host network with `bypassPermissions`. Proxmox LXC per tenant is the design that
closes this, and **1 of ~15 instances** uses it today.

The three highest-value moves, in order:
1. **Per-instance PHP-FPM pool + uid + `open_basedir`** (`/var/www/html/default/<slug>.tiknix/:/tmp/`).
   Single biggest gain short of LXC; closes cross-instance file reads, broker/app-secret
   theft, and the shared control-plane DB in one move.
2. **Per-instance tmux socket** for `TmuxManager` (the Builder terminal already does this;
   the task path uses the shared default socket, so one agent can `send-keys` into another
   member's session).
3. **Migrate real tenants onto the LXC path** and treat shared-host instances as
   preview/free only. Add cgroup/ulimit limits so one runaway build can't starve others
   (none exist today).

---

## What to tell members (the trust surface)

- `/index/privacy` and `/index/terms` **500 today** — the views don't exist
  (`views/index/privacy.php` missing). With debug now off a visitor sees a clean 500, but a
  paid product needs real privacy + terms pages before signups. This is a launch blocker of
  a different kind: legal, not code.
- No security page describes isolation/encryption to a prospective member; once the isolation
  work lands, a short "how we protect your projects" page converts.

---

## Standing capability to build (so this doesn't rot)

**There is no automated security scanning in either repo** — no dependabot, semgrep, psalm
taint, CodeQL, ZAP, or pre-commit security hook; the only CI is a base-image build.
`lib/PhpValidator::scanSecurity` exists but runs on demand, not in CI. Add:
- `composer audit` + dependabot (or Renovate) on both repos — would have surfaced the Flight
  CVEs that are C4/H1.
- semgrep (php ruleset) in CI, failing on taint into `exec`/`ORDER BY`/echo.
- a scheduled OWASP-ZAP baseline against a staging copy.
The point of the scanner is that the four criticals here are the kind a linter catches; a
human audit before each launch does not scale, a gate in CI does.

---

## Suggested order

1. **Today**: apply C4 (patch ready), rotate `app_secret` + broker tokens, stop shipping
   them into clones. (Archive leak, SSO, 2FA, DB perms, debug already done.)
2. **This week**: C2 deny-by-default, C3 ShellStep argv, the HIGH self-grant / firehose /
   is_root / MCP-pipeline / SSRF / order_by set, sidecar cookie hardening, Flight upgrade,
   nginx security headers.
3. **Before real tenants**: per-instance FPM pool + uid + open_basedir, per-instance tmux
   socket, privacy/terms pages.
4. **Standing**: CI security scanning so the next four don't ship silently.
