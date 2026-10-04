<?php
/* Control-plane chrome, slot 'navTop' (app\Chrome, wired in lib/controlplane.php): the PROJECT
   PANEL — the selected project and everything that acts on it, set apart from Tiknix's own menu
   below it. Included in the runtime header's scope; $__proj, $__projLevel and $__plugins come
   from chrome_prepare.php, and app\ProjectNav decides what one member is offered.

   It leads the sidebar because it is what the member is here to do, in the order a project is
   built: build it, connect it, check it, deploy it — then the running app's own pages.

   With no project selected the panel is still SHOWN, saying so. Removing it meant every project
   tool vanished at once with nothing to explain it, which reads as "my tools were switched off"
   rather than "you are not on a project". */
if (!$__hasProject): ?>
      <div class="ui-nav-panel ui-nav-panel-empty" role="group" aria-label="Project">
        <div class="ui-nav-panel-head"><span class="ui-nav-panel-title">No project selected</span></div>
        <div class="ui-nav-panel-meta">Building, connections and deploying all act on a project.</div>
        <a class="ui-nav-link<?= $__active('/projects') ?>" href="/projects"><i class="bi bi-grid-3x3-gap"></i> Choose a project</a>
      </div>
<?php return; endif;

$__pName   = (string) ($__proj->displayName ?: $__proj->slug);
$__inCt    = trim((string) ($__proj->ctIp ?? '')) !== '';
$__pBuild  = array_values(array_filter($__plugins, fn($__p) => $__p['scope'] === 'project' && !$__p['premium'] && $__p['name'] !== 'publisher'));
$__pDeploy = array_values(array_filter($__plugins, fn($__p) => $__p['scope'] === 'project' && !$__p['premium'] && $__p['name'] === 'publisher'));
$__pPrem   = array_values(array_filter($__plugins, fn($__p) => $__p['scope'] === 'project' && $__p['premium']));
$__pLink   = function (array $__p) use ($__active): void { ?>
        <a class="ui-nav-link<?= $__active($__p['href']) ?>" href="<?= htmlspecialchars($__p['href']) ?>"><i class="bi <?= htmlspecialchars($__p['icon']) ?>"></i> <?= htmlspecialchars($__p['label']) ?></a>
<?php };
?>
      <div class="ui-nav-panel" role="group" aria-label="Project <?= htmlspecialchars($__pName) ?>">
        <div class="ui-nav-panel-head">
          <span class="ui-nav-panel-title" title="<?= htmlspecialchars($__pName) ?>"><?= htmlspecialchars($__pName) ?></span>
          <a class="ui-nav-panel-switch" href="/projects" title="Work on another project">Switch</a>
        </div>
        <?php /* Your role ON THIS PROJECT (not your Tiknix level, which the sidebar's foot shows):
                 it is why a team member sees fewer of the app's pages than its owner. */ ?>
        <div class="ui-nav-panel-meta" title="Your role on this project"><?= htmlspecialchars(\app\ProjectNav::roleName($__projLevel)) ?></div>

        <div class="ui-nav-heading">Build</div>
        <?php if ($__pBuild) $__pLink($__pBuild[0]); ?>
        <a class="ui-nav-link<?= $__active('/connections') ?>" href="/connections"><i class="bi bi-plug"></i> Connections</a>
        <?php /* A project in its own container has Integrations as a page of its app (below);
                 one that is not (core itself) has it here. */ ?>
        <?php if (!$__inCt): ?>
        <a class="ui-nav-link<?= $__active('/integrations') ?>" href="/integrations"><i class="bi bi-diagram-3"></i> Integrations</a>
        <?php endif; ?>
        <?php foreach (array_slice($__pBuild, 1) as $__p) $__pLink($__p); ?>
        <?php foreach ($__pDeploy as $__p) $__pLink($__p); ?>

        <?php /* Premium plugins ([sidecar.<name>] premium = true): the door for a member who has
                 one, the upgrade for the project's owner when it is on offer (ProjectNav::plugins). */ ?>
        <?php if ($__pPrem): ?>
        <div class="ui-nav-heading"><i class="bi bi-gem"></i> Premium</div>
        <?php foreach ($__pPrem as $__p): ?>
          <?php if ($__p['state'] === 'upsell'): ?>
        <a class="ui-nav-link ui-nav-link-locked" href="<?= htmlspecialchars($__p['href']) ?>" title="<?= htmlspecialchars($__p['label']) ?> is a premium plugin — ask us to switch it on for <?= htmlspecialchars($__pName) ?>"><i class="bi <?= htmlspecialchars($__p['icon']) ?>"></i> <?= htmlspecialchars($__p['label']) ?> <i class="bi bi-lock-fill ui-nav-link-mark"></i></a>
          <?php else: $__pLink($__p); endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php
        /* The project's OWN pages. A project in its own container is an app with its own AI agents,
           pipelines, settings and members — not Tiknix's (those are under "Tiknix admin"). Each link
           goes through /projects/open, which signs you in there at your role on the project and
           lands on the page: only the pages that role can open are offered. A new tab: it is
           another site. */
        $__appPages = $__inCt ? \app\ProjectNav::appPages($__projLevel, \app\Feature::allows('mcp', $__mid, $__level)) : [];
        if ($__appPages): ?>
        <div class="ui-nav-heading" title="Pages of <?= htmlspecialchars($__pName) ?> itself, opened signed in, in a new tab">Open the app <i class="bi bi-box-arrow-up-right"></i></div>
        <?php foreach ($__appPages as [$__pPath, $__pIcon, $__pLabel]): ?>
        <a class="ui-nav-link" href="/projects/open?to=<?= rawurlencode($__pPath) ?>" target="_blank" rel="noopener"><i class="bi bi-<?= $__pIcon ?>"></i> <?= $__pLabel ?> <i class="bi bi-box-arrow-up-right ui-nav-link-mark"></i></a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
