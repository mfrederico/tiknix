<?php
/**
 * Control-plane chrome, slot 'prepare' (app\Chrome, wired in lib/controlplane.php).
 * Runs in the runtime header's scope before the sidebar is drawn: resolves the member's
 * selected project, hides project-bound tools until one is chosen, adds Projects, Teams and
 * Invitations to the top of the menu (Chrome::$menuHeading), works out the member's role on
 * the project and their plugins for the project panel (chrome_navtop.php), and decides the
 * project bar.
 */

// Every sidecar plugin operates ON a project — the AI Builder edits one, the Pipeline
// Editor edits its pipelines, the Store and Explorer read one. With no project selected
// they have nothing to act on, so offering them invites the exact confusion this is
// meant to remove: you click in, get asked to pick a project, and now two places own
// that choice. Hide them until a project is chosen; /projects is the way in.
$__proj = $__loggedIn ? \app\ProjectContext::current($__mid) : null;
$__hasProject = $__proj !== null;
if (!$__hasProject) {
    foreach ($__sections as $__sec => $__items) {
        // A plugin that genuinely does not need a project opts out with
        // 'requires_project' => false in its menu entry.
        $__sections[$__sec] = array_values(array_filter($__items, fn($__it) =>
            !(isset($__it['url']) && str_starts_with((string) $__it['url'], '/sidecar/app/') && ($__it['requires_project'] ?? true))));
        if (!$__sections[$__sec]) unset($__sections[$__sec]);
    }
}

if ($__loggedIn) {
    $__have = [];
    foreach ($__sections as $__grp) foreach ($__grp as $__i) { if (isset($__i['url'])) $__have[$__i['url']] = 1; }
    // Projects is the ONLY place a project is chosen; everything else — core pages and
    // every sidecar — follows that selection. It leads the Main group because it is the
    // question all the others assume you have already answered. Teams share projects.
    $__lead = [];
    foreach ([['url' => '/projects', 'label' => 'Projects', 'icon' => 'grid-3x3-gap'],
              ['url' => '/teams',    'label' => 'Teams',    'icon' => 'people']] as $__add) {
        if (!isset($__have[$__add['url']])) $__lead[] = $__add;
    }
    // Members write to support from inside the platform (Helpdesk) instead of by email;
    // the answer comes back to their Communications.
    if (!$__isAdmin && !isset($__have['/helpdesk'])) {
        $__lead[] = ['url' => '/helpdesk', 'label' => 'Support', 'icon' => 'life-preserver'];
    }
    // Registration is closed, so an invitation is the only way anyone new gets an account —
    // a real permission, granted per member (see app\Invite). It invites to TIKNIX, so it
    // sits with the account's pages, not with the project's.
    if (\app\Feature::allows('invites', $__mid, $__level) && !isset($__have['/invites'])) {
        $__lead[] = ['url' => '/invites', 'label' => 'Invitations', 'icon' => 'envelope-plus'];
    }
    $__sections = [$__mainSec => array_merge($__lead, $__sections[$__mainSec] ?? [])] + $__sections;

    // The member's role on the selected project and the plugins they are offered — read by the
    // project panel (chrome_navtop.php). A plugin about Tiknix itself rather than the selected
    // project ([sidecar.<name>] scope = platform — Insights) goes in this menu instead.
    $__projLevel = $__hasProject ? \app\AppAccess::level($__mid, $__proj) : null;
    $__plugins   = \app\ProjectNav::plugins($__mid, $__level, $__projLevel);
    foreach ($__plugins as $__p) {
        if ($__p['scope'] !== 'platform' || isset($__have[$__p['href']])) continue;
        $__sections[$__mainSec][] = ['url' => $__p['href'], 'label' => $__p['label'], 'icon' => preg_replace('/^bi-/', '', $__p['icon'])];
    }

    // WHICH PROJECT AM I IN — the band under the top bar, decided here because its presence
    // sets --ui-projectbar-height before .ui-main opens (the sidecar iframe subtracts it).
    $__chromeBar = true;
}
