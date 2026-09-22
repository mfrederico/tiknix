## Useful Scripts

### Permission Cache Reset
When modifying `authcontrol` table entries directly (adding/removing route permissions), the APCu cache needs to be refreshed:

```bash
php scripts/resetcache.php
```

This clears and reloads the permission cache without requiring a PHP-FPM restart. The cache uses versioning, so web requests will automatically pick up the new permissions.

**When to use:**
- After manually editing authcontrol records in the database
- When permission changes don't seem to take effect
- After deleting duplicate/conflicting authcontrol entries
### Agent guidance is generated

`CLAUDE.md` is produced by `php scripts/clitool.php --agent-sync` from `agent/guidelines/*.md`
(one file per section, ordered by prefix) plus each enabled concept's `guidelines.md`. Edit
the section file, run the sync, commit both. A hand edit to `CLAUDE.md` fails
`tests/unit/AgentGuidanceTest.php`, which the pre-commit hook runs.

