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
    ['auth', 'twofaskip', 101, '2FA skip setup (optional mode)'],
    ['auth', 'twofarecoverycodes', 101, '2FA recovery codes'],
    ['auth', 'setpassword', 101, 'Set password (post-2FA / oauth)'],
    // Public so a logged-OUT invitee can open the token link: join() self-serves a
    // new account for the invited email, then hands off to auth::setpassword. The
    // teams::* wildcard (100) would otherwise bounce them to login before they can
    // create an account. The token is the credential; only a valid invite gets in.
    ['teams', 'join', 101, 'Accept a team invite via token (public, self-serve account create)'],
    ['install', 'index', 101, 'First-run setup wizard'],
    ['install', 'save', 101, 'First-run setup wizard submit'],
    ['docs', '*', 101, 'Documentation'],
    ['help', '*', 101, 'Help pages'],
    ['contact', 'index', 101, 'Contact form'],
    ['contact', 'submit', 101, 'Submit contact form'],
    ['terms', 'index', 101, 'Terms of service'],
    ['privacy', 'index', 101, 'Privacy policy'],
    // Marketing pricing page. Public so guests can view it; the controller itself
    // gates it to the flagship site (redirects to / on a provisioned instance), so
    // this row is harmless on instances. Explicit row avoids a build_mode deploy
    // auto-creating it at a restrictive default level.
    ['pricing', '*', 101, 'Public marketing pricing page (flagship-gated in-controller)'],

    // Member (100)
    ['auth', 'logout', 100, 'Logout'],
    ['member', '*', 100, 'All member methods'],
    ['dashboard', '*', 100, 'Dashboard access'],
    ['apikeys', '*', 100, 'API key management'],
    ['grocery', '*', 100, 'Grocery list management'],
    ['workbench', '*', 100, 'Workbench access'],
    // Generic sidecar-plugin launcher: /sidecar/launch/<name> (Explorer, Store, …).
    // MEMBER-eligible; each plugin's own Feature grant gates it (Sidecar::launch enforces).
    ['sidecar', '*', 100, 'Sidecar plugin launcher (per-plugin feature-gated)'],
    ['teams', '*', 100, 'Teams management'],
    ['communications', '*', 100, 'Threaded email inbox'],

    // Admin (50)
    ['admin', '*', 50, 'Admin panel access'],
    ['translations', '*', 50, 'Translations editor (i18n)'],
    ['aibuilder', '*', 50, 'AI Builder'],
    ['permissions', '*', 50, 'Permission management'],
    ['contact', 'admin', 50, 'View contact messages'],
    ['contact', 'view', 50, 'View single message'],
    ['contact', 'respond', 50, 'Respond to message'],
    ['lead', 'admin', 50, 'View captured leads'],
    ['lead', 'delete', 50, 'Delete a lead'],
    ['lead', 'export', 50, 'Export leads CSV'],
    ['leads', 'data', 50, 'Leads DataTable AJAX feed'],
    ['leads', 'delete', 50, 'Delete a lead / purge bot-flagged leads'],
    ['mcpregistry', '*', 50, 'MCP Server Registry management'],
    // Matches the level of the other connections::* rows (e.g. connections::add = 50).
    ['connections', 'connectkey', 50, 'Connect an api_key connector from a validated pasted key'],
    ['connections', 'webhooksecret', 50, 'Set/clear a connection webhook verification secret'],
    ['connections', 'publishfeed', 50, 'Publish a public social showcase page for a social connection'],
    ['ecommerce', '*', 50, 'Ecommerce storefront tools (per-member feature-flagged)'],

    // Public storefront (101) — the /shop front controller (Shop.php) + legacy
    // redirect/alias shims. Must be PUBLIC so guests can browse the store.
    ['shop', '*', 101, 'Public storefront front controller'],
    ['social', '*', 101, 'Public social showcase front controller'],
    ['products', '*', 101, 'Storefront legacy redirect -> /shop/product'],
    ['categories', '*', 101, 'Storefront legacy redirect -> /shop/catalog'],
    ['store', '*', 101, 'Storefront alias -> /shop'],
    ['catalog', '*', 101, 'Storefront alias -> /shop/catalog'],
    ['category', '*', 101, 'Storefront alias -> /shop/catalog'],

    // Public webhook (101) — authenticates itself via Mailgun HMAC
    ['webhook', 'mailgun', 101, 'Mailgun inbound mail + delivery-event webhook'],

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

// Schema is 100% bean-derived — no hand-declared indexes/constraints. RedBean
// has no bean-native way to express the composite UNIQUE (control, method) or a
// plain level index, so they are not created at the DB level. Duplicate rows
// are prevented by the findOne(control, method) idempotency guard in the loop
// above.
