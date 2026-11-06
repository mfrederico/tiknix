Playwright-style browser automation with **no browser on the server**. Classes `app\concepts\browserdrive\Session`; controller
`Browserdrive` (`/browserdrive`); MCP tool `browserdrive_drive`; beans `drivesession`, `drivestep`.

### How it works

An agent opens a **session** on a page of this site and sends **steps**. The **runner page** (`/browserdrive`, left open by the admin
whose API key opened the session, any tab) claims it, shows the page in a frame of this site, plays each step with the events a
person's mouse and keyboard fire, and posts the result back. That admin's runner not open = nothing plays: a session nobody claims expires
after 5 minutes and says so. That is not a fault in the page; say in your reply that the check is still to do.

### Driving it

- MCP: `browserdrive_drive` with `action: open {path}`, then `step {session, cmd}` per command (`result {step}` asks
  again for one still pending; `png: true` returns a screenshot's PNG), then `close`.
- Scripts: the same tool at `/mcp/message` (JSON-RPC `tools/call`, `Authorization: Bearer <an admin API key>`).
- Node: `/browserdrive/client` serves the client (save it as `browserdrive-client.mjs`): `const page = await connect({ base, key, path })`, then `page.click(…)`,
  `page.fill(…)`, `page.press('Enter')`, `page.waitForText('Saved')`, `page.text('.total')`, `page.evaluate('…')`,
  `page.screenshot('a.png')`, `page.close()`. A failed step throws with the runner's reason.
- Commands (`cmd.do`): goto reload back viewport click dblclick hover fill type press check uncheck select drag mouse wheel
  waitFor wait evaluate text value attr count html url title screenshot console. Targets: a CSS selector, `text=Save`,
  `xpath=//…`, or `{x, y}` where the frame shows now. A drag's two ends must fit on screen together, or it fails and says to set a taller `viewport`. Actions wait up to 5 s for their target to exist and be visible.
- Each result carries `url`, `ms` and the page's console lines and errors since the last step (`logs`): read them.

### Rules

- **Sandbox only.** A driven page acts as the person whose runner plays it, signed in as them. Sessions may only open paths
  an admin listed on `/browserdrive` (setting `browserdrive_allow`); `goto` is checked too. Never ask for real customer pages
  to be allowed; build or use a demo page and demo data.
- The API key must be an admin's, the same admin whose tab plays the session: nobody else's key can open a session in it or send it steps.
- `evaluate` and `waitFor` with `js` are refused until an admin switches script on at `/browserdrive`: script reaches every page of the
  site, not only the sandbox ones. Do not ask for it unless a check cannot be done without it. Read the page with `text`, `attr`, `count`.
- Input is synthetic (`isTrusted` false): pointer lock, fullscreen, starting audio, file pickers and the clipboard cannot be
  driven. A canvas screenshots exactly; other parts are drawn from the page (close, not pixel-perfect).
- Use it to check what you built and what a page test cannot reach (a flow across pages, an editor, a canvas). Not as a
  ritual after every change, and never start a browser of your own instead.
- Do not edit this plugin's files; it is installed from the catalog.
