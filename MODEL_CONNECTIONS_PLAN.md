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

## Open decisions (owner)

1. Member-owned on core (recommended — one key, every project you can reach) vs per-project.
2. Should a project **owner** be able to pin "this project always builds on my connection X",
   so teammates' runs spend the owner's key? (Recommended **no** by default — opt-in per project.)
3. Localhost/LAN endpoints: allow for everyone, or only ROOT (the box's owner)? Recommended:
   ROOT only — a member pointing a builder at `127.0.0.1:…` reaches this server's internals.
