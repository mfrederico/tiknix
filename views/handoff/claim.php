<?php
/**
 * /handoff/claim/<token> — a signed-in member decides whether the plan the Get-started
 * wizard produced becomes a project of theirs.
 *
 * Expects: $state ('offered' | 'claimed' | 'unknown'), $handoff (bean or null), $brief (array),
 *          $engines (name => label), $refusal (array|null from ProjectQuota), $project (PlanHandoff::state)
 */
$h = $handoff;
?>
<div class="container py-4" style="max-width: 760px">
<?php if ($state === 'unknown'): ?>
  <div class="card"><div class="card-body">
    <h1 class="h4">That plan link is not one we know</h1>
    <p class="text-body-secondary mb-2">It may have been mistyped, or it was never issued. Start again from the wizard.</p>
    <a class="btn btn-primary" href="https://start.tiknix.com/start">Open the Get-started wizard</a>
  </div></div>

<?php elseif ($state === 'claimed'): ?>
  <div class="card"><div class="card-body">
    <h1 class="h4">This plan is already a project</h1>
    <p class="mb-3">It became <strong><?= htmlspecialchars($project['project_slug'] ?: (string) $h->name) ?></strong> on <?= htmlspecialchars((string) $h->claimedAt) ?>.</p>
    <a class="btn btn-primary" href="/sidecar/app/workbench?to=<?= rawurlencode('/workbench') ?>">Open the Builder</a>
    <a class="btn btn-outline-secondary ms-2" href="/projects">All projects</a>
  </div></div>

<?php else: ?>
  <div class="card">
    <div class="card-body">
      <h1 class="h4 mb-1">Start a new project from this plan?</h1>
      <p class="text-body-secondary">The wizard wrote a plan for <strong><?= htmlspecialchars((string) $h->name) ?></strong>. Say yes and it becomes a project of yours with <code>PLAN.md</code> committed, ready for the Builder.</p>

      <?php if (!empty($brief)): ?>
      <div class="border rounded p-3 mb-3 bg-body-tertiary small">
        <?php foreach (['business' => 'Business', 'goal' => 'End goal', 'summary' => 'Summary', 'outcomes' => 'Outcomes', 'modules' => 'Modules'] as $k => $label):
            if (empty($brief[$k])) continue;
            $v = $brief[$k]; ?>
          <div class="mb-1"><span class="text-body-secondary"><?= $label ?>:</span>
            <?php if (is_array($v)): ?>
              <?= htmlspecialchars(implode(', ', array_map(fn($x) => is_array($x) ? (string) ($x['title'] ?? $x['name'] ?? $x['id'] ?? json_encode($x)) : (string) $x, $v))) ?>
            <?php else: ?>
              <?= htmlspecialchars((string) $v) ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <details class="mb-3">
        <summary class="small text-secondary" style="cursor:pointer">Read PLAN.md (<?= number_format(strlen((string) $h->planMd)) ?> characters)</summary>
        <pre class="border rounded p-3 mt-2 small" style="max-height: 360px; overflow: auto; white-space: pre-wrap"><?= htmlspecialchars((string) $h->planMd) ?></pre>
      </details>

      <?php if ($refusal): ?>
        <div class="alert alert-warning">
          <?= htmlspecialchars((string) ($refusal['error'] ?? 'You cannot create another project right now.')) ?>
          <?php if (!empty($refusal['action_url'])): ?>
            <a class="alert-link" href="<?= htmlspecialchars((string) $refusal['action_url']) ?>"><?= htmlspecialchars((string) ($refusal['action_label'] ?? 'Sort it out')) ?></a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <form id="handoffForm" class="row g-2 align-items-end">
          <input type="hidden" name="token" value="<?= htmlspecialchars((string) $h->token) ?>">
          <div class="col-12 col-sm-7">
            <label class="form-label small mb-0" for="handoff-name">Project name</label>
            <input id="handoff-name" name="name" class="form-control" maxlength="60" required value="<?= htmlspecialchars((string) $h->name) ?>">
          </div>
          <div class="col-8 col-sm-3">
            <label class="form-label small mb-0" for="handoff-engine">Engine</label>
            <select id="handoff-engine" name="engine" class="form-select">
              <?php foreach ($engines as $engName => $engLabel): ?>
                <option value="<?= htmlspecialchars((string) $engName) ?>"><?= htmlspecialchars((string) $engLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4 col-sm-2">
            <button class="btn btn-primary w-100" type="submit" id="handoff-build">Build it</button>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input type="hidden" name="decompose" value="0">
              <input class="form-check-input" type="checkbox" id="handoff-decompose" name="decompose" value="1" checked>
              <label class="form-check-label" for="handoff-decompose">Plan Phase 1 now — the Builder reads PLAN.md and lays out the first tasks for you to approve (recommended)</label>
            </div>
          </div>
          <div class="col-12 form-text">Your first project is free. Provisioning takes about a minute; you land in the Builder with the plan in place.</div>
        </form>
        <div id="handoff-error" class="alert alert-danger mt-3 d-none"></div>
      <?php endif; ?>
    </div>
  </div>

  <script>
  (function () {
    var form = document.getElementById('handoffForm');
    if (!form) return;
    var btn = document.getElementById('handoff-build'), err = document.getElementById('handoff-error');
    var sending = false;
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      if (sending) return;
      sending = true; btn.disabled = true;
      var html = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Creating…';
      err.classList.add('d-none');
      try {
        var body = new URLSearchParams(new FormData(form));
        body.set('_csrf_token', <?= json_encode(csrf_token()) ?>);
        var r = await fetch('/handoff/create', {method: 'POST', body: body, headers: {'X-CSRF-TOKEN': <?= json_encode(csrf_token()) ?>}});
        var d = await r.json();
        if (d.success) {
          // The project exists. A planner that did not start is said here, with the way in — not lost behind a redirect.
          if (d.data.planner && d.data.planner.indexOf('NOT started') === 0) {
            err.innerHTML = 'Project created, but the Phase 1 planner was ' + d.data.planner + ' <a class="alert-link" href="' + d.data.url + '">Open the Builder</a>';
            err.classList.remove('d-none');
            return;
          }
          window.location.href = d.data.url; return;
        }
        err.innerHTML = (d.message || 'The project was not created.') + (d.action_url ? ' <a class="alert-link" href="' + d.action_url + '">Continue</a>' : '');
        err.classList.remove('d-none');
      } catch (x) {
        err.textContent = 'The project was not created: ' + x;
        err.classList.remove('d-none');
      } finally {
        sending = false; btn.disabled = false; btn.innerHTML = html;
      }
    });
  })();
  </script>
<?php endif; ?>
</div>
