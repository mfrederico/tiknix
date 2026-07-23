<?php
/**
 * App shell — dark sidebar + slim topbar. Opens .ui-shell > .ui-sidebar +
 * .ui-main > .ui-topbar + .ui-content; layouts/footer.php closes them.
 * Design tokens in views/components/design-system.php.
 */
$__loggedIn = $isLoggedIn ?? false;
$__uname = 'User'; $__level = 100;
if ($__loggedIn && $member) {
    $__uname = (string)($member['username'] ?? 'User');
    $__level = (int)($member['level'] ?? 100);
}
$__initials = strtoupper(mb_substr($__uname, 0, 2)) ?: 'U';
$__isAdmin = $__level <= 50;
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
// Surface Teams + Communications in the "Main" group for logged-in users. Teams was
// only reachable from the topbar dropdown; Communications moved here out of Workspace.
// Dedupe against whatever the dynamic menu already provides so nothing doubles up.
if ($__loggedIn) {
    $__have = [];
    foreach ($__sections as $__grp) foreach ($__grp as $__i) { if (isset($__i['url'])) $__have[$__i['url']] = 1; }
    foreach ([
        ['url' => '/teams',          'label' => 'Teams',          'icon' => 'people'],
        ['url' => '/communications', 'label' => 'Communications', 'icon' => 'chat-left-dots'],
    ] as $__add) {
        if (!isset($__have[$__add['url']])) $__sections['Main'][] = $__add;
    }
    // Leads is an admin-only capability but is grouped under Main per preference.
    if ($__isAdmin && !isset($__have['/leads'])) {
        $__leadCount = 0;
        try { $__leadCount = (int)\app\Bean::count('lead'); } catch (\Throwable $e) {}
        $__sections['Main'][] = ['url' => '/leads', 'label' => 'Leads', 'icon' => 'person-lines-fill', 'badge' => $__leadCount];
    }
}
?>
<div class="ui-shell">
  <div class="ui-sidebar-backdrop" id="uiSidebarBackdrop" onclick="uiToggleSidebar(false)"></div>

  <aside class="ui-sidebar" id="uiSidebar">
    <a class="ui-sidebar-brand" href="/" aria-label="<?= htmlspecialchars($site_name ?? 'Tiknix') ?>">
      <span class="ui-brand-logo"></span>
      <span class="ui-brand-word">tiknix</span>
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
        <?php if (builder_tools_enabled()): ?>
          <div class="ui-nav-heading">Workspace</div>
          <a class="ui-nav-link<?= $__active('/workbench') ?>" href="/workbench"><i class="bi bi-hammer"></i> AI Projects</a>
          <a class="ui-nav-link<?= $__active('/aibuilder') ?>" href="/aibuilder"><i class="bi bi-robot"></i> Advanced Builder</a>
          <a class="ui-nav-link<?= $__active('/connections') ?>" href="/connections"><i class="bi bi-plug"></i> Integrations</a>
        <?php endif; ?>

        <?php /* Ecommerce moved to the shop.tiknix sidecar — listed via the plugin nav below. */ ?>
        <?php if (class_exists('\\app\\Sidecar\\Registry')): ?>
          <?php $__plugins = \app\Sidecar\Registry::launchable(); $__pfirst = true; ?>
          <?php foreach ($__plugins as $__pname => $__p): ?>
            <?php if (\app\Feature::isEnabled($__p['feature'], (int)($member['id'] ?? 0), $__level)): ?>
              <?php if ($__pfirst): ?><div class="ui-nav-heading">Plugins</div><?php $__pfirst = false; endif; ?>
              <a class="ui-nav-link" href="/sidecar/launch/<?= htmlspecialchars($__pname) ?>"><i class="bi <?= htmlspecialchars($__p['icon']) ?>"></i> <?= htmlspecialchars($__p['label']) ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($__isAdmin): ?>
          <div class="ui-nav-heading">Admin</div>
          <?php if (builder_tools_enabled()): ?>
            <a class="ui-nav-link<?= $__active('/agentsetup') ?>" href="/agentsetup"><i class="bi bi-sliders"></i> Agent Setup</a>
          <?php endif; ?>
          <a class="ui-nav-link<?= $__active('/admin') ?>" href="/admin"><i class="bi bi-shield-lock"></i> Admin</a>
          <a class="ui-nav-link<?= $__active('/security') ?>" href="/security"><i class="bi bi-shield-check"></i> Security</a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>

    <?php if ($__loggedIn): ?>
    <div class="ui-sidebar-foot">
      <div class="d-flex align-items-center gap-2">
        <span class="ui-avatar" style="background:var(--ui-accent-order)"><?= htmlspecialchars($__initials) ?></span>
        <div style="min-width:0;line-height:1.2">
          <div class="text-truncate" style="color:#fff;font-size:.85rem;font-weight:600"><?= htmlspecialchars($__uname) ?></div>
          <a href="/auth/logout" style="font-size:.72rem;color:var(--ui-sidebar-heading);text-decoration:none">Sign out</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </aside>

  <div class="ui-main">
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
          <?php include __DIR__ . '/_notify-bell.php'; ?>
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
              <li><a class="dropdown-item" href="/apikeys"><i class="bi bi-key me-2"></i>API Keys</a></li>
              <li><a class="dropdown-item" href="/teams"><i class="bi bi-people me-2"></i>Teams</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="/docs"><i class="bi bi-book me-2"></i>Documentation</a></li>
              <li><a class="dropdown-item" href="/help"><i class="bi bi-question-circle me-2"></i>Help</a></li>
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
