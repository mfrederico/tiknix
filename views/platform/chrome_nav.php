<?php /* Control-plane chrome, slot 'nav' (app\Chrome, wired in lib/controlplane.php): Workspace and Build. Included in the runtime header's scope. */ ?>
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
        if ($__loggedIn) {
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
