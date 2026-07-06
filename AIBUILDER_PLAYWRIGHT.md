# AI Builder — Playwright MCP (agent self-testing)

Each instance's `.mcp.json` registers a **stdio** playwright MCP server alongside the
`tiknix` introspection server, so the jailed agent can drive a headless browser to
test the layout/design it just built:

```json
{
  "mcpServers": {
    "tiknix":     { "command": "php", "args": ["mcptools/mcp-stdio.php"] },
    "playwright": { "command": "npx", "args": ["-y", "@playwright/mcp@latest", "--headless", "--isolated"] }
  }
}
```

New instances get this at provision time (`scripts/aibuilder-provision.php`).
Backfill existing instances:

```bash
php scripts/add-playwright-mcp.php <slug>     # one instance
php scripts/add-playwright-mcp.php --all       # every *.tiknix
```

The agent then has tools like `browser_navigate`, `browser_snapshot`,
`browser_take_screenshot`, `browser_click`. The **Copy browser-test prompt** button
in the builder drops a ready prompt (pointing at the instance URL) to paste into the
terminal agent.

## Runtime prerequisites (host / jail)

Playwright runs **inside the bwrap jail** (the agent spawns it via `.mcp.json`), so the
jail must be able to run it. Confirm these on the host that runs the jail:

1. **node / npx on the jail PATH.** node exists on the host (e.g. under `~/.nvm`), but
   the jail may not bind that path. Either install node system-wide (`/usr/bin`) or
   ensure `jail-run.sh` binds the node bin dir. Verify inside a jailed shell: `npx --version`.
2. **Chromium + system libraries.** Install once where the jailed `npx` resolves:
   `npx playwright install --with-deps chromium`. The browser cache
   (`~/.cache/ms-playwright`) must be readable inside the jail.
3. **Network reachability.** The jail firewall blocks **loopback and RFC1918** egress.
   So the browser can only reach the target over the **public internet** — point tests
   at the instance's public URL (e.g. `https://<slug>.tiknix.com` on a public IP), not
   `localhost` or a private-range address. A locally-served preview will not be
   reachable under the current firewall.

If any of these is missing the MCP server will fail to start or the browser will hang;
check the agent's MCP logs. Until the host is set up, the `.mcp.json` entry is inert
(the `tiknix` server is unaffected).

## Alternative (not yet built)

If in-jail browsers are impractical, a host-side "screenshot/preview" endpoint (like the
Publish flow — a gatekeeper action outside the jail, where node + browsers are already
available) can render the instance URL and return an image. Ask if you want that instead
of / in addition to the in-jail MCP.
