#!/usr/bin/env php
<?php
/**
 * Initialize the isolated security database
 *
 * This creates a separate SQLite database for security sandbox rules.
 * Keeping it separate from the main app DB provides:
 * - Isolation from potential rogue Claude access
 * - Independent backup/restore
 * - Tighter file permissions possible
 */

// RedBeanPHP setup for security DB only
require_once dirname(__DIR__) . '/vendor/autoload.php';

use RedBeanPHP\R;

$securityDbPath = __DIR__ . '/security.db';

// Connect to security database
R::setup('sqlite:' . $securityDbPath);
R::freeze(false); // Allow schema changes

echo "Initializing security database at: {$securityDbPath}\n\n";

// Create the securitycontrol table with initial schema
$rule = R::dispense('securitycontrol');
$rule->name = 'Block /etc access';
$rule->target = 'path';           // 'path' or 'command'
$rule->action = 'block';          // 'block', 'allow', 'protect'
$rule->pattern = '/etc';          // Path or regex pattern
$rule->level = null;              // null = applies to all, 50 = admin only, etc.
$rule->description = 'Block access to system configuration directory';
$rule->priority = 100;            // Lower = higher priority
$rule->isActive = 1;
$rule->createdAt = date('Y-m-d H:i:s');
R::store($rule);

// Add default security rules
$defaultRules = [
    // === BLOCKED PATHS (for everyone) ===
    ['Block /root', 'path', 'block', '/root', null, 'Block root home directory', 10],
    ['Block /boot', 'path', 'block', '/boot', null, 'Block boot partition', 10],
    ['Block /proc', 'path', 'block', '/proc', null, 'Block proc filesystem', 10],
    ['Block /sys', 'path', 'block', '/sys', null, 'Block sys filesystem', 10],
    ['Block /var/log', 'path', 'block', '/var/log', null, 'Block system logs', 10],
    ['Block SSH keys', 'path', 'block', '/.ssh', null, 'Block SSH directories', 10],
    ['Block AWS credentials', 'path', 'block', '/.aws', null, 'Block AWS config', 10],
    ['Block .env files', 'path', 'block', '/\.env$/', null, 'Block environment files (regex)', 20],
    ['Block other users homes', 'path', 'block', '#^/home/(?!mfrederico)#', null, 'Block other home dirs (regex)', 15],

    // === PROTECTED PATHS (admin only can write) ===
    ['Protect security hooks', 'path', 'protect', 'scripts/hooks', 50, 'Security hooks require ADMIN to modify', 50],
    ['Protect Claude settings', 'path', 'protect', '.claude', 50, 'Claude config requires ADMIN', 50],
    ['Protect app config', 'path', 'protect', '/conf/', 50, 'App configuration requires ADMIN', 50],
    ['Protect core libs', 'path', 'protect', '/lib/', 50, 'Core libraries require ADMIN', 50],
    ['Protect base controllers', 'path', 'protect', 'controls/BaseControls', 50, 'Base controllers require ADMIN', 50],
    ['Protect CLAUDE.md', 'path', 'protect', 'CLAUDE.md', 50, 'Claude instructions require ADMIN', 50],

    // === ALLOWED PATHS ===
    ['Allow project dir', 'path', 'allow', '/home/mfrederico/development/tiknix', 100, 'Project directory', 100],
    ['Allow nginx lua', 'path', 'allow', '/home/mfrederico/capricorn/etc/nginx/xpi', 50, 'Nginx lua scripts (admin only)', 100],
    ['Allow proxy files', 'path', 'allow', '/var/www/html/.proxy.', 100, 'Subdomain proxy files', 100],

    // === BLOCKED COMMANDS ===
    ['Block rm -rf /', 'command', 'block', '/\brm\s+(-rf?|--recursive)?\s*\//', null, 'Dangerous recursive delete', 10],
    ['Block dd to device', 'command', 'block', '/\bdd\s+.*of=\/dev/', null, 'Write to raw device', 10],
    ['Block mkfs', 'command', 'block', '/\bmkfs/', null, 'Format filesystem', 10],
    ['Block chmod 777', 'command', 'block', '/\bchmod\s+.*777/', null, 'World-writable permissions', 10],
    ['Block curl pipe bash', 'command', 'block', '/\bcurl\s+.*\|\s*(ba)?sh/', null, 'Remote code execution', 10],
    ['Block wget pipe bash', 'command', 'block', '/\bwget\s+.*\|\s*(ba)?sh/', null, 'Remote code execution', 10],
    ['Block sudo', 'command', 'block', '/\bsudo\s+/', null, 'Privilege escalation', 10],
    ['Block reboot', 'command', 'block', '/\breboot\b/', null, 'System reboot', 10],
    ['Block shutdown', 'command', 'block', '/\bshutdown\b/', null, 'System shutdown', 10],
    ['Block DROP DATABASE', 'command', 'block', '/DROP\s+DATABASE/i', null, 'Database destruction', 10],

    // === ALLOWED COMMANDS ===
    ['Allow git', 'command', 'allow', '/^git\s/', 100, 'Git commands', 200],
    ['Allow php lint', 'command', 'allow', '/^php\s+-l/', 100, 'PHP syntax check', 200],
    ['Allow composer', 'command', 'allow', '/^composer\s/', 100, 'Composer commands', 200],
    ['Allow npm', 'command', 'allow', '/^npm\s/', 100, 'NPM commands', 200],
    ['Allow tmux', 'command', 'allow', '/^tmux\s/', 100, 'Tmux commands', 200],
];

foreach ($defaultRules as $r) {
    $rule = R::dispense('securitycontrol');
    $rule->name = $r[0];
    $rule->target = $r[1];
    $rule->action = $r[2];
    $rule->pattern = $r[3];
    $rule->level = $r[4];
    $rule->description = $r[5];
    $rule->priority = $r[6];
    $rule->isActive = 1;
    $rule->createdAt = date('Y-m-d H:i:s');
    R::store($rule);
    echo "  + {$r[0]}\n";
}

// Set restrictive permissions on the security database
chmod($securityDbPath, 0600);

echo "\nSecurity database initialized with " . R::count('securitycontrol') . " rules.\n";
echo "File permissions set to 600 (owner read/write only).\n";

R::close();
