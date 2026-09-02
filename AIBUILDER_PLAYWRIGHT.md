# AI Builder — Playwright MCP (agent self-testing)

Each instance's `.mcp.json` registers a **stdio** playwright MCP server alongside the
`tiknix` introspection server, so the jailed agent can drive a headless browser to
test the layout/design it just built:

```json
{
  "mcpServers": {
    "tiknix": {
      "type": "http",
      "url": "https://<slug>.tiknix.com/mcp/message",
      "headers": { "Authorization": "Bearer <agent token>" }
    },
    "playwright": { "command": "npx", "args": ["-y", "@playwright/mcp@latest", "--headless", "--isolated"] }
  }
}
```

`tiknix` is **HTTP, not stdio**, and that is deliberate — over HTTP the agent gets the
instance's full tool set (~27 tools) instead of the 9-tool stdio allow-list, and the
server keeps working if the instance moves hosts or sits behind a load balancer. (This
doc previously showed `{"command": "php", "args": ["mcptools/mcp-stdio.php"]}` here.
That is core's own config, not what a provisioned instance receives.) See the long note
at `scripts/aibuilder-provision.php` §4. `playwright` stays stdio: it is a browser the
agent launches, not a service.

`.mcp.json` is **generated, never inherited** — it is gitignored (`273151f`) because a
workspace copy carries a live bearer token. Three writers, no clone:
`aibuilder-provision.php` writes it per provision (`chmod 0600`),
`Mcp::ensureMcpConfig()` regenerates the workspace copy, and `PlanExecutor` copies the
instance's file into each git worktree.

New instances get this at provision time (`scripts/aibuilder-provision.php`).
Backfill or repair existing instances:

```bash
php scripts/add-playwright-mcp.php <slug|dir>   # one instance
php scripts/add-playwright-mcp.php --all        # every provisioned *.tiknix
```

The script adds the entry when missing **and repairs one whose args have drifted** —
present is not the same as correct, and an entry written before `--isolated` was
required is present, wrong, and leaks a browser profile per run. It replaces only
`args`, so a hand-edited `command` survives. Note `--all` globs
`/var/www/html/default/*.tiknix` only: **task workspaces under `projects/<member>/<id>/`
are not reached** and need their directory passed explicitly.

### Both flags are load-bearing

`--isolated` gives the browser a temp profile discarded on close. Without it the profile
persists, and where it lands decides the cost: jailed it is RAM (`/tmp` is a tmpfs, see
below) and dies with the jail; unjailed it is `~/.cache/ms-playwright/mcp-chrome-<hash>/`
on real disk, kept forever. `-y` keeps `npx` off the install prompt inside the jail,
where nothing can answer it.

Neither flag prevents a *leak*. When an agent session never exits, its chrome tree stays
up parented to the tmux **server** rather than init — so it does not read as an orphan
and nothing reaps it. That is `scripts/reap-stale-tasks.php`'s job (cron, `*/5`);
`--isolated` only bounds what one leak leaves behind.

The agent then has tools like `browser_navigate`, `browser_snapshot`,
`browser_take_screenshot`, `browser_click`. The **Copy browser-test prompt** button
in the builder drops a ready prompt (pointing at the instance URL) to paste into the
terminal agent.

## Runtime prerequisites (host / jail)

Playwright runs **inside the bwrap jail** (the agent spawns it via `.mcp.json`), so the
jail must be able to run it. Confirm these on the host that runs the jail:

1. **node / npx on the jail PATH.** `jail-run.sh` sets `PATH_DIRS` to
   `/usr/local/bin:/usr/bin:/bin` — a node under `~/.nvm` is **not** on it. Either
   install node system-wide or extend the binds/PATH in `jail-run.sh`. Verify inside a
   jailed shell: `npx --version`.
2. **Chromium + system libraries.** `jail-run.sh` mounts `--tmpfs /tmp` and sets
   `XDG_CACHE_HOME=/tmp/.cache` and `npm_config_cache=/tmp/.npm`, so **the jail does not
   see the host's `~/.cache/ms-playwright`** and starts every session with an empty
   cache. Browsers must therefore resolve somewhere the jail actually binds — in
   practice a system install (`/opt/google/chrome`, which is what playwright-mcp picks
   up by default here), not a per-user `npx playwright install` under `$HOME`.
3. **Network reachability.** `--unshare-net` is **absent on purpose** and load-bearing:
   the agent reaches its own MCP over HTTPS, and a private netns kills that quietly (the
   agent starts, the tools are just gone). So the jail shares the host's network
   namespace and adds no egress restriction of its own. Point browser tests at the
   instance's **public URL** (`https://<slug>.tiknix.com`) rather than `localhost` or an
   RFC1918 address — but note that any such block is a **host-level** rule, not the
   jail's. *This doc previously attributed it to a "jail firewall"; that was wrong. The
   host rules were not re-verified when this was corrected — confirm before relying on
   a locally-served preview being unreachable.*

If any of these is missing the MCP server will fail to start or the browser will hang;
check the agent's MCP logs. Until the host is set up, the `playwright` entry is inert
(the `tiknix` server is unaffected).

## Alternative (not yet built)

If in-jail browsers are impractical, a host-side "screenshot/preview" endpoint (like the
Publish flow — a gatekeeper action outside the jail, where node + browsers are already
available) can render the instance URL and return an image. Ask if you want that instead
of / in addition to the in-jail MCP.
