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

## There is no dependency blocker

An earlier draft of this plan said there was one. That was wrong, from a bad
`find` filter that showed a partial listing of the vendored package and was read
as "the auth interfaces are missing". Checked properly:

- tiknix has **v0.1.1** vendored, ref `9db083acab13`.
- `/var/www/html/default/fastmcphp` is at **the same commit**. There is nothing
  local that is not published; `dev-main` and `v0.1.1` are that commit.
- The vendored copy already ships everything the later phases need:

```
Server/Auth/        AuthProviderInterface, AuthorizationInterface,
                    AuthorizationContext, AuthResult, AuthRequest, AuthenticatedUser
Server/Middleware/  AuthenticationMiddleware
Server/Session/     ApcuSessionStore, SessionStoreInterface
Server/Transport/   Http, Sse, Stdio, TransportInterface
```

So no path repository, no upstreaming, no version bump. `composer require` has
already happened. `ApcuSessionStore` is a bonus: this app already runs APCu for
PermissionCache.

One thing stays true — `HttpTransport` needs `react/http`, which is not installed
and is only a `suggest`. That does not matter, because `/mcp/message` should keep
being served by PHP-FPM behind nginx. What gets adopted is `Protocol\JsonRpc`,
the auth interfaces and the session store, NOT the transport. react/http is an
event-loop daemon on its own port and would sit outside the session, authcontrol
and the cached database adapter.

## The 23-tool gap, which is worse than the refactor

Asked directly, the two servers do not agree about what exists:

```
HTTP  (controls/Mcp.php)        27 tools
stdio (mcptools/mcp-fastmcp.php) 4 tools — codebase_map, describe,
                                           submit_plan, whatprovides
```

`mcptools/` holds **21 Tool classes**. The stdio server registers four of them.

That is not a tidiness problem. `.mcp.json` points the JAILED AI BUILDER AGENT at
the stdio server, so the agent that builds instances cannot call:

- `reuse_digest` — which CLAUDE.md declares **MANDATORY** before adding any
  controller, model or service ("call reuse_digest FIRST when adding a feature")
- `check_redbean`, `check_flightphp`, `validate_php`, `full_validation` — the
  standards checks this project cares most about
- every pipeline tool, and every task tool (`get_task`, `update_task`,
  `complete_task`, `add_task_log`)

An agent instructed to call a tool it cannot see will either invent the answer or
skip the step. That is a plausible cause of real behaviour, not a hypothetical.

**So the unification is phase 1, ahead of the extraction**, and it is worth doing
on its own merits even if none of the rest happens. One registry both transports
read — the tool classes already exist and both servers already delegate to
`app\mcptools\*Tool::execute()`; only the LIST is built twice.

Worth checking as part of it: whether the four exposed over stdio are a
deliberate minimal set (the file calls itself "codebase introspection") or simply
the four that existed when it was written. The HTTP list has grown to 27 and this
one has not, which suggests the second.

## Phases

Each is independently shippable and leaves the server working.

**1. One tool registry, both transports.** Above. Cheapest, highest value, and
independent of everything else: the stdio server exposes 4 of 21 tools and the
builder agent is the one using it. Do this even if the rest is never done.

**2. Extract the client, change nothing else.** Move `tryStartServer`,
`fetchBackendTools`, `initializeMcpSession`, `getServerTools` and `proxyToolCall`
into `lib/McpClient.php` (or port myctobot's `McpClientService` wholesale and
adapt). The controller calls the service; behaviour is identical. ~570 lines
leave the controller and nothing else moves. **This phase alone is most of the
size win and carries almost no risk**, because the registry has no rows — the
code being moved is not currently executed by anyone.

**3. Auth as a provider.** Adapt the api-key and broker-key checks into
`AuthProviderInterface`, following `McpGatewayAuthProvider`. This is the phase
that touches live traffic: every `tools/call` goes through it, and the broker key
carries `instance_id`, which is what scopes a call to one project's connectors.
Get it wrong and a caller acts as the wrong instance. Wants its own review.

**4. Accounting behind an interface.** `mcplog`, `mcpusage`, `mcpsession` and
`sendErrorWithLog` (269 lines, the largest method in the file) become a logging
service the controller and the client both call. Much of that method's size is
error shaping repeated per failure mode.

**5. Reconsider the registry.** With the client extracted and the controller
readable, decide whether the backend-server feature stays. It is easier to judge
a 300-line service than 570 lines woven through a controller — and if it goes,
it goes cleanly.

## What must not break

**The transports have already drifted** — 27 tools against 4. That is phase 1
rather than a caveat; see above.

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

Phase 1 (the tool registry) is small and fixes a live functional gap. Phase 2 is
a move, and the moved code has no live callers: low risk, most of the size
reduction. Phases 2 and 3 touch every request and deserve separate sessions with
the diff read. A plausible end state is a controller in the **400–600 line** range
with the capability intact — the same result myctobot already has — rather than
the ~900 that deleting the proxy would give while losing the feature.
