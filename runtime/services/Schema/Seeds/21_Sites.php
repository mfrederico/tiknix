<?php
/**
 * 21_Sites.php — the `site` table (lib/Sites.php), the install's default site, the member's
 * default-site pointer and the member↔site assignment table. CONNECTOR-CATALOG-PLAN.md §2c.
 *
 * Sized by probe rows so RedBean never widens a column later; the default site row `main` is
 * created once and never touched again (a person may rename it).
 */
use \RedBeanPHP\R;

if (!$_tableCheck('site')) {
    $s = R::dispense('site');
    $s->slug          = str_repeat('x', 40);
    $s->name          = str_repeat('x', 120);
    $s->domain        = str_repeat('x', 253);
    $s->status        = str_repeat('x', 16);     // active | closed
    $s->settings_json = str_repeat('x', 4000);
    $s->branding_json = str_repeat('x', 4000);
    $s->address_json  = str_repeat('x', 2000);
    $s->parent_ref    = 1;
    $s->created_at    = str_repeat('x', 40);
    $s->updated_at    = str_repeat('x', 40);
    R::store($s);
    $_defer($s);
    echo "  site table created\n";
}
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_site_slug ON site (slug)');
R::exec('CREATE INDEX IF NOT EXISTS idx_site_domain ON site (domain)');

if (!$_tableCheck('membersite')) {
    $ms = R::dispense('membersite');
    $ms->member_ref = 1;
    $ms->site_ref   = 1;
    $ms->role       = str_repeat('x', 32);       // manager | staff | viewer
    $ms->created_at = str_repeat('x', 40);
    R::store($ms);
    $_defer($ms);
    echo "  membersite table created\n";
}
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_membersite ON membersite (member_ref, site_ref)');

if ($_tableCheck('member') && !array_key_exists('site_ref', R::inspect('member'))) {
    R::exec('ALTER TABLE member ADD COLUMN site_ref INTEGER DEFAULT 0');
    echo "  member.site_ref added\n";
}

// The default site — after the probe has been deferred, so it is not the probe.
$main = R::findOne('site', 'slug = ?', ['main']);
if (!$main || !$main->id) {
    $main = R::dispense('site');
    $main->slug = 'main';
    $main->name = (string) (\Flight::get('app.name') ?? 'Main');
    $main->domain = '';
    $main->status = 'active';
    $main->settings_json = '{}';
    $main->branding_json = '{}';
    $main->address_json  = '{}';
    $main->parent_ref = 0;
    $main->created_at = date('Y-m-d H:i:s');
    R::store($main);
    echo "  default site 'main' created (#{$main->id})\n";
}

// /site/status is how two domains on one install are proved to be two sites; /site/switch is
// the member's switcher. Seeded here, before anything fetches them.
foreach ([['status', 101, 'Which site this host resolves to (public)'], ['switch', 100, 'Act as another site for this session']] as [$method, $level, $desc]) {
    echo "  authcontrol: site::{$method} => " . \app\PermissionCache::seedRule('site', $method, $level, $desc) . "\n";
}
