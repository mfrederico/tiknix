# Tenancy & AI Builder Sandbox — design note

**Status: the current subdomain system works as intended. Do not replace it with
runtime request-time tenancy routing.**

Tiknix has two *different* isolation needs that must not be conflated.

## The current model: clone-per-instance (a code-mutation sandbox)

Each `<sub>.tiknix.com` is a **full filesystem clone** at
`/var/www/html/default/<sub>.tiknix`, with its own `conf/config.ini`, its own
`database/<sub>.db`, and `vendor/` symlinked. Capricorn's `provision-instance.sh`
clones the repo and calls `scripts/aibuilder-provision.php`. The **webserver** maps
the subdomain to that docroot — routing happens *below* the app.

The AI Builder agent (`lib/ClaudeRunner.php`) runs in a **bwrap jail** rooted at the
instance directory. `scripts/hooks/security-sandbox.php` restricts writes via
`TIKNIX_PROJECT_ROOT`, and `ClaudeRunner.php` refuses to run against the main tree.

**The isolation unit is the directory, because the agent edits code.** That is the
whole point — one instance's code changes can never touch another instance or the
source tree.

## The pattern NOT to adopt here: runtime workspace routing

`../dealeryes/lib/WorkspaceResolver.php` uses **one** codebase/docroot and swaps the
RedBean connection per request based on the `Host` header
(`switchDatabase()` → `conf/config.<slug>.ini`). Shared code, per-tenant DB,
**zero code isolation.**

That is fine for a *finished* app serving its own customers — but it cannot isolate
a code-editing agent: one edit to the shared code would hit every tenant at once,
destroying the sandbox.

## How they relate

| | Isolation unit | Code | Scale | Lifecycle |
|---|---|---|---|---|
| Clone-per-instance (current) | filesystem dir + jail | mutable | dozens | **build** phase |
| WorkspaceResolver (dealeryes) | DB connection | shared/immutable | thousands | **run** phase |

WorkspaceResolver is a **runtime feature a built app may use for its own tenants** —
not tiknix's mechanism for isolating builder instances. Scaffold could even emit
workspace-aware CRUD so a generated app inherits it.

## If app-level workspace switching is ever added to tiknix

- **Gate it OFF whenever a jail is active** — a mid-session `switchDatabase()` would
  pull the DB out from under a builder that assumes one stable (code, config, DB) triple.
- Route it through `parse_db_dsn()` / `DB_DSN` (one backend code path), **not**
  dealeryes' hardcoded sqlite/mysql branches.
- Keep **MCP multi-homing per-clone**: `codebase_map`/`describe`/`whatprovides`
  introspect *code*, which lives in the filesystem clone. Only data-facing tools
  would ever care about a workspace switch.
