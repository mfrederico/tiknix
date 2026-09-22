## Code Validation Hook

A validation hook at `scripts/hooks/validate-tiknix-php.php` (wired up as a `Write|Edit`
PreToolUse hook in `.claude/settings.json`) enforces these standards:
- **Blocks** on raw `R::<method>` where `Bean::` wraps it — see the table above. Methods
  `Bean::` does NOT wrap (`setup`, `close`, `testConnection`, `getWriter`, `nuke`) pass,
  because blocking a call with no alternative just teaches people to route around the hook.
  Allowlisted files: `bootstrap.php`, `services/Schema/Seeds/*.php`, `lib/Bean.php`.
- **Blocks** on invalid `R::dispense` bean names (underscores, uppercase)
- **Warns** on `exec` for CRUD — `Bean::exec` too, not just `R::exec`: the wrapper bypasses
  FUSE models exactly the same way, and checking only `R::` meant converting a file to the
  mandated `Bean::` silenced the warning about the thing that was actually wrong
- **Warns** on manual FK assignments (should use associations)

The `R::`/`exec` checks read **code only** — comments and string literals are stripped with
PHP's tokenizer first, so a docblock that names `R::store` or an MCP tool description that
quotes `"R::exec usage"` is not treated as a database call.

A duplicate/anti-pattern scanner also lives at `scripts/hooks/check-duplicates.php`
(patterns in `scripts/hooks/duplicate-patterns.json`):

```bash
php scripts/hooks/check-duplicates.php --quick      # controls/ + services/
php scripts/hooks/check-duplicates.php --verbose    # + lib/ and models/
```

Install `scripts/hooks/pre-commit-duplicates` as `.git/hooks/pre-commit` to block
commits on critical violations.
