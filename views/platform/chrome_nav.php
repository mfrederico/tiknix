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
            <?php foreach ($__enabledPlugins as $__pname => $__p):
                /* Deploy is core's page (controls/Deploy.php): a project's domains and exports.
                   The Publisher sidecar is reached from it for a project still on the host. */
                $__href = $__pname === 'publisher' ? '/deploy' : '/sidecar/app/' . $__pname; ?>
              <a class="ui-nav-link<?= $__active($__href) ?>" href="<?= htmlspecialchars($__href) ?>"><i class="bi <?= htmlspecialchars($__p['icon']) ?>"></i> <?= htmlspecialchars($__p['label']) ?></a>
            <?php endforeach; ?>
          <?php else: ?>
            <a class="ui-nav-link" href="/projects"><i class="bi bi-plus-circle"></i> New Project</a>
          <?php endif; ?>
        <?php endif; ?>

        <?php
        /* The selected project's OWN pages. A project in its own container is an app with its own
           AI agents, pipelines, connections, settings and members — not these pages of Tiknix, which
           are the platform's own (under Admin). Each link goes through /projects/open, which signs
           you in there (as that app's account with your email, at its level) and lands on the page.
           A new tab: it is another site. */
        if ($__loggedIn && $__hasProject && trim((string) ($__proj->ctIp ?? '')) !== ''):
            $__projPages = [
                ['/dashboard', 'speedometer2', 'Dashboard'],
                ['/agents', 'robot', 'AI agents'],
                ['/pipelines', 'diagram-2', 'Data'],
                ['/connections', 'plug', 'Connections'],
                ['/integrations', 'diagram-3', 'Integrations'],
                ['/settings', 'gear', 'Settings'],
                ['/admin', 'people', 'Members'],
            ]; ?>
          <div class="ui-nav-heading text-truncate" title="Pages of <?= htmlspecialchars((string) ($__proj->displayName ?: $__proj->slug)) ?>, opened signed in"><?= htmlspecialchars((string) ($__proj->displayName ?: $__proj->slug)) ?></div>
          <?php foreach ($__projPages as [$__pPath, $__pIcon, $__pLabel]): ?>
            <a class="ui-nav-link" href="/projects/open?to=<?= rawurlencode($__pPath) ?>" target="_blank" rel="noopener"><i class="bi bi-<?= $__pIcon ?>"></i> <?= $__pLabel ?> <i class="bi bi-box-arrow-up-right ms-auto opacity-50" style="font-size:.7rem"></i></a>
          <?php endforeach; ?>
        <?php endif; ?>
