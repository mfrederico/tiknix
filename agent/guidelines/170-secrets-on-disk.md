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
