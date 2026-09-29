<?php /* Control-plane chrome, slot 'bar' (app\Chrome, wired in lib/controlplane.php): the project bar — the selected project, or the prompt to choose one. Included in the runtime header's scope. */ ?>
<?php if (!$__loggedIn) return; /* guests are shown no project */ ?>
    <?php if ($__proj):
        /* Links to the WORKING instance — the thing you are building — never to wherever it
           is published: a published URL belongs to a publish target, not to the project. */
        $__purl  = $__proj->box()->url();
        $__pname = $__proj->displayName ?: $__proj->slug;
        $__pmid  = (int) (\Flight::getMember()->id ?? 0);
    ?>
      <div class="ui-projectbar" role="region" aria-label="Current project">
        <i class="bi bi-hdd-network-fill text-primary flex-none"></i>
        <span class="ui-pb-eyebrow d-none d-sm-inline">Working on</span>
        <a href="<?= htmlspecialchars($__purl) ?>" target="_blank" rel="noopener" class="ui-pb-name link-body-emphasis text-decoration-none"
           title="Open the working instance — <?= htmlspecialchars($__purl) ?>"><?= htmlspecialchars($__pname) ?><i class="bi bi-box-arrow-up-right ms-1 small opacity-75"></i></a>
        <?php if (!empty($__proj->isDefault)): ?><span class="badge text-bg-warning flex-none" style="font-size:.62rem">default · core</span><?php endif; ?>
        <nav class="ui-pb-actions" aria-label="Project actions">
          <?php /* Feature-gated like every sidecar: offering Publish without the flag lands on a plugin they cannot open. */ ?>
          <?php if (\app\Feature::isEnabled('publisher', $__pmid, $__level)): ?>
            <a href="/sidecar/app/publisher" class="btn btn-dark btn-sm" title="Where and how this project goes live"><i class="bi bi-cloud-upload"></i><span class="ui-pb-label">Publish</span></a>
          <?php endif; ?>
          <a href="/connections" class="btn btn-outline-secondary btn-sm" title="Store &amp; service connections for this project"><i class="bi bi-plug"></i><span class="ui-pb-label">Connections</span></a>
          <a href="/teams" class="btn btn-outline-secondary btn-sm" title="Share this project with a team"><i class="bi bi-people"></i><span class="ui-pb-label">Share</span></a>
          <a href="/projects" class="btn btn-outline-primary btn-sm" title="Change project"><i class="bi bi-grid-3x3-gap"></i><span class="ui-pb-label">Change</span></a>
        </nav>
      </div>
    <?php else:
        /* No project selected. Said here, where the project normally is — everything that
           works on a project quietly does nothing until one is chosen. The state a new
           account starts in, and the one after deleting the project you were on. */ ?>
      <a href="/projects" class="ui-projectbar ui-projectbar-empty text-decoration-none">
        <i class="bi bi-signpost-split-fill text-warning-emphasis flex-none"></i>
        <span class="ui-pb-name link-body-emphasis">No project selected — create or choose one to begin</span>
        <span class="ui-pb-actions"><span class="btn btn-warning btn-sm"><i class="bi bi-grid-3x3-gap"></i><span class="ui-pb-label">Projects</span></span></span>
      </a>
    <?php endif; ?>
