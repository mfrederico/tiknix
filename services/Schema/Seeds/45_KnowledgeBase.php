<?php
use RedBeanPHP\R;
/**
 * 45_KnowledgeBase.php — the platform knowledge base (app\KnowledgeBase): its table, who may
 * reach what, and the first entries — things already learned the hard way, each of which cost an
 * agent or a person real time before it was written down.
 *
 *   brokerinfo::kb   PUBLIC at the route: an app's agent asks with the app's own broker key,
 *                    which the method checks itself (like the rest of brokerinfo).
 *   knowledge::*     ADMIN: the page where entries are written, approved, dismissed and retired.
 *
 * The table is DECLARED: every text column TEXT and the counters INTEGER from the start, so no
 * later value widens a column (a widen rebuilds a SQLite table and empties it).
 *
 * Entries are idempotent by title, and a seed never changes one that exists — an entry a person
 * edited, retired or dismissed stays as they left it.
 */
echo '  authcontrol: brokerinfo::kb => ' . \app\PermissionCache::seedRule('brokerinfo', 'kb', 101, "A project's agent looks up the platform knowledge base (broker key)") . "\n";
echo '  authcontrol: knowledge::* => ' . \app\PermissionCache::seedRule('knowledge', '*', 50, 'Platform knowledge base: write, approve, retire (admins)') . "\n";

R::exec('CREATE TABLE IF NOT EXISTS kbentry (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT, symptom TEXT, cause TEXT, fix TEXT,
    status TEXT, source TEXT, source_note TEXT,
    hits INTEGER, last_hit_at TEXT, reviewed_at TEXT,
    created_by INTEGER, created_at TEXT, updated_at TEXT
)');
R::exec('CREATE INDEX IF NOT EXISTS idx_kbentry_status ON kbentry(status)');
echo "  kbentry: table declared\n";

$entries = [
    ['git push fails with 403: the only remote, seed, is read-only',
     'In a hosted app, `git push` answers 403; `git remote -v` shows one remote named `seed` (https://tiknix.com/git/<slug>.git) that serves only git-upload-pack. The agent wonders whether the next build task will see its commit.',
     'By design. The app\'s checkout IS the running app, and every build task starts as a git worktree of its `main` at HEAD. `seed` is only where the app was first cloned from.',
     'Do not push and do not escalate. Commit on `main`, check `git status` is clean, and say the change is in: it is live and it is what the next task builds on.'],
    ['A seed in database/seeds never runs; the table or permission it makes is missing after a merge or update',
     'A seeder was written under `database/seeds/` (or anywhere but `services/Schema/Seeds/`). It worked when run by hand in the task, but after the merge or an update the table, starter rows or authcontrol rows are not there on the live app.',
     'Only `services/Schema/Seeds/NN_Name.php` is applied (by `php scripts/clitool.php --build`, which every merge and update runs). Nothing ever runs `database/seeds`.',
     'Move the seed to `services/Schema/Seeds/` with a number from 50 up, make it idempotent, and run `php scripts/clitool.php --build`. Print what `seedRule()` returns.'],
    ['The tiknix MCP server shows as disconnected in the builder terminal after about a minute',
     'In an app\'s builder terminal the `tiknix` MCP server connects, then shows disconnected after roughly 60 seconds of not being used; reconnecting works until the next quiet minute. `log/mcp-*.log` shows the server starting and ending 60 s apart.',
     'On runtimes before v2.0.0-alpha.133 the stdio server\'s input was a socket, and PHP\'s default_socket_timeout ended it after a quiet minute.',
     'Update the app: `php scripts/clitool.php --update` (fixed in v2.0.0-alpha.133). Until then, reconnect with /mcp.'],
    ['A page works on the first request and answers 500 on the next: "table already exists"',
     'A page or seed works once and then fails with a 500 whose log line says a table "already exists" (CREATE TABLE), or a table that was just created is reported missing. It comes and goes with the cache.',
     'On runtimes before v2.0.0-alpha.101 the list of tables was cached, so code that asks "does this table exist?" got a stale answer and tried to create it again.',
     'Update the app (`php scripts/clitool.php --update`; fixed in v2.0.0-alpha.101). In your own code, declare tables in a seed with CREATE TABLE IF NOT EXISTS rather than creating them on a request.'],
    ['A new route answers 403 for members even though its seed sets it to members (level 100)',
     'A page added by a task is forbidden (403) to signed-in members, or is admin-only, although its seed asks for level 100. `authcontrol` has a row for `<controller>::<method>` at level 50.',
     'The route was requested before its permission was seeded. The first request to an unseeded route creates an ADMIN-only row for that exact method, and a hand-written seed that only inserts when missing then leaves it in place.',
     'Set the rule with `\\app\\PermissionCache::seedRule(\'<controller>\', \'*\', 100, \'why\')` in a seed and run `php scripts/clitool.php --build` — it corrects the auto-made rows (a wildcard clears the per-method ones). Then `php scripts/resetcache.php`. Seed BEFORE fetching a new route.'],
    ['A method added to a runtime controller (Admin, Member, Auth…) answers 404',
     'A task added a page as a new method on one of the platform\'s controllers — for example `/admin/customers` in a copy of `controls/Admin.php` — and the URL answers 404, or its view 500s on variables nothing passes it.',
     'Runtime controllers are loaded from `vendor/tiknix/runtime/`; a hand-made copy in the app is not loaded at all, while a copied view is.',
     'Build the page in a controller of the app\'s own: `controls/Cafe.php` → `/cafe/customers`, with `views/cafe/customers.php`, and link to it. Remove the copied files. To really change a runtime file use `php scripts/clitool.php --override=<path>`.'],
    ['`php scripts/clitool.php --update` is refused: uncommitted code edits',
     'An update stops with "uncommitted code edits: …" and names files.',
     'The updater will not move the runtime under work in progress: it checkpoints and commits, and someone\'s half-done edit would be swept into that.',
     'Commit the named files (or discard them with `git checkout -- <file>`), then run the update again. Generated guidance (AGENTS.md, CLAUDE.md, the installed skills) is committed by the updater itself and is never what blocks it.'],
    ['A build task ended with "exited 124" or "ran out of time"',
     'A plan task is marked failed with a note that it hit its time limit; the plan stops or waits.',
     'A task has a time limit: 30 minutes, or its agent\'s own "Task time limit" when that is longer. Slower providers need more for the same work.',
     'Retry the task from its page: a task that ran out of time with work committed RESUMES from that work. If it keeps happening on one agent, raise that agent\'s Task time limit on the app\'s AI agents page, or split the task.'],
];
$added = 0; $kept = 0;
$now = date('Y-m-d H:i:s');
foreach ($entries as [$title, $symptom, $cause, $fix]) {
    if (R::getCell('SELECT id FROM kbentry WHERE title = ?', [$title])) { $kept++; continue; }
    R::exec('INSERT INTO kbentry (title, symptom, cause, fix, status, source, source_note, hits, reviewed_at, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$title, $symptom, $cause, $fix, 'published', 'seed', 'first entries: incidents already resolved', 0, $now, 0, $now, $now]);
    $added++;
}
echo "  kbentry: {$added} starter entr" . ($added === 1 ? 'y' : 'ies') . " added, {$kept} already there\n";
