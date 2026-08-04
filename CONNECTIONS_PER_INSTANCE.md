# Connections move to the instance

**Status: designed, not built.** Decided 2026-08-04. Nothing below is implemented
yet; the current code still stores every connection in core's `connections` table
scoped by `instance_id`.

## What changes

A connection becomes the instance's, not the platform's:

| | now | after |
|---|---|---|
| storage | `connections` table in core's db, `instance_id` column | `data/connections.db` inside the instance |
| encryption key | core's `[security] app_key` | the instance's own key, in its gitignored config |
| who can read a token | core, and anything with core's db + key | that instance |
| sidecar access | broker call to core (`/brokerinfo/connectiontoken`) | opens the file directly when local |

## Why

**Credentials must not reach git.** Instances force-track their main database so
checkpoint/rollback can capture it — `collectiq-302eb3` has a commit that *is*
its `.db`. And `acp.tiknix` holds 4 connections in its main database, all 4
carrying tokens. Those two facts have not collided yet, but an instance doing
both commits encrypted credentials into history, where they survive every clone
and rollback. A separate gitignored file cannot.

**Ejection becomes real.** With a per-instance key the instance can read its own
connections without asking anything. A self-hosted copy works identically to a
hosted one, which is the "option C" custody model already chosen for Telegram and
monday.

**It removes a bug class rather than guarding against it.** Two separate defects
this week came from core's table holding every customer's rows: an unscoped
lookup returning another customer's connection, and an ordering rule that
preferred staging over production. With one file per instance there is no other
customer's row to select.

## File, not table

`data/connections.db`, following `data/workbench.db` — per-instance, gitignored,
opened as a named RedBean connection. **Not** `connections-<slug>.db`: the slug is
already the directory, and putting it in the filename goes stale the moment a
slug changes.

## The two hard parts

**1. OAuth becomes two-step.** Redirect URIs are registered to core's domain, so
core receives the token and must hand it onward to the instance to be stored.
There is a failure window between "core has it" and "the instance stored it" that
did not exist before. Needs an explicit answer: retry, or a short-lived staging
row in core that the instance claims and core then deletes.

**2. Core's Connections hub can no longer read what it shows.** It currently
renders connection state from its own table. After this it must ask the instance,
or keep a non-secret mirror (connector type, external name, enabled, revoked) with
the token living only in the instance. The mirror is probably right — the hub
needs to *list*, not to *use*.

## Migration: there isn't one

Decided 2026-08-04: **people re-apply their connectors.** Nothing is carried
across.

This is the decision that makes the whole change safe, and it is worth
understanding why rather than treating it as a shortcut. Migrating would have
meant, per connection: decrypt with core's key, re-encrypt with the instance's
new key, write, verify against the live API, then delete from core — with a
window in the middle where a credential exists in neither place, or in both.
Seven of those, across four instances, holding a customer's Stripe and Shopify
and monday tokens. Re-applying is a few minutes of somebody's time and has no
such window.

What that means in practice:

- Core's existing rows are **left alone**, not deleted. They stop being read once
  the readers point at the instance file. Clearing them out is a separate,
  unhurried job once every instance has reconnected.
- Every connector stops working at the moment the readers switch, until its owner
  reconnects. That is user-visible and needs saying out loud before the switch —
  it is not a silent degradation.
- The instance's `data/connections.db` and its key are created on first connect,
  so an instance nobody reconnects simply has no connections, which is the
  honest state rather than a broken one.

Order that follows from this: build the storage and the key, switch the readers,
tell people to reconnect. No data moves at any point.

## What already fits

- `ConnectionStore` is the single read/write point (`forInstance`, `token`,
  `upsert`) — the place to change the storage target.
- `/brokerinfo/connectiontoken` stays, for genuinely remote instances.
- `MondayImport::setConnection()` / `setToken()` already let a caller supply both,
  so the sidecar needs no change to read locally instead.
