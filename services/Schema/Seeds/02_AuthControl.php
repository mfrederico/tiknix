<?php
/**
 * 02_AuthControl.php — route permissions (authcontrol). Seeds the default
 * control/method → level map via beans, mirroring sql/schema.sql. Idempotent:
 * existing rows (matched on control+method) are left as-is.
 *
 * Levels: 1=ROOT, 50=ADMIN, 100=MEMBER, 101=PUBLIC.
 */

use \RedBeanPHP\R;

// Pass 1 — padded sample to size columns; deferred.
if (!$_tableCheck('authcontrol')) {
    $s = R::dispense('authcontrol');
    $s->control     = '__schema_seed_' . str_repeat('x', 80);
    $s->method      = '__schema_seed_' . str_repeat('x', 80);
    $s->level       = 999;
    $s->description = str_repeat('x', 500);
    $s->created_at  = date('Y-m-d H:i:s');
    R::store($s);
    $_defer($s);
}

// Default permission map: [control, method, level, description].
$defaults = [
    // Public (101)
    ['index', 'index', 101, 'Home page'],
    ['index', '*', 101, 'All index methods'],
    ['auth', 'login', 101, 'Login page'],
    ['auth', 'dologin', 101, 'Process login'],
    ['auth', 'register', 101, 'Registration page'],
    ['auth', 'doregister', 101, 'Process registration'],
    ['auth', 'forgot', 101, 'Forgot password page'],
    ['auth', 'doforgot', 101, 'Process forgot password'],
    ['auth', 'reset', 101, 'Reset password page'],
    ['auth', 'doreset', 101, 'Process reset password'],
    ['auth', 'google', 101, 'Google OAuth login'],
    ['auth', 'googlecallback', 101, 'Google OAuth callback'],
    ['auth', 'verify', 101, 'Email verification'],
    ['auth', 'twofasetup', 101, '2FA setup page'],
    ['auth', 'twofaverify', 101, '2FA verification page'],
    ['auth', 'twofaconfirmsaved', 101, '2FA confirm recovery codes saved'],
    ['auth', 'twofarecoverycodes', 101, '2FA recovery codes'],
    ['auth', 'setpassword', 101, 'Set password (post-2FA / oauth)'],
    ['install', 'index', 101, 'First-run setup wizard'],
    ['install', 'save', 101, 'First-run setup wizard submit'],
    ['docs', '*', 101, 'Documentation'],
    ['help', '*', 101, 'Help pages'],
    ['contact', 'index', 101, 'Contact form'],
    ['contact', 'submit', 101, 'Submit contact form'],
    ['terms', 'index', 101, 'Terms of service'],
    ['privacy', 'index', 101, 'Privacy policy'],

    // Member (100)
    ['auth', 'logout', 100, 'Logout'],
    ['member', '*', 100, 'All member methods'],
    ['dashboard', '*', 100, 'Dashboard access'],
    ['apikeys', '*', 100, 'API key management'],
    ['grocery', '*', 100, 'Grocery list management'],
    ['workbench', '*', 100, 'Workbench access'],
    ['teams', '*', 100, 'Teams management'],

    // Admin (50)
    ['admin', '*', 50, 'Admin panel access'],
    ['aibuilder', '*', 50, 'AI Builder'],
    ['permissions', '*', 50, 'Permission management'],
    ['contact', 'admin', 50, 'View contact messages'],
    ['contact', 'view', 50, 'View single message'],
    ['contact', 'respond', 50, 'Respond to message'],
    ['lead', 'admin', 50, 'View captured leads'],
    ['lead', 'delete', 50, 'Delete a lead'],
    ['lead', 'export', 50, 'Export leads CSV'],
    ['mcpregistry', '*', 50, 'MCP Server Registry management'],

    // Public MCP endpoints (101) — auth handled by the controller
    ['mcp', '*', 101, 'MCP server endpoints'],
    ['mcp', 'message', 101, 'MCP JSON-RPC endpoint'],
    ['mcp', 'health', 101, 'MCP health check'],
    ['mcpregistry', 'testConnection', 101, 'Test MCP server connection'],

    // Root only (1)
    ['permissions', 'build', 1, 'Build mode - scan controllers'],
    ['permissions', 'scan', 1, 'Scan for new permissions'],
];

foreach ($defaults as [$control, $method, $level, $desc]) {
    $existing = \app\Bean::findOne('authcontrol', 'control = ? AND method = ?', [$control, $method]);
    if ($existing) {
        continue;
    }
    $ac = R::dispense('authcontrol');
    $ac->control     = $control;
    $ac->method      = $method;
    $ac->level       = $level;
    $ac->description = $desc;
    $ac->created_at  = date('Y-m-d H:i:s');
    R::store($ac);
}

try {
    R::exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_authcontrol ON authcontrol (control, method)');
    R::exec('CREATE INDEX IF NOT EXISTS idx_authcontrol_level ON authcontrol (level)');
} catch (\Exception $e) { /* indexes may already exist */ }
