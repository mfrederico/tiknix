# QA Testing — plan

Status: phases 0–5 built (see "Built" at the end). Written 2026-10-02 from the owner's brief and a read of the
existing system (sidecar kit, the post-plan audit, the builder's job model, feature flags).

## What it is

A premium sidecar, **QA Testing**, in core's left nav for the selected project. Per project it
holds a set of described tests, runs them as background jobs **outside the customer's app**,
shows each test as a traffic light while it runs, keeps receipts, and turns results into a
short report whose findings the owner can send to the Builder as a plan. A second pane does
code review of what the Builder changed. Everything is JSON first: the page is a client of
the same API that later becomes MCP tools.

| Light | Meaning |
|---|---|
| Blue | not run yet |
| Amber | queued or running |
| Green | passed |
| Red | failed, or could not run (the row says which) |

## Principles

1. **Independent.** Nothing of the verifier runs in the customer's container or on the app's
   own agent credential. Today's post-plan audit does both (the app's agent drives the browser,
   screenshots are copied into the app's `public/uploads`), so it is not reused as the engine —
   only its lessons are.
2. **Replays, not improvisation.** A model is used to *write* a test from a concept and to
   *explain* a failure. A run itself is a deterministic replay: same steps, same assertions,
   no model in the loop. That is what makes a green light mean something, and what makes a
   run cheap enough to queue freely.
3. **Quiet by default.** Green produces no text. Amber and red get dug into. "No findings" is
   a normal, expected result of a review.
4. **The owner decides.** Tests are edited only by the project's owner. Nothing is sent to the
   Builder, retired or rewritten automatically — the system recommends, the owner clicks.
5. **Only the project's own addresses.** A run may reach the project's container domain and
   the domains bound to it, nothing else. Security checks are passive (see below): no fuzzing,
   no load, nothing destructive.

## Where it lives

- **Sidecar** `qa` (`qa.tiknix`, `https://qa.tiknix.com`), built on sidecar-kit exactly like
  workbench2: `[sidecar.qa]` in core's config, `embedded = true`, project from the SSO claim,
  never its own project picker. Name and routes are `[a-z0-9]` only (the kit strips anything
  else); "QA Testing" is the label.
- **Flag** `qa` in `Feature::CATALOG`. That gives the per-member switch on the Admin member
  page, the nav entry and the SSO gate with no further code. The flag is the SKU for now
  (there is no per-feature billing yet); runs are metered from day one so pricing can follow.
  The **owner's** flag switches QA on for their project; team members of that project can
  see results and start runs, and cannot edit tests, credentials, or send to the Builder.
- **Per-project data** in `_workspaces/<slug>/data/qa.db` (beside `workbench.db`, same
  lifecycle: archived and removed with the project). Receipts in
  `_workspaces/<slug>/qa/receipts/<run>/`, served by the sidecar behind its session — never
  copied into the customer's app.
- **The queue** in the sidecar's own `data/qa.db` (it spans projects).
- **Secrets** sealed with `EncryptionService::encryptWith()` and a key file in
  `qa.tiknix/secure/qa.key`.

## The test, as data

One JSON document per test — the thing the API, the page, the runner and later MCP all share.

```json
{
  "id": "t_8f2c",
  "title": "A member cannot open another member's invoice",
  "concept": "Invoices are private to the member they belong to.",
  "kind": "web | api | mcp",
  "lifetime": "durable | one-off",
  "state": "draft | active | retired",
  "origin": "owner | plan-acceptance | openapi | suggested",
  "persona": "member",
  "mutates": false,
  "steps": [ … recorded actions … ],
  "expect": [ … assertions … ],
  "receipts": ["screenshot" | "response"]
}
```

- **web** — recorded Playwright actions and assertions, replayed headless in Chromium on the
  sidecar's host against the project's public address. Receipt: screenshots at the asserted
  steps, plus the failing step's screenshot and console errors.
- **api** — an HTTP request and expectations (status, JSON shape, a few fields, time). No
  browser. Receipt: the request and the response, secrets redacted. Drafted in bulk from an
  OpenAPI/Swagger document when the project has one (`OpenApiSpec` already parses both).
- **mcp** — `initialize`, `tools/list`, then `tools/call` with given arguments; expectations
  on the tool list and on results. Also asserts the negative: `tools/call` without a key is
  refused. Receipt: the JSON-RPC exchange.
- **lifetime** is set when the test is written and shown everywhere: a *one-off* validates a
  specific fix or change; a *durable* test protects a concept that should stay true.
- **mutates** — a test that writes data says so. Mutating tests do not run unless the owner
  switched them on for that test, because the target is the live app.

## Building tests — like the Builder

The owner types a concept ("members can only see their own invoices", "the booking form
refuses a past date"). A QA author agent turns it into draft tests, the owner reviews and
activates them — the same submit → draft → approve shape as a Builder plan.

What the author reads: the concept, PLAN.md's *Acceptance checks* per persona (already
written for the audit — a ready source of durable tests), the project's inventory
(`TenantBuilder::digest`), and its OpenAPI document if any. For a web test it drives the app
once through Playwright and **records** what it did; the recording, not the agent, is the test.

Kept tight on purpose: a concept yields the fewest tests that would catch it breaking
(usually one to three), each test asserts one thing, and the author must say for each whether
it is durable or one-off and why.

## Signing in

A web or API test names a persona; the persona's sign-in is supplied once by the owner:

1. **Tiknix apps — nothing to supply.** The runner creates a throwaway account at the right
   level for the run and deletes it after (what the audit does today). No stored secret.
2. **A session token** — a cookie, header or bearer value the owner pastes.
3. **Login and password** — for a test account, with the login page and field names.

2 and 3 are sealed at rest, never shown again, never put in a prompt, a log or a receipt. The
runner signs in itself and hands the recording an already-signed-in browser, so no model sees
a credential even while a test is being authored. The page says plainly: use a test account.
Two-factor on a test account blocks option 3 — said at save time, not discovered at run time.

## Running

- `POST /qa/api/runstart` queues a run (all active tests, or a chosen set). A cron drain
  starts queued runs; one run per project at a time, a small global cap.
- A run is a detached process with a result file, in the builder's existing style (tmux
  session, `TIKNIX_SESSION_NAME` set so leaked browsers are cleaned up by the existing sweep).
- The page polls `GET /qa/api/run?id=` every two seconds — the same data a machine gets. Each
  test flips blue → amber → green/red as it finishes.
- A test that cannot run (sign-in refused, address unreachable) is red with that reason, and
  is never counted as a failed assertion.

## Results → report → Builder

After a run with any amber/red, a reviewer model (a different model from the project's
builder, per the decorrelated-review rule) reads the failures and receipts and writes:

- **For the human:** three to six lines — what broke, what it means for a user, what is fine.
- **Findings**, each with a category (functional, UX/UI, data integrity, security, API
  contract), severity (amber/red), the evidence (receipt link), and a **recommendation for an
  agent**: the smallest change that fixes it, and what must not be touched.
- **Test upkeep:** which one-off tests have done their job and can be retired; which durable
  tests duplicate each other; which should be augmented because the flow changed (the replay
  broke on a step, not on an assertion). Recommendations only.

**Send to Builder** (owner only) takes the findings the owner ticks and files them as one
draft plan on the project's board through `PlanIngestor` — reviewed and built like any plan.
It deliberately does not go through the error firehose, which can start fix builds by itself.

Security findings come from passive checks only: response headers, cookie flags, CSRF tokens
on forms, and the authorization matrix (each persona requesting the others' pages and
records — the broken-access-control class). Mapped to OWASP categories in the report.

Guard against over-engineering, built into the reviewer's contract: a finding must cite its
evidence; a recommendation is one change, not a programme; no "consider adding", no
speculative hardening, no new abstractions; a hard cap on findings per run.

## Code Review pane

The planned "lens 2" of `AGENT_ORCHESTRATION.md`: a review of the code itself, read over SSH
as a diff (a task branch, or everything merged since the last review).

- Deterministic checks first (the existing PHP/RedBean/Flight validators, unrecorded
  overrides) — facts, not opinions.
- Then a model that is not the builder's worker model.
- Each changed file gets a light. **Green files get no text.** Amber and red get at most a
  few findings each: the line, what goes wrong (or what it costs a reader), and the change.
- It prefers complete, readable code over clever code: nested ternaries, dense one-liners and
  magic are amber. It does not raise style nits, praise, or refactors nobody asked for.
- A finding the owner dismisses is remembered against that code and not raised again.
- Findings can join a "Send to Builder" plan like test findings.

## API (the page uses only this)

No hyphens in routes. Session auth for the page; API keys (scope `qa`) for machines.

```
GET  /qa/api/tests            list            POST /qa/api/testsave     owner
GET  /qa/api/test?id=         one             POST /qa/api/testretire   owner
POST /qa/api/concept          owner: concept → draft tests (queued)
POST /qa/api/runstart         queue a run     GET  /qa/api/run?id=      status + per-test lights
GET  /qa/api/runs             history         GET  /qa/api/receipt?…    a screenshot / exchange
GET  /qa/api/report?run=      summary + findings
POST /qa/api/tobuilder        owner: findings → draft plan
POST /qa/api/review           queue a code review     GET /qa/api/reviewresult?id=
POST /qa/api/credential       owner: store a persona's sign-in (write-only)
```

Later, MCP: tools in core's `mcptools/qa/` (a sidecar's own tools are not discovered by the
gateway) that call the same service classes, offered only when the flag is on for that
project's owner.

## Phases — each ends in something usable and is proven with Playwright

0. **Skeleton.** Sidecar, flag, nav entry, per-project db, empty test list with blue lights.
1. **API tests + the run machinery.** Test documents, queue, runner, polling lights, receipts,
   OpenAPI import. No browser and no model yet — the whole pipeline proven on the simplest kind.
2. **Web tests.** Personas and sign-in (throwaway accounts first), recorded replays, screenshots.
3. **Concept → tests.** The author agent, PLAN.md acceptance checks, draft/approve.
4. **Report and Send to Builder.** Reviewer, findings, upkeep recommendations.
5. **Code Review pane.**
6. **MCP tests, MCP tools, API keys, metering page, scheduled runs** (nightly / after a build).

## Decisions (owner, 2026-10-02)

1. **Tiknix pays for the model.** An admin creates a Tiknix AI agent for QA on core's AI agents
   page, with its API key; the sidecar's `[qa] agent` names it (`qa`). Never the project's agent.
2. **Stored test logins are allowed** (sealed, test accounts only); throwaway accounts are the
   default for Tiknix apps.
3. **Team members view and run, never edit.**
4. **The post-plan audit stays** as the Builder's own check; from phase 3 a finished plan's
   acceptance checks become QA tests.
5. **Mutating tests are off unless switched on per test.**

## Built

- **Phase 0 (2026-10-02):** the `qa.tiknix` sidecar (SSO door, per-project `qa.db`, test
  documents, `/qa/api/{status,tests,test,testsave,testretire}`, the list with a light per
  test), the `qa` flag, the nav entry between Builder and Deploy, and the QA Testing card on
  the Deploy page (`[qa] available = false` → "Coming soon").
- **Phase 1 (2026-10-02):** API tests and the run machinery. A test is one request to the
  project's own site plus expectations (status, json path, header, body, max_ms), replayed with
  no model. Runs are queued (`qajob` in the sidecar's db — also the meter), started by
  `scripts/qa-drain.php` (cron every minute, kicked on demand; one per project, two across),
  executed by `scripts/qa-run.php`; the page polls `/qa/api/run` every two seconds. Receipts
  keep the request and the answer with credentials replaced. Personas' sign-ins for API tests
  (bearer / header / cookie) are sealed with the sidecar's key — brought forward from phase 2,
  since an API test without one can only test signed-out behaviour. Drafts from an OpenAPI
  description (GETs without path values). A request that can change data needs the per-test
  mark. Operations as built: `teststate`, `credentials`, `credential`, `credentialremove`,
  `openapi` beside the planned ones. Web and MCP tests can be described and stay drafts.
- **Phase 2 (2026-10-02):** web tests. Steps (goto, click, fill, select, check, press, wait,
  screenshot) with targets by label / role / text / placeholder / test id / CSS, and page
  expectations (text, no_text, url, title, visible, hidden, no_console_errors), written by
  hand in the form until phase 3 records them. Replayed by `runner/replay.mjs` (Playwright,
  Chromium with its sandbox on, a fresh browser per test, 120 s cap). The browser is fenced:
  the page stays on the project's site, and nothing it loads may come from a private,
  loopback or link-local address (`runner/fence.mjs`) — the page under test is the owner's
  code running in a browser on the platform's host. Screenshots: one at the end state, one
  where a step stopped, one per screenshot step (8 per test), kept in the project's workspace
  for its newest 20 runs and served by `/qa/api/shot` behind the session. Sign-ins: `account`
  (a throwaway account at root/admin/member, made through the app's own clitool for the run
  and deleted after; written down first so one a dead runner left is removed by the next run)
  and `login` (a stored test account, sealed); a token/key/cookie persona is sent to the
  project's site only. A test that types into or changes the page needs the per-test mark.
  `[qa] node` and `[qa] browsers` in the sidecar's config name the Node.js binary and the
  browsers folder — cron and the web server have a bare PATH.
- **The QA browser host (2026-10-02, owner's decision):** the browser left the control plane's
  host. Web tests run in ONE dedicated container (`lib/QaHost.php`, `scripts/qa-host.php`,
  `tenant/qa.sh`) — a linked clone of the tenant template like a project's, 3 GB / 8 GB, no app
  in it — whose firewall refuses every connection out to a private address, so a page under
  test cannot reach another project's container, the control plane or the gateway; it reaches
  projects at their public address, as a visitor does. Not the project's own container: that
  would put the checker inside what it checks, and a browser in a 1 GB app container. The
  sidecar's `QaBrowser` sends a test over SSH and gets the result and screenshots back;
  nothing of a test stays on the host. `[qa] node`/`browsers` are gone with the local browser.
  Phase 3's authoring agent will drive this same host's browser through Playwright MCP.
- **Phase 3 (2026-10-02):** concept → draft tests. The owner types what should be true and
  picks who to look at the app as; a drafting job is queued (`qaauthor`, the same queue as
  runs). Core runs Tiknix's own `qa` agent for it through `lib/PlatformAgent.php` — claude with
  NO built-in tools (`--bare --tools ""`), only the MCP server the job names, no settings or
  memory from the host, a scratch HOME, a spending cap, the answer checked against a JSON
  schema — because the agent reads pages written by project owners. Its one MCP server is the
  QA host's browser (Playwright MCP over SSH stdio, no port opened), started from the sign-in
  state the runner left there, so the model never sees a credential. It gets the concept, the
  address, the app's page inventory and the titles of existing tests; it answers with one to
  three tests in the form's vocabulary, each durable or one-off with why. Each is replayed once
  (`QaRunner::trial`) and saved as a draft carrying what the replay showed; a failed attempt
  gets one correction round. `[qa] author_max_usd` (1.50 a round) and `author_daily` (20 a
  project) bound the spending; the cost of each job is on its queue row. "Use the plan's
  acceptance checks" pulls PLAN.md's acceptance section into the box. First real jobs: about
  20 seconds and 3–4 cents each.
- **Phase 4 (2026-10-02):** suites, reports, Send to Builder.
  - **Suites** (owner's addition): a named, ordered set of tests run as a whole — `webapp`
    always exists; `owasp` and `uptime` are one-click starters; the owner can add their own.
    A suite's tests are its steps (the owner orders them); with stop-on-fail the rest are
    recorded as *not run* (blue), never as failed. Its description is given to the drafting
    agent, so a concept is drafted for the suite it is typed into. One run is one suite's.
  - **Report**, on demand ("Explain this run", owner only — not after every red run: a
    scheduled suite would spend on each one). The `qa` agent with NO tools reads the failed
    tests, their steps and receipts: a summary, at most five findings about the APP (each tied
    to a test, with evidence, one smallest-change recommendation and what to leave alone), and
    upkeep for the TESTS (retire / update / merge). A finding not tied to a test of the run is
    dropped. `[qa] report_max_usd` (0.50) and `report_daily` (30). First reports: under a cent,
    and on contrived failing tests it correctly returned no findings and blamed the tests.
  - **Send to Builder** (owner only): the ticked findings become ONE DRAFT PLAN on the
    project's board (`scripts/qa-agent.php --to-builder` → PlanIngestor), each task carrying
    the evidence, the fix, what to leave alone and how it was checked. Refused while another
    draft plan waits for review. Not through the firehose.
  - A one-off test that has passed says it can be retired — a hint, the click is the owner's.
  - Still to come with phase 6: a schedule per suite, and one pipeline step in a project that
    runs a suite by name and reads its result (needs the machine key for the QA API).
- **Phase 5 (2026-10-02):** the code review pane.
  - **What is read:** the project's commits since the last review ended (the first time: its
    last 10), added and changed files only — vendored code, lock files, minified assets, images
    and the generated CLAUDE.md are left out; at most 40 files and 12 KB of patch each, and
    what was not read is named. Read over SSH by core (`lib/QaDiff.php`,
    `scripts/qa-agent.php --diff`); nothing of the project is run.
  - **Facts first:** `ValidationService` on each changed PHP file, kept only where it is about
    THIS change (a line the change added, or anywhere in a file it created), and the app's
    unrecorded overrides (the catcafe Admin failure, as a check).
  - **The agent** (`qa`, no tools) reads the patches under a contract built against a model's
    urge to say something: only amber/red files are listed; at most 3 findings a file and 12 a
    review; each must quote ONE added line (verified against the patch — else dropped) and be
    marked proven by the patch itself (else dropped); the prompt carries the platform's facts
    (wildcard permission seeds, Bean::, no migrations) so they are not "found"; style, praise,
    "consider…" and refactors are named as not findings. Readable over clever: nested ternaries,
    dense one-liners and magic values are amber.
  - **Dismissals** are kept per project by the line of code (path + the quoted line), so the
    same finding is not raised again while that line stands.
  - **Send to Builder:** ticked findings → one draft plan, as for a run's findings.
  - `[qa] review_max_usd` (1.00) and `review_daily` (10). First real review (catcafe, 19 files):
    13 seconds, 6 cents, 18 files green, one finding. The first attempt also produced a guess
    about wildcard permission seeds — which is what added the "proven" rule and the platform
    facts.
  - Not done: reviewing a single task branch before it merges (the plan's other input); the
    review model is the `qa` agent's, which is not guaranteed to differ from a project's
    builder model.
  - Page: a test is edited in place, inside its own row (the one form, moved in), and each
    test's icons sit on a line of their own.

