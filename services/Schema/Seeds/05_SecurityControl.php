<?php
/**
 * 05_SecurityControl.php — the path rules every install enforces.
 *
 * WHY THIS FILE EXISTS
 *
 * There were two path guards. `scripts/hooks/security-sandbox.php` reads these rows: it
 * is level-aware, editable from the admin UI, and registered in the one tracked
 * .claude/settings.json, so it runs on core and on every clone. `.claude/guard.php` was
 * the other — a hardcoded PCRE list with no level awareness that existed only in the
 * instances somebody had copied it into. Two guards, overlapping on `.claude` and
 * `conf/`, disagreeing everywhere else, and no way to change either one for everybody.
 *
 * The rules table was never seeded, so `database/security.db` is untracked and built per
 * install. That is the actual reason guard.php was carried around by hand: there was no
 * mechanism to ship a rule. This is that mechanism, and with it guard.php has nothing
 * left that this cannot say — plus a level, which guard.php could never express.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE. Matches on pattern, and only fills in what is missing.
 * A rule an operator has edited or switched off stays edited or off: re-running the build
 * must never quietly re-open something somebody deliberately closed.
 */

use RedBeanPHP\R;

// The security rules live in their own database, beside the app's — same file
// controls/Security.php and the sandbox hook read.
$securityDb = __DIR__ . '/../../../database/security.db';

if (!R::hasDatabase('security')) {
    R::addDatabase('security', 'sqlite:' . $securityDb);
}
R::selectDatabase('security');

R::exec('CREATE TABLE IF NOT EXISTS securitycontrol (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT, target TEXT, pattern TEXT, action TEXT,
    level INTEGER, description TEXT, priority INTEGER,
    scope TEXT, is_active INTEGER DEFAULT 1
)');

/**
 * Everything guard.php protected that the rules table did not already cover.
 *
 * `.claude`, `/conf/`, `/lib/`, `scripts/hooks` and `CLAUDE.md` are already rows at
 * level 50 — those were the overlap. What follows is the remainder, at the same level,
 * so an ADMIN can still work on them and a build agent cannot.
 */
$rules = [
    [
        'name'        => 'Protect vendor tree',
        'pattern'     => '/vendor/',
        'description' => 'Composer dependencies — changed by composer, never by hand',
    ],
    [
        'name'        => 'Protect git hooks',
        'pattern'     => '/.git/hooks',
        'description' => 'Git hooks run on every commit — a write here is persistence',
    ],
    [
        'name'        => 'Protect billing models',
        'pattern'     => '#(^|/)models/Model_(Billing|Payment|Subscription|Invoice)#i',
        'description' => 'Billing data models',
    ],
    [
        'name'        => 'Protect billing services',
        'pattern'     => '#(^|/)services/[^/]*[Bb]illing#',
        'description' => 'Billing services',
    ],
    [
        'name'        => 'Protect example configs',
        'pattern'     => '#(^|/)conf/[^/]*\.example\.ini$#',
        'description' => 'Template configs shipped with the app',
    ],
];

$added = 0; $kept = 0;
foreach ($rules as $r) {
    $existing = R::findOne('securitycontrol', 'pattern = ?', [$r['pattern']]);
    if ($existing) { $kept++; continue; }     // somebody's row wins, edited or disabled

    $row              = R::dispense('securitycontrol');
    $row->name        = $r['name'];
    $row->target      = 'path';
    $row->pattern     = $r['pattern'];
    $row->action      = 'protect';            // read allowed, write needs the level
    $row->level       = 50;                   // ADMIN
    $row->description = $r['description'];
    $row->priority    = 50;
    $row->scope       = 'always';
    $row->isActive    = 1;
    R::store($row);
    $added++;
}

R::selectDatabase('default');

echo "  05_SecurityControl.php: {$added} added, {$kept} already present\n";
