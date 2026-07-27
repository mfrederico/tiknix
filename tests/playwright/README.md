# End-to-end regression suite

One standard, applied everywhere: **a control must resolve, and it must do what its
label says.** Around that sit the checks you cannot see on a screenshot — console
errors, failed XHRs, and what the servers wrote to their own logs while the test ran.

```bash
cd tests/playwright
npm install
npx playwright install chromium     # once
cp .env.example .env                # fill in E2E_PASS
npm test
npm run report                      # open the HTML report
```

## What it does

| spec | covers |
|---|---|
| `01-core-sweep` | every admin page of core: renders, links resolve, labels are honest, buttons are named |
| `02-public-and-auth` | the signed-out surface, the login/logout doors, 404 and 403 behaviour |
| `03-project-lifecycle` | create a project → build a small app in it → serve it → wire up publishing → delete it |
| `04-sidecars` | each sidecar through core's SSO hop, audited inside its iframe |

## The disposable project

`03` creates its own project (`e2e<random>`), builds a controller + view + seed inside
it, checks the page serves on its own domain, and deletes it again through the builder's
danger zone. Nothing it touches is yours.

Cleanup does not depend on the tests passing. `lib/global-teardown.js` removes whatever
the run created — through core's HMAC provisioning door, the same one the builder uses —
clears the archive that a delete leaves behind, and puts your **selected project** back
the way it was, since selecting one is an account-wide change.

If teardown ever fails it prints `LEAKED INSTANCE:` with the slug and id. That is the
one message in the output worth acting on immediately.

## Blast radius

The suite runs against the live site as an admin, so it **finds** every control but only
**fires** the destructive ones inside its own disposable project. A Delete button on
`/admin` is checked for existence and wiring; it is not clicked.

Two paths cost real resources and are therefore opt-in:

- `E2E_HOSTED=1` — a container deploy, which spends hypervisor capacity
- `E2E_GITHUB=1` — a real push to a real repo

Default runs use neither.

## Known red

`04-sidecars › the builder's terminal bridge is up` fails on this host: nothing is
listening on `127.0.0.1:3990`, so `wss://<core>/aibuilder/ws` answers 502 and the
builder's terminal is dead even though the page renders. That is a true finding, not a
flaky test — it goes green when the bridge service runs.

## Adding to it

- New page in core? Add it to `PAGES` in `01-core-sweep.spec.js`. The last test in that
  file fails if the nav grows a link the suite does not visit, so you will be told.
- Expecting an error in a test? Declare it: `watch.allowConsole(/…/)`,
  `watch.allowServer(/…/)`. Declaring noise keeps it visible in the test that expects it,
  instead of lowering the bar for everyone.
- Judging labels lives in `lib/audit.js` — `LABEL_EXCEPTIONS` for labels whose
  destination cannot be inferred, `SYNONYMS` for words this product uses
  interchangeably. Both should stay short.
