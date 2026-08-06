#!/usr/bin/env php
<?php
/**
 * seed-security.php — seed the isolated Claude Code sandbox rules database
 * (database/security.db, separate from the main app DB and always local SQLite).
 *
 * Fixes two things a fresh deploy/instance otherwise gets wrong:
 *   1. The securitycontrol table is only ever lazily created by RedBean when the
 *      first rule is saved via /security, so a never-used instance has a security.db
 *      file with no table — which crashes the /admin dashboard card. We CREATE it here.
 *   2. Without seeded rules the jailed agent runs with an EMPTY ruleset (permissive
 *      by default). We seed the universal safety blocks/protects/allows.
 *
 * Host-specific rules (a particular user's /home, project paths) are intentionally
 * NOT baked in — the "allow project dir" rule is computed from THIS instance's root.
 *
 * Idempotent: a rule already present (matched on target+pattern) is left as-is.
 * Uses raw R::exec on the security connection so it works even when the main app
 * connection is frozen (DB_FREEZE / production).
 *
 * Usage:  php scripts/seed-security.php
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

chdir(dirname(__DIR__));
// Standalone: RedBean straight onto the local security.db — no app bootstrap and
// no main database, so this runs reliably in the container entrypoint regardless
// of the main DB / DB_DSN state (it only ever touches security.db).
require_once __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;

$projectRoot    = dirname(__DIR__);
$securityDbPath = $projectRoot . '/database/security.db';
@mkdir(dirname($securityDbPath), 0775, true);

R::setup('sqlite:' . $securityDbPath);
if (!R::testConnection()) {
    fwrite(STDERR, "seed-security: cannot open {$securityDbPath}\n");
    exit(1);
}

// 1) Ensure the table exists (matches the schema RedBean produces).
R::exec('CREATE TABLE IF NOT EXISTS securitycontrol (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT, target TEXT, action TEXT, pattern TEXT,
    level INTEGER, description TEXT, priority INTEGER,
    is_active INTEGER, created_at NUMERIC)');

// 1b) `scope` says WHERE a rule is worth enforcing:
//       always   — enforce everywhere
//       unjailed — only outside the bubblewrap jail, which already enforces it
//     The hook reads it; a row without one is treated as 'always'.
$hasScope = false;
foreach (R::getAll('PRAGMA table_info(securitycontrol)') as $col) {
    if (($col['name'] ?? '') === 'scope') { $hasScope = true; break; }
}
if (!$hasScope) {
    R::exec("ALTER TABLE securitycontrol ADD COLUMN scope TEXT DEFAULT 'always'");
}

// 2) The universal default ruleset.
//    [name, target, action, pattern, level, priority, scope].
//
// Nothing here is scoped 'unjailed' because it is unimportant — it is scoped that
// way because bwrap PROVABLY enforces it: /root, /boot, /sys, /home and /var/log
// are never bind-mounted, and a jailed process holds CapEff=0 with NoNewPrivs=1
// and no block devices, so sudo/reboot/shutdown/mkfs/dd-to-device cannot succeed.
// Unjailed task workspaces still get every one of them.
$defaults = [
    // --- path blocks: system + sensitive locations ---
    ['Block /root',            'path', 'block', '/root',    10, 1, 'unjailed'],
    ['Block /boot',            'path', 'block', '/boot',    10, 1, 'unjailed'],
    ['Block /proc',            'path', 'block', '/proc',    10, 1, 'unjailed'],
    ['Block /sys',             'path', 'block', '/sys',     10, 1, 'unjailed'],
    ['Block /var/log',         'path', 'block', '/var/log', 10, 1, 'unjailed'],
    // level 10, not 100. The bypass test is `memberLevel <= rule->level`, so a
    // level of 100 let EVERY caller through (only PUBLIC=101 was ever stopped) —
    // the rule read as "admin only" and behaved as "nobody". 10 matches the other
    // system blocks: host tooling running as ROOT still passes, task agents do not.
    ['Block /etc access',      'path', 'block', '/etc',     10, 1, 'unjailed'],
    ['Block SSH keys',         'path', 'block', '/.ssh',    10, 1, 'always'],
    ['Block AWS credentials',  'path', 'block', '/.aws',    10, 1, 'always'],
    ['Block home dirs',        'path', 'block', '/home',    15, 1, 'unjailed'],
    // .env lives INSIDE the instance, which is the one read-write bind. bwrap does
    // not help here, so this one is enforced everywhere.
    ['Block .env files',       'path', 'block', '/\.env$/', 20, 1, 'always'],

    // --- command blocks: destructive / remote-exec ---
    // rm -rf against an absolute path stays 'always': the instance IS writable in
    // the jail, so this is the one destructive command that can still land.
    ['Block rm -rf /',         'command', 'block', '/\brm\s+(-rf?|--recursive)?\s*\//', 10, 1, 'always'],
    ['Block chmod 777',        'command', 'block', '/\bchmod\s+.*777/',                 10, 1, 'always'],
    // Network is up inside the jail on purpose (the agent's MCP is HTTP), so
    // piping a download into a shell is still reachable.
    ['Block curl pipe bash',   'command', 'block', '/\bcurl\s+.*\|\s*(ba)?sh/',         10, 1, 'always'],
    ['Block wget pipe bash',   'command', 'block', '/\bwget\s+.*\|\s*(ba)?sh/',         10, 1, 'always'],
    ['Block DROP DATABASE',    'command', 'block', '/DROP\s+DATABASE/i',                10, 1, 'always'],
    ['Block dd to device',     'command', 'block', '/\bdd\s+.*of=\/dev/',               10, 1, 'unjailed'],
    ['Block mkfs',             'command', 'block', '/\bmkfs/',                          10, 1, 'unjailed'],
    ['Block sudo',             'command', 'block', '/\bsudo\s+/',                       10, 1, 'unjailed'],
    ['Block reboot',           'command', 'block', '/\breboot\b/',                      10, 1, 'unjailed'],
    ['Block shutdown',         'command', 'block', '/\bshutdown\b/',                    10, 1, 'unjailed'],

    // --- path protects: app internals (need elevated level to touch) ---
    // These are the rules that do the real work inside a jail: everything here
    // lives in the instance, which is bind-mounted READ-WRITE, so bwrap will not
    // stop an agent editing the guardrails that constrain it.
    ['Protect security hooks', 'path', 'protect', 'scripts/hooks',          50, 50, 'always'],
    ['Protect Claude settings','path', 'protect', '.claude',                50, 50, 'always'],
    ['Protect app config',     'path', 'protect', '/conf/',                 50, 50, 'always'],
    ['Protect core libs',      'path', 'protect', '/lib/',                  50, 50, 'always'],
    ['Protect base controllers','path','protect', 'controls/BaseControls',  50, 50, 'always'],
    ['Protect CLAUDE.md',      'path', 'protect', 'CLAUDE.md',              50, 50, 'always'],
];

// The `allow` rules that used to live here are GONE, and deliberately so. Both
// checkPath() and checkCommand() end with "no rule matched -> allow", and rules are
// evaluated priority ASC — every allow sat at 100/200, after every block (1/10) and
// protect (50). An allow could therefore only ever fire for something that was
// already allowed by default: it could never exempt anything. The per-instance
// 'Allow project dir' row was the only thing that differed between instance
// databases, and it was a no-op, so dropping it makes the ruleset identical
// everywhere and safe to distribute as one file.

$now = date('Y-m-d H:i:s');
$added = 0; $updated = 0; $unchanged = 0; $removed = 0;

$wanted = [];
foreach ($defaults as [$name, $target, $action, $pattern, $level, $priority, $scope]) {
    $wanted["$target\0$pattern"] = true;

    $row = R::getRow('SELECT id, action, level, priority, scope FROM securitycontrol
                       WHERE target = ? AND pattern = ?', [$target, $pattern]);
    if (!$row) {
        R::exec('INSERT INTO securitycontrol (name, target, action, pattern, level, description, priority, is_active, scope, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$name, $target, $action, $pattern, $level, $name, $priority, $scope, $now]);
        $added++;
        continue;
    }

    // Reconcile, don't just skip. Seeding used to be insert-only, which meant a
    // rule could never be corrected or retired once it was out in the field —
    // exactly why every instance was still carrying rules we now know are dead.
    if ((string) $row['action'] !== $action || (int) $row['level'] !== $level
        || (int) $row['priority'] !== $priority || (string) ($row['scope'] ?? '') !== $scope) {
        R::exec('UPDATE securitycontrol SET action = ?, level = ?, priority = ?, scope = ?, is_active = 1 WHERE id = ?',
            [$action, $level, $priority, $scope, $row['id']]);
        $updated++;
    } else {
        $unchanged++;
    }
}

// A rule that is not in $defaults is NOT deleted. This seeder owns the canonical
// set; it does not own the database. Hosts carry hand-tuned rules that matter and
// that no default can predict — core has an `Allow nginx lua` for
// /home/ubuntu/capricorn at priority 5, which sits AHEAD of the blocks at 10 and is
// the only reason host tooling can read the capricorn scripts at all.
//
// An earlier version of this script deleted anything not in $defaults, on the
// reasoning that allow rules are dead weight (both check functions end in
// "no rule matched -> allow"). That reasoning holds only for the priorities THIS
// file ships — an allow ordered before a block is a genuine exemption, and
// wiping those took away real access. Retiring a rule is now a deliberate,
// separate act, not a side effect of re-seeding.
if ($removed) { /* unreachable by design; kept so the counter stays honest */ }

R::close();

echo "seed-security: {$added} added, {$updated} updated, {$unchanged} unchanged, {$removed} retired ({$securityDbPath})\n";
