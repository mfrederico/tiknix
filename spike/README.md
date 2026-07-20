# Phase 0 spike — prove ACP through tiknix's jail + MCP

Goal: de-risk the ACP approach **before** touching real surfaces. It answers the open
questions from the feasibility report: does an ACP agent (kimi first) drive tiknix's MCP
tools over ACP, does it work **through the bwrap jail** (the "jail-hairpin"), does the
Claude adapter still honor hooks, and which engines support `loadSession`.

`acp-spike.js` is a **zero-dependency Node ACP client** (just `node`). It runs
`initialize → session/new (passing tiknix's MCP server) → session/prompt`, serves the
agent's callbacks (`session/request_permission`, `fs/read|write_text_file`), streams
`session/update`, and prints a pass/fail checklist. The prompt tells the agent to call a
**tiknix** MCP tool — seeing that `tool_call` is the passthrough proof.

## 0. Sanity-check the harness (no engine needed)
```
node spike/acp-spike.js  # default ACP_AGENT="kimi acp" — will fail to spawn if kimi absent
ACP_AGENT="node spike/mock-agent.js" node spike/acp-spike.js   # ← self-test, all-green
```
The mock run should light up every checklist row except `fs/write` (the mock never writes).

## 1. Real engine, stdio MCP (the core proof)
Point `ACP_CWD` at a real instance workspace so `mcptools/mcp-fastmcp.php` resolves.
```
ACP_AGENT="kimi acp" ACP_CWD="/path/to/an/instance" node spike/acp-spike.js
```
Pass = `initialize` ✅, `session/new` ✅, **tiknix MCP tool** ✅. (kimi must be logged in /
BYO-billing configured, or the turn stops at the model call — the plumbing rows still prove out.)

## 2. Jailed — the jail-hairpin (stdio MCP)
Wrap the agent command in your jail so both the agent AND its stdio MCP child run inside bwrap:
```
ACP_AGENT="/home/ubuntu/capricorn/bin/jail-run.sh <args> kimi acp" ACP_CWD="…" node spike/acp-spike.js
```
Confirms the agent can spawn+reach the stdio MCP server from inside the jail.

## 3. HTTP MCP — hairpin to the control plane (broker/gateway path)
Tests the agent reaching tiknix's HTTP MCP endpoint (needs an engine advertising
`mcpCapabilities.http` — the spike warns if it isn't). Run the agent jailed to test the real hairpin.
```
ACP_MCP_HTTP_URL="https://tiknix.com/mcp/message" ACP_MCP_HTTP_TOKEN="tk_…" ACP_AGENT="…jailed…" node spike/acp-spike.js
```

## 4. Claude adapter — does it still honor hooks?
```
ACP_AGENT="npx -y @agentclientprotocol/claude-agent-acp" ACP_CWD="…with .claude/settings.json…" node spike/acp-spike.js
```
Watch whether your PreToolUse hooks still fire (the adapter runs the Agent SDK). Informs whether
hooks stay a Claude-only bonus.

## What to record per engine
| engine | initialize | session/new | tiknix tool (stdio) | tiknix tool (http, jailed) | loadSession | notes |
|---|---|---|---|---|---|---|
| kimi (`kimi acp`) | | | | | | native |
| claude adapter | | | | | | hooks? |
| gemini / qwen / goose | | | | | | native |

## Env vars
| var | default | meaning |
|---|---|---|
| `ACP_AGENT` | `kimi acp` | shell command to launch the ACP agent (wrap in jail here) |
| `ACP_CWD` | cwd | `cwd` passed to `session/new` (an instance workspace) |
| `ACP_PROMPT` | reuse_digest prompt | the turn's prompt (asks the agent to call a tiknix tool) |
| `ACP_MCP_CMD` / `ACP_MCP_ARGS` | `php` / `mcptools/mcp-fastmcp.php` | stdio MCP server passed to the agent |
| `ACP_MCP_HTTP_URL` / `ACP_MCP_HTTP_TOKEN` | — | use an HTTP MCP server instead (needs mcp.http) |
| `ACP_PROTO` | `1` | protocolVersion (try `'"1"'` if the number is rejected) |
| `ACP_FRAMING` | `ndjson` | `ndjson` or `headers` (flip if the handshake hangs) |
| `ACP_TIMEOUT` | `120000` | ms before the spike gives up |

## Assumptions to confirm on first real run
Grounded in agentclientprotocol.com/protocol/v1, but verify against the actual engine —
see the header comment block in `acp-spike.js`: **framing** (ndjson vs Content-Length),
**protocolVersion** (number `1` vs string `"1"`), **permission reply** (flat vs nested
`outcome`), and the **session/update discriminator** (`sessionUpdate` vs `type` — the spike
accepts either). Each is a 1-line tweak.
