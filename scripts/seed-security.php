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
require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;
use app\Bean;

new \app\Bootstrap(); // main connection (not used for the write, but boots config/log)

$projectRoot    = dirname(__DIR__);
$securityDbPath = $projectRoot . '/database/security.db';
@mkdir(dirname($securityDbPath), 0775, true);

// Switch to the isolated security DB (register once, then select).
if (!array_key_exists('security', R::$toolboxes ?? [])) {
    Bean::addDatabase('security', 'sqlite:' . $securityDbPath);
}
Bean::selectDatabase('security');

// 1) Ensure the table exists (matches the schema RedBean produces).
R::exec('CREATE TABLE IF NOT EXISTS securitycontrol (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT, target TEXT, action TEXT, pattern TEXT,
    level INTEGER, description TEXT, priority INTEGER,
    is_active INTEGER, created_at NUMERIC)');

// 2) The universal default ruleset. [name, target, action, pattern, level, priority].
$defaults = [
    // --- path blocks: system + sensitive locations ---
    ['Block /root',            'path', 'block', '/root',    10, 1],
    ['Block /boot',            'path', 'block', '/boot',    10, 1],
    ['Block /proc',            'path', 'block', '/proc',    10, 1],
    ['Block /sys',             'path', 'block', '/sys',     10, 1],
    ['Block /var/log',         'path', 'block', '/var/log', 10, 1],
    ['Block /etc access',      'path', 'block', '/etc',    100, 1],
    ['Block SSH keys',         'path', 'block', '/.ssh',    10, 1],
    ['Block AWS credentials',  'path', 'block', '/.aws',    10, 1],
    ['Block home dirs',        'path', 'block', '/home',    15, 1],
    ['Block .env files',       'path', 'block', '/\.env$/', 20, 1],

    // --- command blocks: destructive / remote-exec ---
    ['Block rm -rf /',         'command', 'block', '/\brm\s+(-rf?|--recursive)?\s*\//', 10, 1],
    ['Block dd to device',     'command', 'block', '/\bdd\s+.*of=\/dev/',               10, 1],
    ['Block mkfs',             'command', 'block', '/\bmkfs/',                          10, 1],
    ['Block chmod 777',        'command', 'block', '/\bchmod\s+.*777/',                 10, 1],
    ['Block curl pipe bash',   'command', 'block', '/\bcurl\s+.*\|\s*(ba)?sh/',         10, 1],
    ['Block wget pipe bash',   'command', 'block', '/\bwget\s+.*\|\s*(ba)?sh/',         10, 1],
    ['Block sudo',             'command', 'block', '/\bsudo\s+/',                       10, 1],
    ['Block reboot',           'command', 'block', '/\breboot\b/',                      10, 1],
    ['Block shutdown',         'command', 'block', '/\bshutdown\b/',                    10, 1],
    ['Block DROP DATABASE',    'command', 'block', '/DROP\s+DATABASE/i',                10, 1],

    // --- path protects: app internals (need elevated level to touch) ---
    ['Protect security hooks', 'path', 'protect', 'scripts/hooks',          50, 50],
    ['Protect Claude settings','path', 'protect', '.claude',                50, 50],
    ['Protect app config',     'path', 'protect', '/conf/',                 50, 50],
    ['Protect core libs',      'path', 'protect', '/lib/',                  50, 50],
    ['Protect base controllers','path','protect', 'controls/BaseControls',  50, 50],
    ['Protect CLAUDE.md',      'path', 'protect', 'CLAUDE.md',              50, 50],

    // --- command allows: safe dev tooling ---
    ['Allow git',              'command', 'allow', '/^git\s/',      100, 200],
    ['Allow php lint',         'command', 'allow', '/^php\s+-l/',   100, 200],
    ['Allow composer',         'command', 'allow', '/^composer\s/', 100, 200],
    ['Allow npm',              'command', 'allow', '/^npm\s/',      100, 200],
    ['Allow tmux',             'command', 'allow', '/^tmux\s/',     100, 200],

    // --- path allow: THIS instance's own project dir (computed) ---
    ['Allow project dir',      'path', 'allow', $projectRoot,       100, 100],
];

$now = date('Y-m-d H:i:s');
$added = 0; $skipped = 0;
foreach ($defaults as [$name, $target, $action, $pattern, $level, $priority]) {
    $exists = (int) R::getCell('SELECT COUNT(*) FROM securitycontrol WHERE target = ? AND pattern = ?', [$target, $pattern]);
    if ($exists) { $skipped++; continue; }
    R::exec('INSERT INTO securitycontrol (name, target, action, pattern, level, description, priority, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
        [$name, $target, $action, $pattern, $level, $name, $priority, $now]);
    $added++;
}

Bean::selectDatabase('default');

echo "seed-security: {$added} rule(s) added, {$skipped} already present ({$securityDbPath})\n";
