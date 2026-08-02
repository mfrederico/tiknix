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
if ($__loggedIn && $member) {
    $__uname = (string)($member['username'] ?? 'User');
    // No defaults past this point: inside this branch the member is signed in, so a
    // missing id or level is a broken session and should say so rather than be guessed.
    $__mid   = member_id($member, 'layout header');
    $__level = (int)$member['level'];
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

// Every sidecar plugin operates ON a project — the AI Builder edits one, the Pipeline
// Editor edits its pipelines, the Store and Explorer read one. With no project selected
// they have nothing to act on, so offering them invites the exact confusion this is
// meant to remove: you click in, get asked to pick a project, and now two places own
// that choice. Hide them until a project is chosen; /projects is the way in.
$__hasProject = false;
if (!empty($__loggedIn) && class_exists('\app\ProjectContext')) {
    $__memberId   = (int) (\Flight::getMember()->id ?? 0);
    $__hasProject = $__memberId > 0 && \app\ProjectContext::current($__memberId) !== null;
}

// Group the dynamic menu by its optional 'section' (default "Main").
$__sections = [];
foreach (($menu ?? []) as $__it) {
    if (isset($__it['url']) && isset($__skip[$__it['url']])) continue;
    // A future plugin that genuinely does not need a project can opt out with
    // 'requires_project' => false in its menu entry.
    if (!$__hasProject
        && isset($__it['url']) && str_starts_with((string) $__it['url'], '/sidecar/app/')
        && ($__it['requires_project'] ?? true)) continue;
    $__sections[$__it['section'] ?? 'Main'][] = $__it;
}
// Surface Teams + Communications in the "Main" group for logged-in users. Teams was
// only reachable from the topbar dropdown; Communications moved here out of Workspace.
// Dedupe against whatever the dynamic menu already provides so nothing doubles up.
if ($__loggedIn) {
    $__have = [];
    foreach ($__sections as $__grp) foreach ($__grp as $__i) { if (isset($__i['url'])) $__have[$__i['url']] = 1; }
    // Projects is the ONLY place a project is chosen; everything else — core pages and
    // every sidecar — follows that selection. It leads the Main group because it is the
    // question all the others assume you have already answered.
    //
    // It is also CONTROL-PLANE only, for the same reason the dashboard hides it: a
    // finished app running on its own domain has no projects to pick, so the link would
    // lead somewhere that means nothing there. Teams and Communications stay — those are
    // ordinary features every clone really has.
    $__main = [];
    if (builder_tools_enabled()) {
        $__main[] = ['url' => '/projects', 'label' => 'Projects', 'icon' => 'grid-3x3-gap'];
    }
    $__main[] = ['url' => '/teams',          'label' => 'Teams',          'icon' => 'people'];
    $__main[] = ['url' => '/communications', 'label' => 'Communications', 'icon' => 'chat-left-dots'];

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
          <?php /* AI Projects + AI Builder moved to the workbench.tiknix sidecar — listed under Plugins below (Feature-gated). */ ?>
          <a class="ui-nav-link<?= $__active('/connections') ?>" href="/connections"><i class="bi bi-plug"></i> Connections</a>
          <a class="ui-nav-link<?= $__active('/integrations') ?>" href="/integrations"><i class="bi bi-diagram-3"></i> Integrations</a>
          <?php /* Agent Setup is MCP configuration, so it follows the mcp GRANT rather than
                   the admin heading it used to sit under — a granted member is not an admin,
                   and filing their one tool under "Admin" says otherwise. Admins still see
                   it: allows() is true for them without any switch being set. */ ?>
          <?php if (\app\Feature::allows('mcp', $__mid, $__level)): ?>
            <a class="ui-nav-link<?= $__active('/agentsetup') ?>" href="/agentsetup"><i class="bi bi-sliders"></i> Agent Setup</a>
          <?php endif; ?>
          <?php /* Registration is closed, so an invitation is the only way anyone new gets
                   an account — a real permission, granted per member (see app\Invite). */ ?>
          <?php if (\app\Feature::allows('invites', $__mid, $__level)): ?>
            <a class="ui-nav-link<?= $__active('/invites') ?>" href="/invites"><i class="bi bi-envelope-plus"></i> Invitations</a>
          <?php endif; ?>
        <?php endif; ?>

        <?php /* Ecommerce moved to the shop.tiknix sidecar — listed via the plugin nav below. */ ?>
        <?php
        /* These act ON the selected project, so they cannot be USED until one is chosen —
           otherwise you click in, get asked to pick a project, and two places own that
           choice. /projects is the way in, and it is also where a project gets made
           (/projects/create is a POST endpoint, not a page).

           But the section is still SHOWN. Removing it outright meant five nav items
           vanished at once with nothing to explain it, which reads as "my tools were
           switched off" rather than "you are not on a project". So the heading stays and
           offers the one thing a new account actually needs to do first. */
        $__enabledPlugins = [];
        if ($__loggedIn && class_exists('\\app\\Sidecar\\Registry')) {
            foreach (\app\Sidecar\Registry::launchable() as $__pname => $__p) {
                if (\app\Feature::isEnabled($__p['feature'], $__mid, $__level)) {
                    $__enabledPlugins[$__pname] = $__p;
                }
            }
        }
        ?>
        <?php if ($__enabledPlugins): ?>
          <div class="ui-nav-heading">Build</div>
          <?php if ($__hasProject): ?>
            <?php foreach ($__enabledPlugins as $__pname => $__p): ?>
              <a class="ui-nav-link<?= $__active('/sidecar/app/' . $__pname) ?>" href="/sidecar/app/<?= htmlspecialchars($__pname) ?>"><i class="bi <?= htmlspecialchars($__p['icon']) ?>"></i> <?= htmlspecialchars($__p['label']) ?></a>
            <?php endforeach; ?>
          <?php else: ?>
            <a class="ui-nav-link" href="/projects"><i class="bi bi-plus-circle"></i> New Project</a>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($__isAdmin): ?>
          <div class="ui-nav-heading">Admin</div>
          <?php if (!builder_tools_enabled()): /* inside an instance: read-only "what am I wired to" views */ ?>
            <a class="ui-nav-link<?= $__active('/connections') ?>" href="/connections"><i class="bi bi-plug"></i> Connections</a>
            <a class="ui-nav-link<?= $__active('/integrations') ?>" href="/integrations"><i class="bi bi-diagram-3"></i> Integrations</a>
          <?php endif; ?>
          <a class="ui-nav-link<?= $__active('/admin') ?>" href="/admin"><i class="bi bi-shield-lock"></i> Admin</a>
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

  <div class="ui-main">
    <header class="ui-topbar">
      <button class="ui-btn-icon d-lg-none" type="button" onclick="uiToggleSidebar(true)" aria-label="Open menu"><i class="bi bi-list"></i></button>
      <div class="ui-topbar-title">
        <span class="ui-eyebrow"><?= htmlspecialchars($topbar_eyebrow ?? ($site_name ?? 'Tiknix')) ?></span>
        <strong><?= htmlspecialchars($title ?? 'App') ?></strong>
      </div>

      <?php
      /* WHICH PROJECT AM I IN. Present on every page, because the answer used to be
         inferable only from whichever tool you happened to be looking at — and when a
         surface guessed differently from the one before it, you had no way to notice.
         Clicking it goes to the picker, the single place the choice is made. */
      if ($__hasProject):
          $__proj = \app\ProjectContext::current((int) (\Flight::getMember()->id ?? 0));
          if ($__proj):
      ?>
        <?php
        /* The AI Builder grew this chip because it was the only surface that knew which
           instance you were in. Now the shell knows, so it lives here — one chip, always
           in the same place, instead of one per plugin that can disagree with the others.

           This links to the WORKING instance — the thing you are building, always at
           <slug>.<host> — never to wherever it is published. A published URL is a
           property of a publish target, not of the project: it can differ per target,
           change when a target is reconfigured, or not exist yet. Sending you there from
           the chip would mean the one control that says "you are working on X" points
           somewhere X is not being edited. */
        $__phost   = app_host() ?: 'tiknix.com';
        $__pdomain = $__proj->slug . '.' . $__phost;
        ?>
        <div class="ui-project-chip d-flex align-items-center gap-2 px-3 py-1 rounded-3 bg-primary-subtle ms-3 flex-wrap">
          <i class="bi bi-hdd-network-fill text-primary"></i>
          <span class="lh-sm">
            <span class="d-block text-uppercase text-body-secondary fw-semibold" style="font-size:.6rem;letter-spacing:.06em">Working on</span>
            <span class="d-block fw-bold" style="font-size:.85rem">
              <a href="https://<?= htmlspecialchars($__pdomain) ?>" target="_blank" rel="noopener"
                 class="link-body-emphasis text-decoration-none"
                 title="Open the working instance — <?= htmlspecialchars($__pdomain) ?>"><?= htmlspecialchars($__proj->displayName ?: $__proj->slug) ?><i class="bi bi-box-arrow-up-right ms-1 small opacity-75"></i></a>
              <?php if (!empty($__proj->isDefault)): ?><span class="badge text-bg-warning" style="font-size:.62rem">default · core</span><?php endif; ?>
            </span>
          </span>
          <?php
          /* The actions that belong to the PROJECT, not to any one page. They lived on
             the AI Builder because that was the only surface that knew which instance you
             were in — so publishing meant navigating to a build tool first. In the shell
             they follow you everywhere, which is the point of the chip.

             Publish is a destination now, not an inline action: the Publisher sidecar owns
             where and how a project goes live, so this links there rather than firing a
             deploy from a dropdown. */
          ?>
          <span class="vr mx-1 d-none d-sm-block"></span>
          <?php
          /* Feature-gated like every other sidecar: offering Publish to someone without
             the flag would land them on a plugin they cannot launch.

             If it is missing after granting the flag, the grant is real but the SESSION
             cache is stale — Feature::stored() caches per member for the session, and
             only Feature::setEnabled() busts it. Re-save the member (or re-login). */
          if (\app\Feature::isEnabled('publisher', (int) (\Flight::getMember()->id ?? 0), $__level)): ?>
            <a href="/sidecar/app/publisher" class="btn btn-dark btn-sm py-0 px-2" style="font-size:.72rem"
               title="Where and how this project goes live"><i class="bi bi-cloud-upload me-1"></i>Publish</a>
          <?php endif; ?>
          <a href="/connections" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.72rem"
             title="Store &amp; service connections for this project"><i class="bi bi-plug me-1"></i>Connections</a>
          <a href="/teams" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.72rem"
             title="Share this project with a team"><i class="bi bi-people me-1"></i>Share</a>
          <a href="/projects" class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle text-decoration-none"
             style="font-size:.62rem" title="Change project"><i class="bi bi-grid-3x3-gap me-1"></i>Change</a>
        </div>
      <?php endif; ?>
      <?php elseif ($__loggedIn && builder_tools_enabled()): ?>
        <?php
        /* No project selected. Say so, here, where the chip normally is — because
           everything that works on a project quietly does nothing until one is chosen,
           and an empty space explains none of that. This is the state a new account
           starts in, and the state you land in after deleting the project you were on. */
        ?>
        <a href="/projects"
           class="ui-project-chip d-flex align-items-center gap-2 px-3 py-1 rounded-3 bg-warning-subtle ms-3 text-decoration-none">
          <i class="bi bi-signpost-split-fill text-warning-emphasis"></i>
          <span class="lh-sm">
            <span class="d-block text-uppercase text-body-secondary fw-semibold" style="font-size:.6rem;letter-spacing:.06em">No project selected</span>
            <span class="d-block fw-bold link-body-emphasis" style="font-size:.85rem">Create or choose one to begin</span>
          </span>
        </a>
      <?php endif; ?>

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
              <?php /* Shown to whoever has been GRANTED mcp (admins always). An API key
                       authenticates MCP tools/call against this instance, so issuing one is
                       programmatic access — see controls/Apikeys. Hidden rather than
                       shown-and-refused: a link that always 403s is a worse error message. */ ?>
              <?php if (\app\Feature::allows('mcp', $__mid, $__level)): ?>
                <li><a class="dropdown-item" href="/apikeys"><i class="bi bi-key me-2"></i>API Keys</a></li>
              <?php endif; ?>
              <li><a class="dropdown-item" href="/teams"><i class="bi bi-people me-2"></i>Teams</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="/docs"><i class="bi bi-book me-2"></i>Documentation</a></li>
              <li><a class="dropdown-item" href="/help"><i class="bi bi-question-circle me-2"></i>Help</a></li>
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
