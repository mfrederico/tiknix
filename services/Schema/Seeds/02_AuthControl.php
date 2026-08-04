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
    // Store checkout broker for the shop.tiknix sidecar. PUBLIC — it authenticates
    // itself via an HMAC signature ([sidecar.shop] secret); no session.
    ['storebroker', '*', 101, 'Shop sidecar checkout broker (HMAC-signed)'],
    // Pipeline run surfaces. api/trigger/status are PUBLIC — self-authenticating via
    // a per-member pk_ key or the [pipeline] trigger_secret. keys is ADMIN (mint UI).
    ['pipeline', 'api', 101, 'Pipeline REST API (per-member pk_ key)'],
    ['pipeline', 'trigger', 101, 'Pipeline cron/webhook trigger (trigger_secret)'],
    ['pipeline', 'status', 101, 'Pipeline run status (per-member pk_ key)'],
    ['pipeline', 'debug', 101, 'Pipeline step-trace debug start (trigger_secret)'],
    ['pipeline', 'debugstep', 101, 'Pipeline step-trace advance (trigger_secret)'],
    ['pipeline', 'object', 101, 'Durable object onMessage (trigger_secret)'],
    ['pipeline', 'objecttick', 101, 'Durable object alarm tick (trigger_secret)'],
    ['pipeline', 'keys', 50, 'Pipeline API key management (ADMIN)'],
    ['pipeline', 'mykey', 100, 'Self-service: mint a REST test key for the current member'],
    ['pipeline', 'mintkey', 101, 'Editor-driven pk_ REST key mint (self-auth via trigger_secret)'],
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
    // The Integrations hub is MEMBER (100) — instance owners manage their OWN instance's
    // connectors + pipelines + durable objects (Connections::index is ownedInstance-scoped).
    // An instance asks core "what am I connected to?" with its own broker key (metadata only).
    ['brokerinfo', 'connections', 101, 'Instance connection lookup (self-authenticating broker key)'],
    ['brokerinfo', 'connectors', 101, 'Available connectors for the instance connect flow (broker key)'],
    ['brokerinfo', 'connectkey', 101, 'Instance-driven api_key connect (broker key)'],
    ['brokerinfo', 'disconnect', 101, 'Instance-driven disconnect (broker key)'],
    ['brokerinfo', 'connectintent', 101, 'Instance-driven OAuth connect handoff (broker key)'],
    // The inverse door: core (or a sidecar) calling THIS install about its own
    // connectors, authenticated by this install's conf/broker.ini key. PUBLIC means
    // reachable, not unprotected — Connectorapi::authed() closes it when no key is
    // configured. Without these rows the first request invents one at the ADMIN
    // default and every push 303s to /auth/login, which the caller cannot tell from
    // "that instance has no such connector".
    ['connectorapi', 'list', 101, 'Instance connector API: list (self-auth via broker key)'],
    ['connectorapi', 'connect', 101, 'Instance connector API: connect a pasted key (self-auth via broker key)'],
    ['connectorapi', 'receive', 101, 'Instance connector API: receive a pre-validated credential (self-auth via broker key)'],
    ['connectorapi', 'disconnect', 101, 'Instance connector API: disconnect (self-auth via broker key)'],
    // The instance-side read-only Integrations view (runs IN the instance).
    ['integrations', 'index', 100, 'Integrations/automations view (owner on control plane; ADMIN enforced in-controller inside an instance)'],
    ['connections', 'index', 100, 'Integrations hub (owner-scoped)'],
    ['connections', 'pipelinerun', 100, 'Trigger one of the instance pipelines (owner)'],
    ['connections', 'githubwebhook', 100, 'Provision the GitHub deploy webhook (owner)'],
    ['connections', 'handoff', 101, 'Instance-driven OAuth connect handoff (self-auth via signed intent)'],
    ['connections', 'instanceconnect', 100, 'Instance-side: start an OAuth connect (owner/admin)'],
    ['connections', 'instanceconnectkey', 100, 'Instance-side: connect an api_key connector (owner/admin)'],
    ['connections', 'instancedisconnect', 100, 'Instance-side: disconnect (owner/admin)'],
    ['connections', 'connectkey', 100, 'Connect an api_key connector from a validated pasted key'],
    ['connections', 'webhooksecret', 100, 'Set/clear a connection webhook verification secret'],
    ['connections', 'publishfeed', 100, 'Publish a public social showcase page for a social connection'],

    // NOTE: the platform storefront (shop/ecommerce/store/products/catalog/category)
    // was removed — the store is now the shop.tiknix sidecar (per-instance). The
    // Stripe connector + connections custody stay; checkout is brokered via
    // storebroker::* (below). See explorer.tiknix/PROVISION + memory sidecar-plugins.
    ['social', '*', 101, 'Public social showcase front controller'],

    // Public webhook (101) — authenticates itself via Mailgun HMAC
    ['webhook', 'mailgun', 101, 'Mailgun inbound mail + delivery-event webhook'],
    ['webhook', 'github', 101, 'GitHub push webhook → deploy pipelines (self-auth via HMAC)'],
    // Was missing, and the gap is the interesting part: with no seeded row the first
    // request to this route makes one at the ADMIN default, so the route locks itself
    // the moment anything touches it — a delivery, or a curl while testing. Every
    // instance in the fleet had webhook::github invented at level 50 that way, which
    // 303s Telegram and GitHub instead of answering them.
    ['webhook', 'telegram', 101, 'Telegram inbound webhook (per-connection secret token)'],

    // Public MCP endpoints (101) — auth handled by the controller
    ['mcp', '*', 101, 'MCP server endpoints'],
    ['mcp', 'message', 101, 'MCP JSON-RPC endpoint'],
    ['mcp', 'health', 101, 'MCP health check'],
    ['mcpregistry', 'testConnection', 101, 'Test MCP server connection'],

    // Root only (1)
    ['permissions', 'build', 1, 'Build mode - scan controllers'],
    ['permissions', 'scan', 1, 'Scan for new permissions'],
];

// Applied through PermissionCache::seedRule, which does the one thing a plain
// "skip if it exists" cannot: CORRECT a row the framework invented.
//
// The old loop continued past every existing row, so a route that got an
// auto-generated ADMIN rule before it was seeded — which happens the first time
// ANYTHING touches it, including a delivery or a curl while testing — kept that
// rule forever, and adding the right line here changed nothing. That is exactly
// what happened to webhook::github and webhook::telegram across the whole fleet:
// both were seeded at 101 and both stayed at 50 on every install that had already
// been hit. seedRule overrules ONLY rows still carrying the auto-generated marker,
// so a level somebody deliberately set is reported as `kept` and left alone.
$_acCounts = ['added' => 0, 'corrected' => 0, 'kept' => 0, 'unchanged' => 0];
foreach ($defaults as [$control, $method, $level, $desc]) {
    $r = \app\PermissionCache::seedRule($control, $method, $level, $desc);
    $_acCounts[$r] = ($_acCounts[$r] ?? 0) + 1;
    if ($r === 'kept') {
        echo "  authcontrol: kept a hand-set level for {$control}::{$method} (seed wanted {$level})\n";
    }
}
echo '  authcontrol: ' . json_encode($_acCounts) . "\n";

// Schema is 100% bean-derived — no hand-declared indexes/constraints. RedBean
// has no bean-native way to express the composite UNIQUE (control, method) or a
// plain level index, so they are not created at the DB level. Duplicate rows
// are prevented by the findOne(control, method) idempotency guard in the loop
// above.
