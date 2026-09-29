<?php
/**
 * App shell — dark sidebar + slim topbar. Opens .ui-shell > .ui-sidebar +
 * .ui-main > .ui-topbar + .ui-content; layouts/footer.php closes them.
 * Design tokens in views/components/design-system.php.
 */
$__loggedIn = $isLoggedIn ?? false;
/* A guest is PUBLIC, not MEMBER. This started at 100, which is the wrong direction to be
   wrong in: every level test here is "<=", so an unauthenticated visitor was being
   measured as though they were a signed-in member. Nothing was reachable through it
   (each gated block sits inside $__loggedIn), but a default that fails open is a trap
   waiting for the first person to move a check outside that guard. */
$__uname = 'User'; $__level = LEVELS['PUBLIC'];
$__mid   = 0;
/* Resolved from Flight::getMember(), NOT from the $member view variable.
   'member' is view DATA, and a controller owns its view data: Contact::view legitimately
   passes the person who submitted the message under that name, which is null when a guest
   submitted it. The shell then rendered a signed-in chrome around somebody else's identity
   — and the notify bell, which guards on $__loggedIn alone, called member_id(null) and
   500'd the page. Reading the session identity from the same call Control uses means no
   controller can shadow the shell's idea of who is looking at it. */
$__me = \Flight::getMember();
if ($__loggedIn) {
    $__uname = (string)($__me['username'] ?? 'User');
    // No defaults past this point: inside this branch the member is signed in, so a
    // missing id or level is a broken session and should say so rather than be guessed.
    $__mid   = member_id($__me, 'layout header');
    $__level = (int)$__me['level'];
}
$__initials = strtoupper(mb_substr($__uname, 0, 2)) ?: 'U';
$__isAdmin = $__level <= LEVELS['ADMIN'];
$__cur = $_SERVER['REQUEST_URI'] ?? '';
$__active = function (string $u) use ($__cur): string {
    return ($u !== '' && $u !== '#' && $u !== '/' && strpos($__cur, $u) === 0) ? ' active' : '';
};

// loadMenu() uses FontAwesome-style icon names; map them to Bootstrap Icons.
$__iconMap = [
    'home' => 'house', 'dashboard' => 'speedometer2', 'user' => 'person', 'cog' => 'gear',
    'sign-out' => 'box-arrow-right', 'sign-in' => 'box-arrow-in-right', 'user-plus' => 'person-plus',
];
$__icon = fn($i) => $__iconMap[$i] ?? ($i ?: 'dot');

// These live elsewhere — don't repeat them in the sidebar. Home (/) points at the
// marketing site (irrelevant once you're inside; still reachable via the brand logo),
// and Profile moves to the avatar dropdown with the other account items. Dashboard stays.
$__skip = ['/auth/logout' => 1, '/admin' => 1, '/' => 1, '/member/profile' => 1];

// Group the dynamic menu by its optional 'section' (default "Main").
$__sections = [];
foreach (($menu ?? []) as $__it) {
    if (isset($__it['url']) && isset($__skip[$__it['url']])) continue;
    $__sections[$__it['section'] ?? 'Main'][] = $__it;
}
// Whatever runs this app may add to the shell (app\Chrome). The 'prepare' parts see the menu
// before it is drawn and may add to it; one that draws a band under the top bar sets
// $__chromeBar so the page leaves room for it.
$__chromeBar = false;
foreach (\app\Chrome::files('prepare') as $__part) include $__part;
// Surface Communications in the "Main" group for logged-in users.
// Dedupe against whatever the dynamic menu already provides so nothing doubles up.
if ($__loggedIn) {
    $__have = [];
    foreach ($__sections as $__grp) foreach ($__grp as $__i) { if (isset($__i['url'])) $__have[$__i['url']] = 1; }
    $__main = [['url' => '/communications', 'label' => 'Communications', 'icon' => 'chat-left-dots']];
    foreach ($__main as $__add) {
        if (!isset($__have[$__add['url']])) $__sections['Main'][] = $__add;
    }
    // Leads is an admin-only capability but is grouped under Main per preference.
    if ($__isAdmin && !isset($__have['/leads'])) {
        $__leadCount = 0;
        try { $__leadCount = (int)\app\Bean::count('lead'); } catch (\Throwable $e) {}
        $__sections['Main'][] = ['url' => '/leads', 'label' => 'Leads', 'icon' => 'person-lines-fill', 'badge' => $__leadCount];
    }
    /* Support messages had no way in at all — /contact/admin was reachable only by typing
       the URL, which is most of why 187 of them sat unread. A page nobody can navigate to
       is a page nobody reads, so the unanswered count goes where it can be seen. */
    if ($__isAdmin && !isset($__have['/contact/admin'])) {
        $__supportNew = 0;
        try { $__supportNew = (int)\app\Bean::count('contact', 'status = ?', ['new']); } catch (\Throwable $e) {}
        $__sections['Main'][] = ['url' => '/contact/admin', 'label' => 'Support', 'icon' => 'life-preserver', 'badge' => $__supportNew];
    }
}
?>
<div class="ui-shell">
  <div class="ui-sidebar-backdrop" id="uiSidebarBackdrop" onclick="uiToggleSidebar(false)"></div>

  <aside class="ui-sidebar" id="uiSidebar">
    <a class="ui-sidebar-brand" href="/" aria-label="<?= htmlspecialchars($site_name ?? 'Tiknix') ?>">
      <?php $__brandLogo = $site_logo ?? Flight::siteLogo(); ?>
      <?php if ($__brandLogo !== ''): ?>
        <img class="ui-brand-logo" src="<?= htmlspecialchars($__brandLogo) ?>" alt="" style="max-height:28px;width:auto;object-fit:contain">
      <?php elseif ($__isCore ?? is_core_install()): /* the Tiknix mark only on the flagship */ ?>
        <span class="ui-brand-logo"></span>
      <?php endif; ?>
      <span class="ui-brand-word"><?= htmlspecialchars($site_name ?? Flight::siteName()) ?></span>
    </a>

    <nav class="ui-nav">
      <?php foreach ($__sections as $__secName => $__items): ?>
        <div class="ui-nav-heading"><?= htmlspecialchars($__secName) ?></div>
        <?php foreach ($__items as $__it): ?>
          <?php if (isset($__it['dropdown'])): ?>
            <?php foreach ($__it['dropdown'] as $__sub): $__u = $__sub['url'] ?? '#'; ?>
              <a class="ui-nav-link<?= $__active($__u) ?>" href="<?= htmlspecialchars($__u) ?>">
                <i class="bi bi-<?= htmlspecialchars($__icon($__sub['icon'] ?? '')) ?>"></i>
                <?= htmlspecialchars($__sub['label'] ?? '') ?>
              </a>
            <?php endforeach; ?>
          <?php else: $__u = $__it['url'] ?? '#'; ?>
            <a class="ui-nav-link<?= $__active($__u) ?>" href="<?= htmlspecialchars($__u) ?>">
              <i class="bi bi-<?= htmlspecialchars($__icon($__it['icon'] ?? '')) ?>"></i>
              <?= htmlspecialchars($__it['label'] ?? '') ?>
              <?php if (!empty($__it['badge'])): ?><span class="ui-nav-badge"><?= (int)$__it['badge'] ?></span><?php endif; ?>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <?php if ($__loggedIn): ?>
        <?php foreach (\app\Chrome::files('nav') as $__part) include $__part; ?>

        <?php if ($__isAdmin): ?>
          <div class="ui-nav-heading">Admin</div>
          <?php if (!builder_tools_enabled()): /* inside an instance: read-only "what am I wired to" views */ ?>
            <a class="ui-nav-link<?= $__active('/connections') ?>" href="/connections"><i class="bi bi-plug"></i> Connections</a>
            <a class="ui-nav-link<?= $__active('/integrations') ?>" href="/integrations"><i class="bi bi-diagram-3"></i> Integrations</a>
          <?php endif; ?>
          <?php /* /admin/concepts lives under /admin, and $__active is a prefix match — without
                   this both links would light up on the Plugins page. */
                $__onPlugins = strpos($__cur, '/admin/concept') === 0; ?>
          <a class="ui-nav-link<?= $__onPlugins ? '' : $__active('/admin') ?>" href="/admin"><i class="bi bi-shield-lock"></i> Admin</a>
          <?php /* ROOT only, matching admin::concepts* — switching a plugin on makes new code
                   routable and runs its seeds. Hidden rather than shown-and-refused. */ ?>
          <?php if ($__level <= LEVELS['ROOT']): ?>
            <a class="ui-nav-link<?= $__onPlugins ? ' active' : '' ?>" href="/admin/concepts"><i class="bi bi-puzzle"></i> Plugins</a>
          <?php endif; ?>
          <?php /* This install's own pipelines — the editor that used to be the pipelines.tiknix
                   sidecar (COMPONENTS_PLAN.md, "Every app has its own /pipelines"). ADMIN, like
                   the rest of this group: pipelines are the app's automations. */ ?>
          <a class="ui-nav-link<?= $__active('/pipelines') ?>" href="/pipelines"><i class="bi bi-diagram-2"></i> Data</a>
          <a class="ui-nav-link<?= $__active('/security') ?>" href="/security"><i class="bi bi-shield-check"></i> Security</a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>

    <?php if ($__loggedIn): ?>
    <?php /* Identity only. Signing out sits with the other things you do to your own
             account — profile, settings, keys — in the topbar dropdown, rather than
             being the one account action stranded at the bottom of the navigation. */ ?>
    <div class="ui-sidebar-foot">
      <div class="d-flex align-items-center gap-2">
        <span class="ui-avatar" style="background:var(--ui-accent-order)"><?= htmlspecialchars($__initials) ?></span>
        <div style="min-width:0;line-height:1.2">
          <div class="text-truncate" style="color:#fff;font-size:.85rem;font-weight:600"><?= htmlspecialchars($__uname) ?></div>
          <div style="font-size:.72rem;color:var(--ui-sidebar-heading)"><?php
            /* The sign-out link used to live on this line. Replacing it with nothing
               would leave an empty row under the name, so it says what the account IS —
               which is the other thing people look at the bottom of a sidebar for. */
            $__levels = [0 => 'Root', 1 => 'Root', 50 => 'Admin', 100 => 'Member', 101 => 'Guest'];
            echo htmlspecialchars($__levels[$__level] ?? 'Member');
          ?></div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </aside>

  <div class="ui-main<?= $__chromeBar ? ' has-projectbar' : '' ?>">
    <header class="ui-topbar">
      <button class="ui-btn-icon d-lg-none" type="button" onclick="uiToggleSidebar(true)" aria-label="Open menu"><i class="bi bi-list"></i></button>
      <div class="ui-topbar-title">
        <span class="ui-eyebrow"><?= htmlspecialchars($topbar_eyebrow ?? ($site_name ?? 'Tiknix')) ?></span>
        <strong><?= htmlspecialchars($title ?? 'App') ?></strong>
      </div>

      <ul class="navbar-nav flex-row align-items-center gap-2 ms-auto mb-0">
        <li class="nav-item">
          <button class="ui-btn-icon" id="uiThemeToggle" type="button" aria-label="Toggle theme"><i class="bi bi-moon-stars"></i></button>
        </li>
        <?php if ($__loggedIn): ?>
          <?php include \Flight::view()->getTemplate('layouts/_notify-bell'); ?>
          <li class="nav-item dropdown">
            <a class="text-decoration-none" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <span class="ui-user-chip">
                <span class="d-none d-sm-inline" style="font-size:.9rem;color:var(--bs-body-color)"><?= htmlspecialchars($__uname) ?></span>
                <span class="ui-avatar" style="background:var(--ui-accent-order)"><?= htmlspecialchars($__initials) ?></span>
              </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow">
              <li><a class="dropdown-item" href="/dashboard"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
              <li><a class="dropdown-item" href="/member/profile"><i class="bi bi-person me-2"></i>Profile</a></li>
              <li><a class="dropdown-item" href="/member/settings"><i class="bi bi-gear me-2"></i>Settings</a></li>
              <?php /* Shown to whoever has been GRANTED mcp (admins always). An API key
                       authenticates MCP tools/call against this instance, so issuing one is
                       programmatic access — see controls/Apikeys. Hidden rather than
                       shown-and-refused: a link that always 403s is a worse error message. */ ?>
              <?php if (\app\Feature::allows('mcp', $__mid, $__level)): ?>
                <li><a class="dropdown-item" href="/apikeys"><i class="bi bi-key me-2"></i>API Keys</a></li>
              <?php endif; ?>
<?php foreach (\app\Chrome::files('account') as $__part) include $__part; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="/auth/logout"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-sm btn-outline-secondary" href="/auth/login">Login</a></li>
          <?php if (Flight::getSetting('registration_enabled', 0) != '0'): ?>
          <li class="nav-item"><a class="btn btn-sm btn-primary ms-1" href="/auth/register">Register</a></li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>
    </header>

    <?php foreach (\app\Chrome::files('bar') as $__part) include $__part; ?>

    <?php if (!empty($breadcrumbs)): ?>
    <nav aria-label="breadcrumb" class="px-4 pt-3">
      <ol class="breadcrumb mb-0">
        <?php foreach ($breadcrumbs as $crumb): ?>
          <?php if (!empty($crumb['active'])): ?>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($crumb['label'] ?? '') ?></li>
          <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($crumb['url'] ?? '#') ?>"><?= htmlspecialchars($crumb['label'] ?? '') ?></a></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ol>
    </nav>
    <?php endif; ?>

    <div class="ui-content">
      <?php if (!empty($agent_credit_alert)): ?>
      <div class="alert alert-warning d-flex align-items-start gap-2 mx-4 mt-3 mb-0" role="alert">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <div>
          <strong>AI tasks are paused — out of credit.</strong>
          The <?= htmlspecialchars((($agent_credit_alert['engine'] ?? '') === 'claude' || ($agent_credit_alert['engine'] ?? '') === 'zai') ? 'Anthropic' : 'AI provider') ?>
          account behind this site's API key has run out of credit, so AI steps are failing. Add credit
          (or update the key) to resume — tasks pick back up automatically.
        </div>
      </div>
      <?php endif; ?>
