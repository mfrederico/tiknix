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


### Plugins (concepts) and `concepts.lock`

`concepts.lock` at the install root is THE record of which plugins (`concepts/<name>/`) this
install has and which are switched on — a file in the repository, so a clone, a task
worktree, a test run and the live site all see the same set without a database.

```bash
php scripts/clitool.php --concepts                  # installed, enabled/disabled, EDITED when files differ from the lock
php scripts/clitool.php --concept-install=NAME      # from the catalog (a build task, never a web action)
php scripts/clitool.php --concept-enable=NAME       # verify, run its seeds, switch on, regenerate CLAUDE.md
php scripts/clitool.php --concept-lock              # (re)write the lock from disk; the one-time migration and the repair
```

**Never edit files under `concepts/<name>/`.** A plugin is adapted through what it exposes
(slots, settings, its classes called from your code), so an update can replace the directory
cleanly; the lock's hash shows an in-place edit as `EDITED`, and updating such a plugin
becomes a merge task. Need behaviour it lacks? Extend it in your own code, or change the
plugin at its source and publish a new version.
