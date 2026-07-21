# ACP branch — CLI-agnostic agent support

This is an **isolated worktree** of tiknix for building **Agent Client Protocol (ACP)**
support, so tiknix can drive any ACP-compatible agent CLI (kimi-cli, Gemini CLI, Qwen,
Goose, Hermes, …) instead of being welded to Claude Code. `main` stays live for current
users; this work merges back only when proven.

- **Branch:** `acp` (off `main`) · **Worktree:** `/var/www/html/default/acp.tiknix/`
- Shares `.git` with the base checkout (`git worktree`), so merging back is trivial.

## Isolation (already set up)

| Concern | Base (`/…/tiknix`, `main`) | This worktree (`/…/acp.tiknix`, `acp`) |
|---|---|---|
| Code | live | `acp` branch (own `lib/`, verified) |
| DB   | `database/tiknix.db` | **separate copy** — live data untouched |
| Config | `conf/config.ini` | own copy, `baseurl = https://acp.tiknix.com` |
| Vendor | live | own `vendor/` (autoloader re-dumped for this path) |

Boots in isolation (`php -r 'require "bootstrap.php"; new app\Bootstrap("conf/config.ini");'`).

## Runtime setup still TODO before running the app/bridges here

1. **nginx vhost** for `acp.tiknix.com` (or a spare port) pointing at this `public/`
   root — mirror the base tiknix server block, incl. the `location ^~ /shop/` and
   `.json`→front-controller rules.
2. **Node bridge deps** if exercising the terminal/chat paths here:
   `aibuilder-terminal/` (npm), `aibuilder-chat/` (composer). Run per-dir installs.
3. If composer deps diverge from base, run `composer install` (currently a copy of
   base vendor + `dump-autoload -o`).

## Decisions (2026-07)

- **Security:** rely on the **bwrap jail** as the boundary; drop the Claude-specific
  PreToolUse hooks. Standards enforcement moves to **pre-merge diff validation**
  (reuse `lib/PhpValidator.php` / `ValidationService`), kept as a Claude-only bonus.
- **Engines:** **native ACP engines first** (kimi `kimi acp`, Gemini CLI, Qwen, Goose,
  Hermes). Defer `pi` (only via the community `pi-acp` MVP adapter).
- **No native PHP ACP library.** Use a **Node sidecar reusing the official
  `@agentclientprotocol/sdk`** (tracks the spec, incl. v2; fits the existing Node-bridge
  pattern). It re-emits the current `{delta|tool|session|done}` frames to PHP.
- **xterm TUI stays the power path** (how most users work today) — it's already
  engine-agnostic (PTY + `ENGINE` env: claude|qwen|hermes).

## Phased plan

- **Phase R — engine registry FIRST (before Phase A):** one `lib/EngineRegistry` +
  `conf/aibuilder.ini [engine.*]` mapping each engine → launch command, transport
  (pty | cli-headless | acp), auth, model tiers (planner|worker|auditor), capability
  quirks. It is the **single resolution point** that BOTH the PTY path and the ACP
  sidecar share, so kimi/gemini/qwen/goose/hermes are **first-class rows, not a
  `claude` + `if qwen` branch**. The engine-name lists duplicated three ways today
  (`aibuilder-terminal/server.js:45`, `controls/Aibuilder.php:311`,
  `lib/PlanIngestor.php:85`) derive from it. This unblocks the dead per-task engine
  field (below) and de-risks Phase 0 (record the capability matrix INTO registry rows).
  See core `AGENT_ORCHESTRATION.md` §7 + its Status section.
- **The per-task `engine` field is DEAD today** — it flows schema
  (`SubmitPlanTool.php:33`) → ingest (`PlanIngestor.php:85`) → DB → UI, but
  **`PlanExecutor.php:407-410` hardcodes the `claude` CLI** and `:178` only *logs* the
  engine. Fix: resolve `$t->engine` through the registry with a **claude fallback +
  warning** when an engine has no headless launcher (`PlanRunner.php:121-141` and
  `AuditRunner.php:118-133` hardcode the same way). This is what "makes the per-task
  engine field real" in Phase B — but the registry (Phase R) lands first.
- **Phase A — engine dispatch (fast win, no ACP):** add kimi/gemini/goose to the
  terminal PTY dispatch (`aibuilder-terminal/server.js`, `jail-run.sh`,
  `conf/aibuilder.ini [engine.*]`) — now registry-driven. Power-path users get engine
  choice immediately.
- **Phase 0 — spike (days):** hand-run `kimi acp` and `npx @agentclientprotocol/claude-agent-acp`
  through the jail; script `initialize → session/new (tiknix mcpServers) → session/prompt`.
  Verify: MCP stdio + HTTP passthrough from inside bwrap (the known jail-hairpin gate),
  whether the Claude adapter still honors hooks, and per-engine `loadSession`.
- **Phase B — structured surfaces on ACP (the real work):** Node ACP sidecar; migrate
  AI Builder **chat** (`aibuilder-chat/server.php`), then **workbench tasks**
  (`lib/ClaudeRunner.php` → ACP session; retire tmux scraping + dialog auto-accept),
  then **planner/executor/audit** (`lib/PlanExecutor.php`, `PlanRunner.php`,
  `AuditRunner.php` — makes the plan schema's per-task `engine` field real).
- **Phase C — cleanup:** per-engine auth (replace OAuth pane-scraping), engine registry
  (extend `instance.engine` + `[engine.*]`), docs.

## Merge back

```
git -C /var/www/html/default/tiknix checkout main
git -C /var/www/html/default/tiknix merge acp
```
Remove the worktree when done: `git worktree remove /var/www/html/default/acp.tiknix`.
