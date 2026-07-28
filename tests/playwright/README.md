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

## The terminal bridge

`04-sidecars` checks that `wss://<core>/aibuilder/ws` reaches the node bridge on
`127.0.0.1:3990`. It accepts **only 101 or 401** — a 404, a redirect and a 502 all mean
the same thing to a user (no terminal), so none of them may pass. Probed over HTTP/1.1,
which is what a browser's websocket handshake uses; over HTTP/2 the same URL answers 404
because `Upgrade` is not a legal header there.

## Adding to it

- New page in core? Add it to `PAGES` in `01-core-sweep.spec.js`. The last test in that
  file fails if the nav grows a link the suite does not visit, so you will be told.
- Expecting an error in a test? Declare it: `watch.allowConsole(/…/)`,
  `watch.allowServer(/…/)`. Declaring noise keeps it visible in the test that expects it,
  instead of lowering the bar for everyone.
- Judging labels lives in `lib/audit.js` — `LABEL_EXCEPTIONS` for labels whose
  destination cannot be inferred, `SYNONYMS` for words this product uses
  interchangeably. Both should stay short.

## The recorded demo

`demo/` is not part of the suite. It is a **recording**: Tiknix building a shift-scheduling
tool from a written spec, every beat a real action against the live control plane —
a project really is provisioned, the planner really decomposes the spec against that
project's reuse inventory, and the orchestrator really builds the subtasks.

```bash
DEMO_REHEARSE=1 npm run demo    # walk every beat up to Decompose, then clean up
npm run demo                    # the real take (spends a planner run, ~30-45 min)
```

**Rehearse first, always.** The rehearsal proves the selectors, the provisioning and the
video file for the cost of one throwaway project and about ten seconds. A real take
spends a frontier planner run and half an hour; discovering a moved selector then is an
expensive way to find out.

The spec the planner receives is `demo/scheduler-goal.md` — edit that, not the script, to
change what gets built.

Shoot it in **two segments** — nobody wants to watch a planner spin:

```bash
# segment 1 — drop the spec in, hit Decompose, cut on the banner (~1 min of footage)
DEMO_USE_PROJECT=<signed-in slug> DEMO_STOP_AT_DECOMPOSE=1 npm run demo

# ...the planner keeps running off camera. When its plan lands:

# segment 2 — open on the plan, approve, build, end on the live app
DEMO_USE_PROJECT=<same slug> DEMO_PLAN_ID=<plan id> npm run demo
```

Both open on the same board at the same size, so they cut together:

```bash
printf "file 'seg1.webm'\nfile 'seg2.webm'\n" > list.txt
ffmpeg -f concat -safe 0 -i list.txt -c copy demo.webm
```

| knob | default | what it does |
|---|---|---|
| `DEMO_PROJECT` | `scheduler` | project name (a hash is appended, as always) |
| `DEMO_SLOWMO` | `350` | ms between actions — a click that lands instantly reads as a glitch |
| `DEMO_BEAT` | `2500` | ms to hold on a screen so a viewer can read it |
| `DEMO_BUILD_MINUTES` | `30` | how long to keep filming the build |
| `DEMO_USE_PROJECT` | — | reuse a project instead of creating one (needed while credentials are per-project) |
| `DEMO_STOP_AT_DECOMPOSE` | — | end segment 1 on the banner; the planner keeps working |
| `DEMO_PLAN_ID` | — | start segment 2 on an existing plan, no planner spend |

Video lands in `demo-recordings/**/video.webm` (1280x720, so the footage needs no
reframing), deliberately OUTSIDE `test-results/` — playwright clears that directory on
every run, and a recording nested in it is deleted the next time the suite runs. The demo
project is **kept** — you just watched it get built.

Its config lives in `demo/`, not beside the suite's, because Playwright auto-discovers
`playwright*.config.*` in the project root and loading two configs breaks collection for
both.
