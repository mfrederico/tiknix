# Connections live with the instance

**Built and in use** since 2026-08-04. Core owns core's connectors; every
instance owns its own. Nothing is stored centrally on anyone's behalf.

Core is not special in this — it is the primary instance, on tiknix.com, and it
asks for its own connectors exactly the way every other install does.

## Where things are

```
<install root>/data/connections.db     the connections, encrypted
<install root>/secure/connections.key  the key. 0600, in a 0700 directory
```

So `/var/www/html/default/tiknix/…` for core and
`/var/www/html/default/<slug>.tiknix/…` for an instance. Both are gitignored —
`data/` already was, and `secure/` is a **directory** rule on purpose: the
earlier `conf/*.key` glob ignored a `.key` and would have let a `.pem`, `.json`
or `.txt` straight through.

The key is minted on first write and never leaves its install. Core cannot read
an instance's connections without reading that instance's key file, which is
what makes ejection real: a self-hosted copy works identically with no control
plane to ask.

## The API

```php
use app\ConnectionStore as CS;

CS::for('monday');                    // this install's, or null
CS::for('stripe', 'production');      // pinned to an environment
CS::token($conn);                     // the usable credential
CS::put('monday', 'production', $payload);   // store one here

CS::forInstall(82, 'monday');         // another install's, by instance id

use app\ConnectorPush;
ConnectorPush::push(82, 'monday', 'production', $payload);   // STORE into another install
```

Writing into another install is a **push over HTTP**, not a file write:
`putForInstall()` is retired (Phase 3). Core has no business opening someone
else's `data/`, and it cannot when they are not on the same disk.

`for()` takes no instance id and no member id, because the **file is the scope**.
`forInstall()` exists for code that runs on core but acts *for* an instance — MCP
tool calls, publish drivers, the Connections hub. It resolves that instance's
directory, reads there, and decrypts with that instance's key while it is still
in scope, carrying the plaintext on the bean as `plainToken`; decrypting later
would need the caller to know whose key to use, which is the knowledge this class
holds so callers do not have to.

`env` defaults to "any", and when unspecified **production wins** over staging.
That is not cosmetic: an install with both, ordered only by id, silently gets
staging because staging was created second.

### Two functions refuse rather than answer

`forInstance()` and `upsert()` both throw. They read and wrote core's shared
table, and while either exists somebody can call it and get a connector that did
not travel with its instance. Throwing beats returning null — null surfaces as
"not connected" and sends people hunting for a setting that is not missing.

There are **no fallbacks anywhere**. No instance named means no connection, full
stop. A fallback is a way for a connector not to travel.

## How connecting works

Both flows route by the OAuth `state`, which has always carried `instance_id`:

- **Hub** (`/connections`) → `Connections::upsertConnection` → `ConnectorPush::push()`
- **Instance-driven** (`/brokerinfo/connectkey`) → `ConnectorPush::push()`, using
  the broker key's own instance id
- **Direct** (`/connectorapi/connect`) → `put()`, on the install being called

The first two land on the instance's `POST /connectorapi/receive`, so core never
writes and never keeps anything.

`member_id` is deliberately not recorded. A connection belongs to the instance,
whoever attached it; an owner field would reintroduce "whose connection is this",
which is the question this design removes.

## Reaching an instance that is not on this disk

`controls/Connectorapi` is the inverse of `Brokerinfo`, and the inversion is the
point. Brokerinfo exists because credentials used to live in core, so an instance
called **in** to ask what it was wired to. Now core and sidecars call the
**instance**:

```
GET|POST /connectorapi/list         metadata only — connector, account, status
POST     /connectorapi/connect      {connector, key} validated HERE, then stored
POST     /connectorapi/receive      {connector, environment, payload} — already validated
POST     /connectorapi/disconnect   {id}
```

Auth is that install's own broker key (`conf/broker.ini`), `hash_equals`
compared. **No key configured means closed, not open.**

It will not hand back a token. A caller that wants to *use* a connection asks
this install to use it, or shares its disk. A route returning credentials over
HTTP would undo the reason they moved here.

## Gotchas worth knowing

**Reading never creates a file.** RedBean opens SQLite and SQLite creates on
open, so an early version left an empty database behind on every instance a
lookup touched. `withOwnDb()` takes a `$create` flag; only writers pass it.

**One RedBean connection name per install path.** The name is derived from the
path, because reusing a fixed one across installs silently keeps the first
database open — and that failure is one project reading another's credentials.

**A sidecar must name the install it acts for** (`useInstall($dir)`), or it reads
its own connections, finds none, and that is indistinguishable from "the customer
never connected anything".

## Migration

There was none. Connectors are re-applied by hand, which is why this was safe to
do at all: no decrypt-with-the-old-key, re-encrypt, verify, delete — and no
window where a credential existed in neither place or in both.

Core's ten legacy rows were **deleted on 2026-08-04**, listing kept at
`data/tombstones/core-connections-dropped-2026-08-04.tsv` (metadata only — no
tokens). Everything in it gets reconnected rather than moved.

### What the first pass missed

This document previously claimed "every reader is on the new store". That was
wrong, and the way it was wrong is worth keeping:

- `Connections::githubconnect` **wrote core's table** for another day, encrypted
  with core's key, while `githubConn()` read the instance's store. So connecting
  GitHub returned "GitHub connected" and left the card empty. It caught a real
  customer (member 24, 2026-08-04 03:29).
- `SshTargetDriver::keyConnection` read the instance's store and wrote core's, so
  it never found the keypair it had just minted and generated a **new one on every
  publish** — silently invalidating the `authorized_keys` line the customer had
  just pasted in.
- `Brokerinfo::connections` answered "you have no connections" to instances that
  had several, which is the worst available answer: identical to the truth.

The common cause is one rule that is easy to miss and now stated explicitly:

> **A bean from `forInstall()` is READ-ONLY.** RedBean stores to the database
> selected at `store()` time, not the one the bean was loaded from. Keeping one
> past the call and saving it writes to whatever file is open — core's.

Anything that mutates goes inside `ConnectionStore::withInstall($id, fn)`, which
holds the right database selected for the whole unit of work.

### Why it survived a whole day: nothing errored

The GitHub writer kept writing core's table for a day after the move, and no part
of the system objected. Walk it through:

1. `githubConn()` read the instance's store correctly and found nothing.
2. The caller read that `null` as **"not connected yet"** and dispensed a new bean.
3. The bean was stored against whatever database was open — core's.
4. The route answered `GitHub connected`.

Every step behaved reasonably. The bug lives entirely in step 2: `null` was made to
mean two different things — *"this install has no such connection"* and *"I could
not tell you"* — and the code picked the cheerful reading. A store that had thrown
"the instance's connections file has never been written" would have failed the
request in front of the person doing it, on the first attempt, instead of quietly
issuing a credential to the wrong file.

> **This is the case against fallbacks, in one page.** A fallback converts a
> question the system cannot answer into an answer it cannot support. It does not
> reduce the number of failures; it moves them somewhere nobody is looking and
> strips the timestamp off them.

The same shape produced the other three: a sealed key decrypted with the wrong key
returned `''` instead of throwing, an absent `instance_id` compared as `0`, an
absent table returned `[]`. Four bugs, one habit.

## The direction is backwards (decided 2026-08-04)

Storage moved to the instance; the **control surface did not**. Core still fronts
the hub, the OAuth callback, the webhooks and the MCP server, and then reaches
across the boundary into instance files. Everything awkward here is a symptom of
that one thing:

- `forInstall()` / `putForInstall()` / `withInstall()` exist only so core can
  operate on someone else's file.
- The repo→instance lookup exists only because a webhook lands on core, which then
  has to work out whose it was.
- The read-one-write-another bugs are all core running code that belongs to an
  instance.

**The instance is the front door.** `<slug>.tiknix.com` is served by wildcard DNS
+ nginx for any valid `/var/www/html/default/<slug>.tiknix/`, and every instance is
a full clone — so the same code, running there, is already correct. `app_url()`
reads that install's `app.baseurl`; `mcptools/Introspector.php` roots itself at
`dirname(__DIR__)`; `BaseTool` already resolves `{instanceRoot}/data/workbench.db`.
None of it needs a change to be right. It needs to run in the right place.

### Phases

| | What | Depends on |
|---|---|---|
| 1 | Webhooks land on `<slug>.tiknix.com`. The domain IS the tenant scope, so the repo→instance lookup is deleted, not repointed. HMAC verifies against that instance's own secret. | nothing |
| 1.5 | `.mcp.json` — **two forms, and the live one is already right.** See below. | nothing |

### 1.5: the two `.mcp.json` forms

**What is live: stdio, relative path.** Tracked and cloned, so every instance runs
its own `mcptools/mcp-fastmcp.php` in its own tree. That is why `Introspector`'s
`dirname(__DIR__)` and `BaseTool`'s `{instanceRoot}/data/workbench.db` resolve to
the instance — the process IS the instance. The file's own comment explains that an
absolute path would be a regression, since inside the jail core's tree is not bound
and would not resolve at all. **Do not "fix" this into an absolute path.**

**What exists but is not wired: HTTP + `tk_` bearer key.**
`Mcp::ensureWorkspaceMcpConfig()` and `Mcp::generateServerConfig()` write the
`{type: http, url, Authorization: Bearer tk_…}` shape that myctobot uses at
`https://demo.myctobot.ai/mcp/pipelines` — the tenant's own domain. Both are
**called from nowhere** in core or the sidecars, which is why no tiknix instance
has ever had one written.

The two are complementary, not competing: stdio serves an agent already inside the
jail, HTTP serves an external client that cannot exec into the instance.

**If HTTP is ever wired up, the one thing that must not be defaulted is the URL.**
`buildMcpUrl(null)` falls back to `app_url()` — whichever install is running the
generator. Called on core, every project gets `https://tiknix.com/mcp/message` and
therefore core's tree. `ensureWorkspaceMcpConfig()` already accepts a `$baseUrl`;
pass the instance's, and treat a missing one as an error rather than a fallback.
| 2 | Secret-key connectors (monday, stripe, rsync/ssh) attach on the instance's own hub via `for()`/`put()`. No instance id in the flow at all. | 1 |
| 3 | **DONE 2026-08-04.** OAuth: core keeps the registered callback as a **thin landing pad** — reads `state`, resolves the instance, pushes the token in over `POST /connectorapi/receive` with that instance's broker key, stores nothing. Same door on-disk or self-hosted. | 2 |

### Decided 2026-08-04: how an instance gets its own identity

Provisioning mints the root member **M2M, before any human visits**, and attaches
the `tk_` MCP key to it. The agent starts building immediately; a key that only
exists after someone loads a page does not exist when the agent needs it.

The provision-to-claim window is closed with `status`, not with a password:

- Provision writes the root member as **`status = 'system'`**. `canHoldSession()`
  already admits `'system'` and `canAuthenticate()` already requires `'active'`, so
  the key works machine-to-machine from the first second while **interactive login
  is refused**. No new mechanism.
- First-run **claims** that row rather than creating a second one: flip to
  `'active'` and write the human's email/username/password onto it. The `apikey`
  row is separate and survives the claim.

**First visitor wins, and that is safe here** — `<slug>` carries a random hash, so
the URL is a capability. Verified: tiknix serves a **wildcard** `*.tiknix.com`
certificate, so per-instance hostnames are never published to Certificate
Transparency logs. **If anyone ever moves to per-subdomain certs, this breaks** —
every slug would appear in CT within minutes of issue and the claim URL would stop
being secret. Re-gate the claim before making that change.

One thing to confirm when building it: the API-key auth path must admit
`'system'`. If it was tightened to `status = 'active'` during the auth-hardening
pass, the placeholder's key is rejected and the scheme fails closed and silently.

## Phase 3: core landed the callback and let go of it (2026-08-04)

Core still owns the OAuth callback, and always will: the app registration — client
id, client secret, the redirect URI the provider will accept — is core's, so an
instance cannot begin the handshake alone. What changed is what core does after the
exchange. It **pushes** and keeps nothing.

```
provider → GET /connections/callback/<type> on core   (state carries instance_id)
         → connector->exchangeCode()
         → ConnectorPush::push(instanceId, type, env, $payload)
         → POST https://<instance>/connectorapi/receive   Bearer <instance broker key>
         → ConnectionStore::put() — on the instance, with the INSTANCE's key
```

`lib/ConnectorPush.php` resolves **where and with which key together**, because they
are one answer: `Connectorapi` compares against the broker key in the conf/ of the
install that is *serving*, so a URL from one copy and a key from another is a 403
with nothing obviously wrong.

- A **deployed container** is its own install on its own rootfs. Core cannot read
  its `conf/`, so the URL is `ct_domain` and the key is the encrypted copy on the
  instance row — the same value the deploy hands the entrypoint.
- An install **in a directory beside core** answers on its own `[app] baseurl` and
  its own `conf/broker.ini`. The baseurl is read, never derived from the slug:
  deriving is a guess that stays right until somebody points a real domain at it.
- Neither → **throw**. Not 0, not null.

### `POST /connectorapi/receive` — the one new attack surface

Every other route in this design either proves a credential against its provider
or hands out no secret at all. This one takes a token **on trust**, so it is a
separate route rather than a loosened `connect()`, and it is gated on more than
reachability:

| Gate | Why |
|---|---|
| this install's broker key, `hash_equals` | "you may act as this install" — no key configured means closed |
| POST only | a credential must not be deliverable by following a link |
| `auth_type` ∈ {oauth, api_key, token} | **not** `ssh_sealed`: those are sealed by `SshKey`, `ownToken()` refuses to open them, so a pushed one would read as connected and never produce a token |
| non-empty `access_token`, connector key shape | an empty credential stored is an outage discovered later |
| every acceptance logged with connector, account, caller | it is the only route that can create a credential it did not verify |

Registry membership is deliberately **not** a gate: `github` is not a registry
connector — core drives that handshake itself — so requiring one would reject the
commonest push with a message that reads like a config problem rather than a rule.

The payload is passed through **whole**, not field-by-field. `metadata` is where a
connector puts its expiry (Instagram's `expires_at`) and its detail (GitHub's
owner/repo/defaultBranch); cherry-picking would silently drop whatever a connector
adds next.

**What retired:** `putForInstall()`. It opened another install's `data/` from core,
which is impossible off this disk, and its two guards returned `0` — a value the
caller could not tell from "stored, id unknown". It now throws and points at
`ConnectorPush::push()`. No callers remain (`Connections::add`,
`Connections::upsertConnection` and `Brokerinfo::connectkey` were the three).

**What did NOT retire, and why:** `withInstall()`. The phase table expected it to go
with the write path, but it has twelve callers that are not writes-from-the-callback:
the hub's list/test/disconnect/publish-feed, `Brokerinfo::connections|disconnect`,
`Integrations`, the SSH publish driver's keypair mint and outcome write, and two
scripts. Retiring it means moving all of those onto HTTP too, which is a later phase
and a behaviour change for the publish drivers — where core legitimately acts for an
instance because core does the deploying. Removing it now would have meant
reimplementing it under another name. `forInstall()` stays for the same reason.

The **non-secret mirror** is still never built, and is still not needed.

**On deploy**, a tenant changes three values: webhook URL, OAuth callback,
`.mcp.json` baseurl. Under the old shape self-hosting was not a re-setup, it was
impossible — the connectors, hooks and MCP all pointed at infrastructure the
tenant did not own.

## Nothing is on core's table (Phases 1 and 2 done)

Every reader and writer now names a store. The claim that this was true once
before was wrong, so here is how to check rather than trust it:

```bash
grep -n "Bean::\(find\|findOne\|findAll\|load\|dispense\|count\|trash\)([^)]*'connections'" \
  controls/*.php lib/*.php lib/**/*.php scripts/*.php | grep -v ConnectionStore.php
```

Every hit must sit inside a `withOwnDb()` / `withInstall()` closure. A hit at the
top level of a method is the bug this document exists about.

The **repo → instance mirror is not built and is not needed**. Phase 1 made the
delivery land on `<slug>.tiknix.com`, so the domain answers the routing question
before the request arrives and the global lookup has nothing left to do.
