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
        <?php /* Which AI builds it (the app's own report: hourly, and at once when a provider changes). Nothing when not reported yet. */
        $__rep = json_decode((string) ($__proj->reportJson ?? ''), true) ?: [];
        if (array_key_exists('agent_ready', $__rep)):
            $__def = (string) ($__rep['default_agent'] ?? '');
            $__aiName = (string) (($__rep['agent_names'][$__def] ?? '') ?: (\Model_Agent::PRESETS[$__def]['label'] ?? $__def));
            if ($__def === 'anthropic' || $__def === 'claude') $__aiName = 'Anthropic';
            $__yours = (int) ((json_decode((string) ($__proj->yourAiJson ?? ''), true) ?: [])['connection'] ?? 0) > 0; ?>
          <?php if (!empty($__rep['agent_ready'])): ?>
            <span class="badge text-bg-success-subtle text-success-emphasis border border-success-subtle flex-none" title="The AI this project builds with"><i class="bi bi-cpu me-1"></i>AI: <?= htmlspecialchars(mb_strimwidth($__aiName, 0, 28, '…')) ?><?= $__yours ? ' (yours)' : '' ?> ✓</span>
          <?php else: ?>
            <a href="/yourai" class="badge text-bg-danger text-decoration-none flex-none" title="<?= htmlspecialchars((string) ($__rep['agent_problem'] ?? '')) ?>"><i class="bi bi-cpu me-1"></i>No AI connected — Connect</a>
          <?php endif; ?>
        <?php endif; ?>
        <nav class="ui-pb-actions" aria-label="Project actions">
          <?php /* Feature-gated like every sidecar: offering Publish without the flag lands on a plugin they cannot open. */ ?>
          <?php if (\app\Feature::isEnabled('publisher', $__pmid, $__level)): ?>
            <a href="/deploy" class="btn btn-dark btn-sm" title="Where this project goes live: its domains and exports"><i class="bi bi-rocket-takeoff"></i><span class="ui-pb-label">Deploy</span></a>
          <?php endif; ?>
          <a href="/connections" class="btn btn-outline-secondary btn-sm" title="Store &amp; service connections for this project"><i class="bi bi-plug"></i><span class="ui-pb-label">Connections</span></a>
          <a href="/teams" class="btn btn-outline-secondary btn-sm" title="Share this project with a team"><i class="bi bi-people"></i><span class="ui-pb-label">Share</span></a>
          <a href="/projects" class="btn btn-outline-primary btn-sm" title="Change project"><i class="bi bi-grid-3x3-gap"></i><span class="ui-pb-label">Change</span></a>
        </nav>
      </div>
    <?php elseif (!\app\MemberProjects::accessible((int) (\Flight::getMember()->id ?? 0))): /* none yet: the dashboard's first steps and the sidebar say so; a third "no project" is noise */ ?>
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
