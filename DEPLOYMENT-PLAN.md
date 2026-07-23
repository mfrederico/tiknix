# DEPLOYMENT-PLAN — shipping "Deploy" for tiknix

**The question:** should tiknix deployment be Terraform (a `terraform` pipeline step, full
IaC), or lighter "direct-to-provider" deploys (Vercel / Netlify / Cloudflare Pages /
Spaceship / Hyperlift) fired from GitHub events through the Integrations hub?

**The answer (verified against the codebase):** Terraform is *one heavy deploy step among
several*, not the deployment model. Ship deployment as an **integration + step** —
a deploy connector (token in broker custody) + a GitHub-push webhook on core + a deploy
pipeline in the instance repo — and add `terraform` later as an opt-in step type for the
minority who need real IaC. Roughly 90% of the work is already built; the one genuinely
missing primitive is an **inbound GitHub webhook** (verified: `controls/Webhook.php` is
Mailgun-only today).

---

## 1. Landscape — how code reaches "deployed" today, and the gap

What exists (all verified):

- **Code → GitHub is done.** `lib/GitHubPublisher.php` snapshots an instance's working
  tree (secrets excluded via a temp index + `rm --cached conf/*.ini`) and pushes to the
  member's own repo as branch `aibuilder-publish`, opening/reusing a PR
  (`lib/GitHubService.php` handles PR create/merge/comment). `controls/Connections.php`
  owns the GitHub connect flow (OAuth or PAT) and stores the token **encrypted** in the
  core `connections` bean with `metadataJson = {owner, repo, defaultBranch, autoPublish}`,
  scoped `member_id + instance_id`. `autoPublish` already re-publishes on every AI Builder
  checkpoint.
- **Pipelines are the automation runtime.** `pipelines/*.json` in the instance repo, run
  by `lib/Pipeline/Executor`, steps in `lib/Pipeline/Steps/*Step.php` (a new step = one
  file implementing `StepInterface`: `type()`, `schema()`, `run()`). Long runs go through
  `lib/Pipeline/Dispatcher.php` → detached `pipeline-run.php`, **jailed via bubblewrap**
  (`jail-run.sh`, `JAIL_CMD`) on capricorn instances. `Executor` supports **await/pause/
  resume** (`['await'=>true]` → run pauses, `Runner::continueRun($id,$input)` resumes) —
  a ready-made approval gate.
- **Triggers exist, but only cron + bearer.** `POST /pipeline/trigger/<slug>`
  (bearer = the instance's `[pipeline] trigger_secret`, `controls/Pipeline.php::trigger`),
  the fake-cron `scripts/pipeline-cron.php` (globs every instance's pipelines, fires
  triggers via curl_multi), `expose_as_api` / `expose_as_tool`. Core can fire an
  instance's pipeline via `lib/InstanceAutomations.php::trigger()` (reads the instance's
  `conf/config.ini` baseurl + trigger_secret from the filesystem — core is on the same
  box).
- **Broker custody is done.** Connectors (`services/connectors/*Connector.php`,
  auto-discovered by `ConnectorRegistry`) hold third-party creds encrypted in core's
  `connections` table; instances reach them via a `brk_` key through
  `controls/Mcp.php::brokerToolCall` — token decrypted server-side, used, zeroed; the
  credential never reaches the instance. The `connection` pipeline step
  (`lib/Pipeline/Steps/ConnectionStep.php`) already calls `<connector>:<tool>` through
  the broker from inside a pipeline. `StripeConnector` shows the generic-proxy pattern:
  a `request` broker tool = any method+path+body against the provider API, auth injected
  server-side. `Connections::connectkey` supports **API-key connectors** with
  validate-before-store (`validateApiKey`) — exactly what Vercel/Netlify tokens are.
- **Durable objects** (`stateful:true` pipelines, `dobject` table, persisted `{state}`,
  alarms via `objecttick`) — a natural home for per-instance release state (last sha,
  status, lock).
- **Integrations hub** (`/connections`, `controls/Connections.php::index` +
  `InstanceAutomations`) already surfaces connectors + pipelines + durable objects per
  instance.

**The gap (the whole feature, really):**

1. **No inbound GitHub webhook.** `controls/Webhook.php` handles only Mailgun (but it
   is the exact template: PUBLIC route, self-authenticating HMAC, deliberate response
   codes). Today nothing turns "push to main on GitHub" into an event inside tiknix.
2. **No deploy provider connectors.** Only Stripe/Shopify/Instagram exist.
3. **No deploy step / pipeline / UI.** Nothing represents "a deployment" anywhere.
4. **No terraform binary** on the box (verified: `which terraform` → nothing), no state
   story, no plan/apply gate.

So the loop is open at exactly one hinge: **GitHub → tiknix**. Everything after that
hinge (trigger → jailed pipeline → broker-authed provider call → durable state → hub UI)
already exists.

---

## 2. Two deploy models, weighed for tiknix

### A. Lightweight "direct-to-provider" (deploy connector + step + GitHub webhook)

The flow: push to GitHub (manual, or the existing auto-publish PR merge) → GitHub fires a
webhook at core → core verifies HMAC, maps repo→instance (the `connections` row already
stores `owner/repo` per instance), and fires the instance's `deploy` pipeline via the
existing trigger mechanism → the pipeline's `connection` step calls
`vercel:create_deployment` (or `netlify:...`) through the broker — provider token
injected server-side, never in the instance or its repo.

**What's genuinely new:** one webhook action on core, one or two ~200-line connectors,
one seed pipeline JSON, one hub card. Everything else is reuse:

| Need | Reuse |
|---|---|
| Provider token custody | `connections` bean + `EncryptionService` + `connectkey` (api_key path) |
| Calling the provider from a pipeline | `ConnectionStep` → broker → `<provider>:request` (StripeConnector's generic-proxy pattern, copied) |
| Firing on push | new `Webhook::github` → `InstanceAutomations::trigger()` (exists) |
| Background/jailed execution | `Dispatcher` (exists, automatic) |
| Deploy history/lock/status | durable object (`stateful` pipeline) or plain `piperun` rows |
| Surfacing it | Integrations hub card (existing patterns in `Connections::index`) |

**Pros:** minimal new code; providers do the actual build/CDN/SSL heavy lifting; deploys
are fast (seconds to trigger); tokens follow the proven custody model; per-provider
"named tools + generic `request`" matches the house connector style; users understand it
("push → live"). Vercel/Netlify both support "create deployment from a GitHub repo ref"
via one POST, and both also support **deploy hooks** (a provider-minted URL where a bare
POST triggers a build) — meaning the *simplest* deploy step is literally the existing
`http` step, no connector required, for day one.

**Cons:** not IaC — no provisioned infra (DBs, DNS, queues), only "app on a PaaS"; a
per-provider connector treadmill (mitigated by the generic `request` tool + deploy-hook
fallback covering any provider we haven't wrapped); the deploy target must be provisioned
once by hand (acceptable — connecting is a one-time UI flow, same as Stripe).

### B. Terraform-as-a-step (`lib/Pipeline/Steps/TerraformStep.php`)

The flow: `.tf` files live in the instance repo (e.g. `deploy/terraform/`) next to
`pipelines/`; a `terraform` step runs `init` → `plan` in the jail, pauses on
`['await'=>true]` with the plan text as the prompt, a human approves in the hub,
`continueRun()` resumes into `apply`.

It *fits the primitives* — that's the honest surprise of this recon:

- **Execution:** `Dispatcher` already jails long runs via bwrap; terraform is just a
  binary inside `JAIL_CMD`. (It must be *installed* and the jail must allow its outbound
  HTTPS + plugin cache — real ops work, not code.)
- **Approval gate:** `Executor`'s await/continueRun is precisely a plan→approve→apply
  gate; no new engine needed.
- **Creds:** provider API keys via the broker — a pre-step fetches a *short-lived*
  provider credential (e.g. a scoped cloud token) through `connection`, exports it as
  env to the terraform step; the long-lived key still never lands in the repo. (Caveat
  honestly: unlike model A, the credential does transit the jail's process env during
  apply — weaker custody than "never leaves core." Prefer providers that can mint
  short-lived tokens.)
- **State:** remote backend (Terraform Cloud / S3-compatible) is correct; "state in a
  durable object" is tempting but wrong — tfstate is large, secret-laden, and needs
  locking semantics tiknix shouldn't reimplement. Verdict: require a remote backend,
  offer a core-hosted S3-compatible bucket later.

**The real weight:** a ~90MB binary + per-provider plugins in every jail; state backend
setup per instance; plans are slow (minutes); a failed apply can strand real cloud
resources billed to the *member*; drift detection, targeted destroys, and `-lock` races
are a support surface an "AI Builder for non-programmers" audience will hit face-first.
Terraform's *users* are the ~5% who outgrew PaaS.

**When it's worth it:** custom infra (own VPC/DB/DNS), multi-resource environments,
teams that already have `.tf`. It should exist — **as one more step type**, the
"heavy step" alongside `agent` and `shell`, not as the deployment model.

---

## 3. Recommendation

**Ship A now; add B later as an opt-in step.** Concretely:

1. Deployment enters tiknix as an **integration + step**, exactly the owner's framing:
   connect a deploy provider on the Integrations hub → a seeded `deploy` pipeline appears
   → pushes to the connected GitHub repo fire it.
2. The **GitHub inbound webhook is the keystone** and is provider-agnostic — build it
   first; it also unblocks future "on push" automations that have nothing to do with
   deploys (run tests, notify, sync).
3. Start with **deploy-hook support via the existing `http` step** (zero new step code,
   works for Vercel/Netlify/Cloudflare Pages today) plus **one real connector (Vercel)**
   with `create_deployment` / `get_deployment` / generic `request` tools. Add Netlify/
   Cloudflare as demand shows; Spaceship/Hyperlift via deploy-hook/generic-HTTP until
   they earn a connector.
4. **Terraform ships in a later phase** as `TerraformStep` + jail provisioning + remote
   state + the await approval gate. It slots in with *zero* changes to the webhook,
   trigger, or hub built in A — proof A is the right foundation.

Why not Terraform-first: it solves provisioning, but tiknix's users' actual gap is
"my instance's code is on GitHub, now make it a live site" — a PaaS POST, not a plan
graph. Terraform-first means months of jail/state/safety work before the first user
deploys anything; A means the first deploy works in the first phase.

---

## 4. Phased build plan

### Phase 1 — GitHub push webhook → pipeline trigger (the keystone)

*New:* `Webhook::github` action in **`controls/Webhook.php`** (extend, don't add a
controller — matches Mailgun's self-authenticating pattern; route
`webhook::github = 101` PUBLIC via an authcontrol seed in `database/seeds/`).

- Verify `X-Hub-Signature-256` (HMAC-SHA256 of the raw body) against a per-connection
  `webhookSecret` stored in the GitHub connection's `metadataJson` (generated at
  connect/publish time). Constant-time compare, like the Mailgun handler.
- Map repo→instance: `Bean::find('connections', "connector_type='github' AND enabled=1")`
  matching `metadataJson.owner/repo` against the payload's `repository.full_name`.
  (Consider adding an indexed `external_eid = owner/repo` on GitHub rows to skip the
  JSON scan.)
- Filter events: `push` to the default branch (and `pull_request.closed` with
  `merged:true` — the auto-publish PR flow ends in a merge, which is the natural
  "go live" moment). Respond 200 fast on ignored events.
- Dispatch: for each pipeline in the instance whose `trigger.github` matches (see step
  shape below), call `InstanceAutomations::trigger($dir, $slug, $context)` with context
  `{event, ref, sha, repo, pusher, commits[]}`.
- *Extend:* `lib/Pipeline/Loader.php` to accept + validate `trigger.github`
  (`{"events":["push"],"branches":["main"]}`) beside `trigger.cron`; surface it in
  `InstanceAutomations::pipelines()`.
- *Extend:* `Connections::add`/`callback` (GitHub connect) to auto-create the repo
  webhook via the GitHub API (`POST /repos/{o}/{r}/hooks`, new method on
  `lib/GitHubService.php`) pointing at `https://<core>/webhook/github`, with the minted
  secret; fall back to showing manual setup instructions when the token lacks
  `admin:repo_hook`.

### Phase 2 — deploy connector + seeded deploy pipeline

*New:* **`services/connectors/VercelConnector.php`** (auto-registers via
`ConnectorRegistry`) — `auth_type: api_key` (reuses `Connections::connectkey`
validate-before-store; `validateApiKey` = `GET /v2/user`), `category: 'Deploy'`,
broker tools: `create_deployment` (POST /v13/deployments with `gitSource` =
the connected repo+ref), `get_deployment`, `list_deployments`, and the generic
`request` proxy (copy `StripeConnector::apiRequest`, JSON body instead of form).

*New:* seed pipeline template `deploy.json` (offered from the hub, written into the
instance's `pipelines/`):

```json
{ "slug": "deploy", "name": "Deploy to Vercel",
  "trigger": { "github": { "events": ["push"], "branches": ["main"] } },
  "steps": [
    { "name": "ship", "type": "connection",
      "config": { "connector": "vercel", "tool": "create_deployment",
                  "arguments": { "ref": "{context.sha}" } } },
    { "name": "announce", "type": "notify",
      "config": { "message": "Deployed {context.sha} — {ship.output.url}" } } ] }
```

Deploy-hook variant needs no connector at all: an `http` step POSTing the provider's
hook URL (stored as a step config value, not a secret — deploy hooks are
trigger-only). Document both; the hub offers hook-mode for providers without a
connector (Cloudflare Pages, Spaceship, Hyperlift).

### Phase 3 — Deploy card + history in the Integrations hub

*Extend:* `controls/Connections.php::index` + `views/connections/` — a "Deploy" card:
provider connection status, the deploy pipeline (create-from-template button), last
deploys (read `piperun` rows for slug `deploy` via the existing read-only instance-DB
access in `InstanceAutomations`), a manual "Deploy now" button (existing
`pipelinerun` action).

*New (small):* durable object template `release.json` (`stateful:true`) the deploy
pipeline messages on start/finish — holds `{current_sha, previous_sha, status,
started_at, lock}`; the deploy pipeline's first step checks/sets the lock (prevents
two pushes racing), last step records the outcome and clears it. This also gives
one-click **rollback**: re-fire `create_deployment` with `previous_sha`.

### Phase 4 — more providers

`NetlifyConnector` (api_key; `POST /api/v1/sites/{id}/builds` + generic `request`),
`CloudflarePagesConnector` if hook-mode proves insufficient. Each is ~a day given the
Vercel template.

### Phase 5 — Terraform as a heavy step (opt-in)

*New:* **`lib/Pipeline/Steps/TerraformStep.php`** — `type(): 'terraform'`; schema
fields: `dir` (default `deploy/terraform`), `action` (`plan|apply|plan_apply|destroy`),
`backend` config, `var_env` (map of env vars filled from prior `connection` steps'
outputs); `run()` shells `terraform init -input=false` + `plan -out=tfplan
-detailed-exitcode`; in `plan_apply` mode returns `['await'=>true, 'prompt'=>
<plan text>]` — the existing pause/approve/`continueRun` machinery *is* the apply gate
— then `apply tfplan` on resume.

*Ops (not code):* install terraform + a shared plugin-cache dir into the capricorn
jail image; allow its outbound HTTPS; require a remote state backend (document
Terraform Cloud free tier first; core-hosted S3-compatible later).

*Custody:* a `connection` step fetches short-lived provider creds via the broker
before the terraform step; document plainly that terraform creds transit the jail env
(weaker than model A) and scope tokens accordingly.

Gate this phase on demand — it changes nothing built in phases 1–4.

---

## 5. Open questions / risks

1. **Webhook auth + replay (top risk).** `/webhook/github` is PUBLIC and fans out into
   instance pipeline dispatches. Mitigations: mandatory per-connection HMAC secret
   (never optional-in-prod like the Mailgun dev fallback), delivery-id (`X-GitHub-
   Delivery`) dedupe, event/branch allowlist, and a per-instance rate limit — a
   webhook flood must not turn `Dispatcher` into a fork bomb (it `exec`s a detached
   worker per dispatch).
2. **Repo→instance mapping ambiguity.** Same repo connected to two instances (dev/prod
   environments are per-connection already) → the webhook would fire both. Decide:
   fire all matches (each instance's own `trigger.github.branches` filters), and put
   `owner/repo` in `external_eid` for exact indexed lookup.
3. **Deploy-secret custody.** Provider tokens: broker custody, solved. Terraform-phase
   env-transiting creds: weaker — prefer short-lived tokens; never write creds into the
   repo or `pipelines/*.json` (the repo is pushed to GitHub!). Deploy-hook URLs are
   semi-secret (trigger-only) — acceptable in step config, but say so in the UI.
4. **Concurrency + rollback.** Two pushes in quick succession = racing deploys; the
   `release` durable-object lock handles it, but the lock must expire (stale-lock TTL
   via the object's alarm) or a crashed run bricks deploys. Rollback = redeploy
   `previous_sha`; that's PaaS-rollback only — Terraform destroy/rollback is
   explicitly out of scope for the step's first cut (plan/apply only, no auto-destroy).
5. **What exactly deploys.** The instance is a PHP/SQLite app; Vercel/Netlify are
   static/serverless-first. For instances that are truly "a site," fine; for full
   tiknix-runtime instances the honest v1 targets are (a) static export/front-end
   deploys and (b) providers that run PHP (a container target — later). Don't promise
   "your instance runs on Vercel" — promise "your repo deploys to your provider."
   This framing question should be settled before the hub copy is written.
