# Model connections — bring your own model

*Planned 2026-09-23. Owner's ask: "people can apply their own ollama keys, and anthropic keys
to build agents … like a connection, but as an agent or model type … if they have a model they
can run on their own machine or infra, they can specify an openai endpoint and have it work …
ollama … openrouter.ai."*

## What exists (and what is broken)

| Piece | Today | Problem |
|---|---|---|
| Build agents (PlanRunner → PlanExecutor → AuditRunner) | `claude` CLI in `jail-run.sh`; `ENGINE=<name>` picks an `[engine.*]` ini row | Engines are **operator** config. A member cannot bring an endpoint. |
| `[engine.qwen]` | `cli_flavor = openai`, model `qwen3-coder:480b-cloud` | Model **retired 2026-07-15**; `agentCommand()` returns null for non-claude flavors, so qwen tasks fail anyway; `OPENAI_API_KEY` only ever comes from the operator's env (`jail-run.sh:296`). |
| `MemberEnginePrefs::setToken` | per-member engine token, encrypted with core `app_key` | **Never reaches a run**: nothing writes it where `jail-run.sh` reads it (`$STATE_DIR/auth-token`). |
| Pipeline agents (`Model_Agent`, per install) | `cli` or `openai` kind, own endpoint + key | Per *app*, not per *member*; can't be used by build agents. |
| myctobot (`aiagents`) | provider per agent, keys as `connections` rows, runs Claude Code with `ANTHROPIC_BASE_URL` for Ollama | Workspace-scoped, no owner checks, `openai`/`custom_http` agents silently run plain `claude`. Take the shape, not the gaps. |

## The idea

**A model connection is a member-owned credential + endpoint, and it is an engine.**

```
member ──owns──▶ modelconnection { name, protocol, base_url, key_enc, models{planner,worker,auditor,resolver}, context }
project ──uses──▶ engine = "claude" (platform) | "mc:<id>" (one of the member's model connections)
run     ──env──▶ claude CLI  +  ANTHROPIC_BASE_URL / ANTHROPIC_AUTH_TOKEN / ANTHROPIC_API_KEY="" / --model
```

One runner. Every endpoint that speaks the **Anthropic Messages API** works with the Claude Code
CLI the builders already run, headless and jailed, with tool use and streaming:

| Preset | base_url | Auth |
|---|---|---|
| Anthropic (your key) | `https://api.anthropic.com` | `ANTHROPIC_API_KEY` |
| Ollama Cloud | `https://ollama.com` | Bearer (your ollama.com key) |
| Ollama on your machine | `http://<host>:11434` | token ignored (`ollama`) |
| OpenRouter | `https://openrouter.ai/api` | Bearer `sk-or-…` |
| z.ai (GLM) | `https://api.z.ai/api/anthropic` | Bearer |
| Custom (LM Studio, vLLM, llama.cpp, LiteLLM …) | anything | Bearer or none |

**OpenAI-compatible-only endpoints** are accepted too, marked `protocol = openai`. They work
for **pipeline agent steps** today (`Pipeline\OpenAiChat`). For **build agents** they need a
headless OpenAI-protocol coding CLI (qwen-code) wired into `jail-run.sh` — phase 3, and until
then the builder picker shows them as "chat only", never silently runs something else.

## Rules (No Fallbacks applies to all of it)

- A member's connection is used only for runs **that member** triggers (same stance as
  `AgentState`: a shared project runs as whoever triggered it). Never the operator's key.
- Key encrypted with core `app_key` (as `MemberEnginePrefs`); stored-but-undecryptable is a
  fault that fails the run naming the connection, never "no key".
- Endpoint validated like `RestConnector` (http/https, SSRF guard for non-owners of the box;
  `localhost`/LAN allowed only when the member marks it "runs on this server's network").
- **Test** button: `GET /v1/models` (both protocols list there) + a 1-token Messages call for
  anthropic protocol; shows the models the endpoint offers so tiers are picked from a list.
- Picking a model the endpoint does not list is allowed but warned; a run whose model the
  endpoint rejects fails with the endpoint's own message.

## Build order

1. **Data + UI** — `modelconnection` bean (seed), `Model_Modelconnection` (validate, encrypt,
   `test()`, `listModels()`), a "Models" card on the member's Connections page
   (add/edit/test/delete, presets above), tier models chosen from the probed list.
2. **Run path** — `EngineRegistry` learns `mc:<id>` engines (resolved per member via
   `AgentContext`); `PlanRunner`/`PlanExecutor`/`AuditRunner` pass the connection's base URL,
   token and tier models to `jail-run.sh` through the run script's env (not argv — keys never
   in `ps`); capricorn `jail-run.sh` honours per-run `ANTHROPIC_BASE_URL`/`AUTH_TOKEN` over the
   ini. Project engine picker lists platform engines + the member's connections.
3. **qwen** — `[engine.qwen]` retires as an operator engine and becomes the **Ollama Cloud
   preset** (default model a live Qwen: `qwen3.5:397b`); the member supplies the ollama.com
   key. Optional later: qwen-code for `protocol = openai` build agents.
4. **Pipelines** — an app's `agent` step may name the owner's model connection (resolved on
   core through the project's broker key) as a third kind beside `cli`/`openai`.
5. Remove `MemberEnginePrefs::setToken` (superseded; migrate any stored tokens into
   connections), and the dead `$STATE_DIR/auth-token` read.

## Decisions (owner, 2026-09-23)

1. **Member-owned on core** — add a key once, it works in every project you can reach.
2. **Whoever triggers pays** — each person's run uses their own connection; a project owner may
   opt a project into "always use my connection X" (later, opt-in).
3. **Localhost/LAN endpoints: ROOT only** — members use public endpoints.

## Status (2026-09-23)

**Built — phases 1, 2 and 3:** `modelconnection` (seed 13), `Model_Modelconnection` (presets, validation with
ROOT-only local endpoints, key encrypted with core `app_key`, `test()` = list models + 1-token
Messages call, `materialize()` → `endpoint.env` + `auth-token` 0600 in the member's state dir),
`EngineRegistry::def()` resolves `mc-<id>`, `AgentContext` substitutes the member's chosen
connection for the platform's Claude and refuses another member's connection, runners' direct
paths source the files (`directEnvShell`), capricorn `jail-run.sh` reads them for `mc-*` and never
passes the operator's key. UI: Settings → Models (add / edit / Test / delete, "Build with").
`[engine.qwen]` retired (`available = false`) in favour of the Ollama Cloud preset.

**Proven live:** ROOT connection to this box's Ollama → Test listed 3 models and a Messages call
answered; choice resolved a planner run to `mc-1` with the right model and state dir; another
member was refused; inside `jail-run.sh` the env was exactly the member's endpoint, `none` token,
empty `ANTHROPIC_API_KEY`, tier models, and the endpoint was reachable. A full Claude Code session
on the tiny CPU model was not watched to completion (16 tokens took 92 s on this CPU).

**Phase 4 built (2026-09-23, core a93a2de + e4ea2a9):** pipeline agent kind `member` names one of the
project OWNER's connections (`agent.connection_ref`, a core row id). The key never leaves core:
the step POSTs `/brokerinfo/modelcall` over the app's broker key, core answers `{job}` at once
and finishes the call after the response (flushed by hand — Flight buffers its body), the step
polls `/brokerinfo/modelresult`. Core resolves the payer from `instance.member_id` and requires
the owner's per-connection opt-in (`allow_pipelines`). Every call is a `modelcall` row. UI:
Connections → Models moved from Settings (owner's choice) + the opt-in checkbox; Data → Agents
has "owner's model". Proven live from serenity: call answered in 3.5 s with pre-prompt + step
system delivered; opt-out → 403 naming the checkbox.

**Phase 5 done (2026-09-23):** the per-engine key in Settings is gone (`MemberEnginePrefs` token
functions, the Settings field, `AgentState::signedIn`'s member-token checks); seed 15 migrated the
one stored key (member 1's z.ai) into a model connection, not chosen for builds. CORRECTION to
the table at the top: that key DID reach a run — the workbench sidecar's Builder terminal wrote it
into the state dir for z.ai. The terminal now resolves through `AgentContext` (so it honours a
member's chosen connection), the bridge accepts `mc-<id>`, and the z.ai prompt is a non-blocking
note pointing at Connections → Models.

**Not yet:** (was phase 5: remove
`MemberEnginePrefs::setToken` + migrate), OpenAI-protocol build agents (qwen-code), per-project
"always use my connection X" opt-in.
