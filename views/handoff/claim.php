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
  <?php $mine = (int) $h->memberRef === (int) ($member->id ?? 0); ?>
  <div class="card"><div class="card-body">
    <?php if (!$mine): ?>
      <h1 class="h4">This plan is already a project</h1>
      <p class="mb-3">It became a project on <?= htmlspecialchars((string) $h->claimedAt) ?>.</p>
      <a class="btn btn-outline-secondary" href="/projects">Your projects</a>
    <?php else: ?>
      <h1 class="h4 mb-1"><?= htmlspecialchars((string) $h->name) ?></h1>
      <p class="text-body-secondary small mb-3"><?= htmlspecialchars($project['project_url'] ?? '') ?></p>
      <?php /* Follows PlanHandoff::state() — the container setting up, PLAN.md going in, Phase 1. */ ?>
      <ol class="list-unstyled mb-3" id="handoff-steps">
        <li data-step="setting-up"><span class="hs-ic"></span> Setting up your project &mdash; about two minutes</li>
        <li data-step="plan-committed"><span class="hs-ic"></span> <code>PLAN.md</code> committed to your project</li>
        <li data-step="planning"><span class="hs-ic"></span> Phase 1 being planned</li>
      </ol>
      <div id="handoff-agent" class="alert alert-info d-none">
        <div class="mb-2"><strong>Connect an AI agent to plan Phase 1.</strong> Your project builds with its own agent &mdash; sign one in on the project's AI agents page, then start Phase 1.</div>
        <a class="btn btn-sm btn-primary" href="/projects/open?to=<?= rawurlencode('/agents') ?>" target="_blank" rel="noopener">Connect an agent</a>
        <button class="btn btn-sm btn-outline-primary ms-2" id="handoff-phaseone" type="button">Start Phase 1</button>
        <div class="small text-body-secondary mt-2" id="handoff-agent-note"></div>
      </div>
      <div id="handoff-failed" class="alert alert-danger d-none"></div>
      <a class="btn btn-primary" id="handoff-open" href="/sidecar/app/workbench?to=<?= rawurlencode('/workbench') ?>">Open the Builder</a>
      <a class="btn btn-outline-secondary ms-2" href="/projects">All projects</a>
      <style>
        #handoff-steps li { padding: .3rem 0; color: var(--bs-secondary-color); }
        #handoff-steps li.done { color: var(--bs-body-color); }
        #handoff-steps li.now { color: var(--bs-body-color); font-weight: 600; }
        .hs-ic { display: inline-block; width: 1.2rem; }
        #handoff-steps li.done .hs-ic::before { content: "✓"; color: var(--bs-success); }
        #handoff-steps li.now .hs-ic::before { content: "…"; }
      </style>
      <script>
      (function () {
        var token = <?= json_encode((string) $h->token) ?>, order = ['setting-up', 'plan-committed', 'planning'];
        var csrf = <?= json_encode(csrf_token()) ?>, timer = null;
        function show(st) {
          var p = st.progress || 'setting-up', at = order.indexOf(p === 'waiting-agent' ? 'plan-committed' : p);
          document.querySelectorAll('#handoff-steps li').forEach(function (li, i) {
            li.className = (p === 'planning' && i <= at) || i < at || (p === 'waiting-agent' && i <= at) ? 'done' : (i === at ? 'now' : '');
          });
          document.getElementById('handoff-agent').classList.toggle('d-none', p !== 'waiting-agent');
          if (p === 'waiting-agent') document.getElementById('handoff-agent-note').textContent = st.note || '';
          var f = document.getElementById('handoff-failed');
          f.classList.toggle('d-none', p !== 'failed');
          if (p === 'failed') f.textContent = 'Something went wrong: ' + (st.note || 'see the project log') + '.';
          if (p === 'planning' || p === 'failed') { clearInterval(timer); timer = null; }
        }
        function poll() {
          fetch('/handoff/state?token=' + token, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); }).then(function (d) { if (d.success) show(d.data); }).catch(function () {});
        }
        document.getElementById('handoff-phaseone').addEventListener('click', function () {
          var b = this; b.disabled = true;
          var body = new URLSearchParams({token: token, _csrf_token: csrf});
          fetch('/handoff/phaseone', {method: 'POST', body: body, headers: {'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.data) show(d.data); if (!d.success) { var f = document.getElementById('handoff-failed'); f.textContent = d.message; f.classList.remove('d-none'); } })
            .finally(function () { b.disabled = false; });
        });
        show(<?= json_encode($project) ?>);
        poll(); timer = setInterval(poll, 4000);
      })();
      </script>
    <?php endif; ?>
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
          // The project exists; this page now follows its setup, PLAN.md and Phase 1.
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
