## Storing Secrets on Disk (secure/) — never chmod on an isolated instance

Encrypted credentials live in `secure/` (gitignored). On an **isolated instance** the
per-instance php-fpm pool runs as `tiknix-i<id>` and is NOT the file owner (owner is
`ubuntu`); it reaches `secure/` only through a POSIX ACL (`user:tiknix-i<id>:rwx` + an
inherited default ACL) that provisioning sets up.

**`chmod` recalculates the ACL mask from the mode's group bits.** So `chmod($dir, 0700)`
or `chmod($file, 0600)` forces `mask::---`, which drops the pool's grant to
`#effective:---` — the very next write fails with "attempt to write a readonly database"
or "Could not write … key", and an existing key becomes unreadable. **umask cannot undo
this** (it only removes bits, never lifts the mask); the repair is `setfacl -m m::rwx`.

When you generate code that persists a secret to `secure/` (or any ACL-managed instance
dir), detect isolation and leave permissions to the ACL — the inherited `default:other::---`
already keeps `other` out, so there is nothing to tighten:

```php
$isolated = is_file(dirname(__DIR__) . '/.fpm-isolated');   // marker at the instance root
if (!is_dir($dir)) @mkdir($dir, $isolated ? 0770 : 0700, true);
@file_put_contents($file, $encrypted);
if (!$isolated) @chmod($file, 0600);   // only a non-isolated install needs the mode tightened
```

`lib/ConnectionStore.php` and the generated `services/*Credential.php` follow this rule;
copy it, don't re-introduce a bare `chmod 0600`/`mkdir 0700` on `secure/`.

**The pool's ACL is narrow (C4, `scripts/instance-acl.php`).** The pool user reads the tree
and writes only `data database secure log logs cache backups storage public/uploads
.aibuilder conf pipelines mcptools scripts/hooks .claude` and the root files `concepts.lock
CLAUDE.md .mcp.json`. Code the app generates for itself that must be writable at runtime
belongs in one of those (a pipeline JSON, an MCP tool, a hook) — never write into `lib/`,
`controls/`, `views/`… from a request; it is refused, and that refusal is the point.

**Never WRITE an isolated instance's own data as the tree owner.** A CLI run as `ubuntu`
(clitool, a seed, a scratch script) that creates `data/connections.db` leaves it with mask
`r--` — SQLite opens at 0644 — and the pool gets "attempt to write a readonly database" on
its own store (Serenity, 2026-09-28; repair `setfacl -m m::rwx`, never chmod).
`ConnectionStore::put()/setAlias()` and store creation refuse this
(`IsolatedPool::ownerOnIsolated`). Code files (`concepts/`, `connectors/*.json`,
`concepts.lock`) are the owner's, like every merge; DATA the pool must write is the pool's:
`clitool --build` and `--concept-seeds` already run every seed AS THE POOL on an isolated
instance (`IsolatedPool::runAsPoolBooted`), so a seed never needs to think about it; a
one-off script that writes instance data goes through `IsolatedPool::runAsPool($root, $php)`
(cgi-fcgi to the socket named in `.fpm-isolated`).
