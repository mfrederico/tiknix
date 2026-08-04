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
CS::putForInstall(82, 'monday', 'production', $payload);
```

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

- **Hub** (`/connections`) → `Connections::upsertConnection` → `putForInstall()`
- **Instance-driven** (`/brokerinfo/connectkey`) → `putForInstall()`, using the
  broker key's own instance id
- **Direct** (`/connectorapi/connect`) → `put()`, on the install being called

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
POST     /connectorapi/connect      {connector, key} validated then stored here
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

## Still on core's table

These have **not** moved, and now read an empty table, so they report "not
connected" instead of failing:

| Where | What it does |
|---|---|
| `controls/Webhook.php:202` | maps a pushed repo → its instance |
| `controls/Webhook.php:403` | telegram webhook by connection id |
| `controls/Integrations.php:58` | an instance's integrations list |
| `controls/Connections.php` 565, 773, 814, 841, 883, 1168-1223 | hub display, telegram, enable/disable/revoke |

`Webhook.php:202` is the one that needs a decision rather than a repoint. It maps
**repo → instance** by scanning every GitHub connection, and per-instance stores
leave core with no such index — the lookup is global but the data no longer is.
Opening every instance's database per webhook would work and does not scale.

The answer is the **non-secret mirror**: core keeps `instance_id`, connector,
`external_eid` and status — never a token — written whenever an instance's store
is written. That also gives the hub something to render without opening ten files,
which is the other half of this same gap.
