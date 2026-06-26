# AI Builder

In-app, sandboxed Claude Code / qwen-code sessions for building and customizing
software. Replaces the old Ollama section. Each member provisions one or more
isolated **`<slug>.tiknix`** instances — an independent git clone with its own
SQLite database — and edits it through a jailed Terminal and Chat. Every change is
reversible via git-native checkpoints.

## Architecture (3 tiers)

```
Browser (/aibuilder)                 tiknix app                 host
┌──────────────────┐  same-origin   ┌──────────────┐  shell    ┌──────────────────────────┐
│ xterm Terminal   │──ws /aibuilder/ws──▶ node bridge :3990 ──▶ │ bwrap jail (jail-run.sh) │
│ Chat UI          │──ws /aibuilder/chat-ws──▶ php bridge :3991 ▶ │  agent confined to ONE   │
│ Checkpoint/Roll  │──https POST──▶ controls/Aibuilder.php ─────▶ │  <slug>.tiknix instance  │
└──────────────────┘                └──────────────┘            └──────────────────────────┘
```

- **The bubblewrap jail is the security boundary** (agent runs with permissions
  off). The tiknix app only gates access (ADMIN), mints a short-lived HMAC token,
  validates instance ownership, and brokers snapshot/rollback.
- The two WebSocket **bridges are shared, app-agnostic daemons** living at
  `/var/www/html/default/aibuilder-terminal` and `/var/www/html/default/aibuilder-chat`.
  They already serve other apps; tiknix simply mints tokens they accept.

## What lives in this repo

| Path | Purpose |
|------|---------|
| `controls/Aibuilder.php` | Gatekeeper: list/create instances, mint token, checkpoint/rollback |
| `models/Model_Instance.php` | FUSE model for the `instance` bean (owner, slug, engine, status) |
| `views/aibuilder/index.php` | Instance picker + create form + Terminal/Chat tabs |
| `conf/aibuilder.ini` | Shared HMAC secret + bridge paths + access level (gitignored) |
| `conf/aibuilder.example.ini` | Committed, secret-free template |
| `scripts/aibuilder-provision.php` | Seeds a freshly cloned instance's SQLite DB (run by capricorn) |

Permission: `aibuilder::*` at level 50 (ADMIN) in `authcontrol`.

## Host prerequisites

- `bwrap` (bubblewrap), `node` (v24 for node-pty ABI), `pm2`, and the `claude`
  CLI on PATH.
- The **capricorn** instance scripts at `/home/ubuntu/capricorn/bin/`
  (`jail-run.sh`, `provision-instance.sh`, `snapshot-instance.sh`,
  `rollback-instance.sh`). `tiknix` must be listed in `SUPPORTED_APPS` in
  `aibuilder-common.sh` (already added).
- The web/fpm user must be able to run the capricorn scripts and create
  directories under `/var/www/html/default` (provisioning does a `git clone`).
  If it needs elevation, set `[ops] sudo_prefix = "sudo -n "` in `conf/aibuilder.ini`.

## The shared secret

`conf/aibuilder.ini [token] secret` **must equal** `AIBUILDER_TOKEN_SECRET` in
BOTH bridge env files:
- `/var/www/html/default/aibuilder-terminal/.env`
- `/var/www/html/default/aibuilder-chat/.env`

Tokens are `base64url(json{app,sub,member_id,exp}).hmac_sha256_hex`. Verified
PHP-mint → Node-verify parity. `conf/aibuilder.ini` is copied into every
provisioned instance by `provision-instance.sh`, so the secret propagates.

## Running the bridges (pm2)

```
cd /var/www/html/default/aibuilder-terminal && pm2 start ecosystem.config.cjs
cd /var/www/html/default/aibuilder-chat     && pm2 start server.php --name aibuilder-chat --interpreter php
```
Both bind `127.0.0.1` only; nginx terminates TLS and proxies.

## nginx

Two same-origin WebSocket locations (on the tiknix vhost), plus subdomain routing
so each provisioned instance is reachable.

```nginx
# --- AI Builder WebSocket bridges (same-origin) ---
location /aibuilder/ws {            # terminal
    proxy_pass http://127.0.0.1:3990;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
location /aibuilder/chat-ws {       # chat
    proxy_pass http://127.0.0.1:3991;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

Instance subdomains (`<slug>.tiknix.<tld>`) must serve from
`/var/www/html/default/<slug>.tiknix/public` — e.g. via the capricorn Lua
`determine_env.lua` router (as used for the other apps) or an explicit server
block / wildcard vhost. The instance is a full tiknix app with its own DB.

## Usage

1. Admin opens **/aibuilder**.
2. Create an instance (slug + engine). Provisioning clones tiknix, runs
   `composer install` for a real per-instance `vendor/`, seeds an isolated SQLite
   DB, installs guardrails, and tags a baseline checkpoint.
3. Open it → **Chat** (friendly) or **Terminal** (full xterm). First time on the
   `claude` engine, run `claude setup-token` in the Terminal to log in the
   account that pays (bring-your-own-account; the operator key is never injected).
4. **Checkpoint** before risky changes; **Roll back** to restore code AND data.

## Operational notes

- Engines: `claude` (Claude Code) or `qwen` (qwen-code on an OpenAI-compatible
  backend, default Ollama :cloud). Per-instance via `<instance>/.aibuilder/engine`.
- Optional hardening: drop jailed sessions to a dedicated uid via
  `AIBUILDER_UID` in the bridge envs (`aibuilder-setup-host.sh`), and the
  `firewall-aibuilder.sh` egress firewall / `aibuilder.slice` cgroup.
- **Provisioning requires committed code**: `provision-instance.sh` clones the
  app's git HEAD, so `scripts/aibuilder-provision.php` (and the rest of this
  feature) must be committed for new instances to seed correctly.
- **Real per-instance vendor (not a symlink)**: `scripts/aibuilder-provision.php`
  replaces capricorn's default `vendor/` symlink with a real `composer install`.
  A symlinked vendor makes composer's `__DIR__`-relative files-autoload resolve
  back into the source tree, double-including `lib/functions.php` ("Cannot
  redeclare" fatal). A real vendor also lets each tenant manage its own deps.
- **Converting an existing symlinked instance**: if an instance was created with
  the old symlink and you swap in a real vendor, php-fpm may keep serving the
  symlink-era resolution from opcache (and briefly the realpath cache, TTL 120s),
  still double-including the source `functions.php`. Reload php-fpm (or call
  `opcache_reset()`) to clear it. Fresh instances are never affected.
