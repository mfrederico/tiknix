<?php
/**
 * 05_SecurityControl.php — the path rules every install enforces.
 *
 * `database/security.db` is untracked and built per install, so a rule added on core
 * reaches nobody. This is how a rule ships: seeded here, enforced by
 * scripts/hooks/security-sandbox.php, which is level-aware and editable from the admin UI.
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
 * Level 50 throughout: an ADMIN can still work on these, a build agent cannot.
 * `.claude`, `/conf/`, `/lib/`, `scripts/hooks` and `CLAUDE.md` are already rows.
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
