# Plan: make tiknix's MCP server a gateway on top of fastmcphp

**Status: proposed, not started.** Written 2026-08-05 from a live comparison with
`/var/www/html/default/myctobot`, which already runs this shape.

## The number that started it

`controls/Mcp.php` is **2,477 lines**. myctobot's equivalent is **372**, doing the
same job for the same protocol. The difference is not that myctobot does less — it
is that myctobot let a library take the protocol and moved the gateway into
services, while tiknix kept all of it in one controller.

Measured, by largest method:

| method | lines | what it is |
|---|---|---|
| `sendErrorWithLog` | 269 | error shaping + accounting |
| `proxyToolCall` | 216 | forward a call to a backend server |
| `message` | 156 | the JSON-RPC entry point |
| `tryStartServer` | 134 | spawn a backend MCP server |
| `fetchBackendTools` | 112 | ask a backend what it exposes |
| `initializeMcpSession` | 70 | handshake with a backend |
| `getServerTools` | 40 | cache a backend's tool list |

`proxyToolCall`, `tryStartServer`, `fetchBackendTools`, `initializeMcpSession` and
`getServerTools` are ~570 lines of **MCP client** — code for talking to *other*
people's MCP servers. That is a real feature, and it is the bulk of the file.

## Why not simply delete it

That was the first instinct and it was wrong. The registry has no rows anywhere:

```
mcpserver: 0 rows across core and every instance
```

But `mcpusage` has 308 rows and `mcplog` 509, so the MCP server itself is in
daily use — it is only the *backend registry* that is unused. Deleting the proxy
removes a built capability to fix a readability problem, and the readability
problem has a better answer.

## What myctobot already proved

Same protocol, same auth model, a fifth of the controller:

| | myctobot | tiknix |
|---|---|---|
| controller | `controls/Mcp.php` **372** | `controls/Mcp.php` **2,477** |
| MCP client | `services/McpClientService` 858 | inline in the controller |
| auth | `services/McpGatewayAuthProvider` **66** | ~104 inline checks |
| own tools | `services/McpGatewayToolService` 339 | `mcptools/*Tool` (already separate) |

`McpGatewayAuthProvider` is the interesting one: 66 lines, and its docblock says
it is *"the existing auth logic adapted into fastmcphp's AuthProviderInterface"*.
The library already has the extension points —
`src/Server/Auth/AuthProviderInterface.php`, `AuthorizationInterface`,
`Middleware/AuthenticationMiddleware` — so auth becomes a plug-in rather than a
hundred inline branches.

`McpClientService` is the piece worth porting: `connect()` over stdio/http/sse,
`initialize()`, `listTools()`, `callTool()`, `readResource()`, `getPrompt()`. It
is a general MCP client, not myctobot domain code, and it replaces tiknix's whole
proxy section.

Note what is NOT portable: `McpGatewayToolService` is myctobot's own jira/github
tools. tiknix's equivalent already exists as `mcptools/*Tool`, which the
controller already delegates to (9 call sites). That half is done.

## The blocker, and it is first

tiknix vendors from packagist:

```json
"fastmcphp/fastmcphp": "^0.1"
```

myctobot uses a path repository against a local checkout:

```json
"repositories": [{ "type": "path", "url": "../fastmcphp" }],
"fastmcphp/fastmcphp": "@dev"
```

`/var/www/html/default/fastmcphp` exists, on `main`, last commit
*"Make stdio usable as a lightweight dependency"*. So **"augment fastmcphp" is
available to myctobot today and not to tiknix.** Nothing else in this plan can
start until that is settled, and it is a real decision rather than a step:

- **path repo** — fastest, and both products track one checkout. But tiknix
  deploys to instances and containers that will not have `../fastmcphp` on disk.
- **upstream and tag** — slower, works everywhere, and is the honest answer if
  fastmcphp is meant to be a library rather than a shared working copy.

The second is almost certainly right for tiknix, because a per-instance clone
that cannot `composer install` is a broken instance.

## Phases

Each is independently shippable and leaves the server working.

**0. Decide the dependency.** Path repo or upstream tag (above). Nothing starts
until this is answered, because it decides whether the later phases can even be
installed on an instance.

**1. Extract the client, change nothing else.** Move `tryStartServer`,
`fetchBackendTools`, `initializeMcpSession`, `getServerTools` and `proxyToolCall`
into `lib/McpClient.php` (or port myctobot's `McpClientService` wholesale and
adapt). The controller calls the service; behaviour is identical. ~570 lines
leave the controller and nothing else moves. **This phase alone is most of the
size win and carries almost no risk**, because the registry has no rows — the
code being moved is not currently executed by anyone.

**2. Auth as a provider.** Adapt the api-key and broker-key checks into
`AuthProviderInterface`, following `McpGatewayAuthProvider`. This is the phase
that touches live traffic: every `tools/call` goes through it, and the broker key
carries `instance_id`, which is what scopes a call to one project's connectors.
Get it wrong and a caller acts as the wrong instance. Wants its own review.

**3. Accounting behind an interface.** `mcplog`, `mcpusage`, `mcpsession` and
`sendErrorWithLog` (269 lines, the largest method in the file) become a logging
service the controller and the client both call. Much of that method's size is
error shaping repeated per failure mode.

**4. Reconsider the registry.** With the client extracted and the controller
readable, decide whether the backend-server feature stays. It is easier to judge
a 300-line service than 570 lines woven through a controller — and if it goes,
it goes cleanly.

## What must not break

**Both transports must agree about what exists.** `mcptools/mcp-fastmcp.php`
(stdio) and `controls/Mcp.php` (HTTP) both expose the same tools by delegating to
`app\mcptools\*Tool::execute()`, but each builds its own `tools/list`. They can
drift today, and a refactor is exactly when that happens. Worth making one shared
list both read from **as part of phase 1**, not after.

**`/mcp/message` answers customer traffic.** 509 log rows and 308 usage rows say
so. Every phase needs the endpoint exercised before and after —
`initialize`, `tools/list`, `ping`, and an authenticated `tools/call` — not just
a lint.

**The security model is documented and load-bearing.** `mcp::message` is
deliberately PUBLIC (level 101) because the controller authenticates itself, and
`tools/list` is intentionally reachable without a key. CLAUDE.md says so in the
"DON'T PANIC / DO PANIC" section. Anyone touching auth should read that first and
keep `tools/call` refusing without a key.

**Instance scoping is not incidental.** `ConnectionStore::forInstall($instanceId,
…)` resolves a project's own credentials from the broker key's instance. That is
the whole per-instance connections model; the auth phase must carry it through
intact.

## Honest estimate

Phase 1 is a move, and the moved code has no live callers: low risk, most of the
reduction. Phases 2 and 3 touch every request and deserve separate sessions with
the diff read. A plausible end state is a controller in the **400–600 line** range
with the capability intact — the same result myctobot already has — rather than
the ~900 that deleting the proxy would give while losing the feature.
