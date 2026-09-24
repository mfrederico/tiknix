<?php
/**
 * Projects picker — big cards, three across, alphabetical, searchable.
 *
 * This is an interstitial: you come here to choose what you are working on, and every
 * other surface follows that choice. So the page does one thing, and the currently
 * selected project is unmistakable.
 *
 * Vars: $projects (array), $currentId (int)
 */
$fmt = function (string $iso): string {
    if ($iso === '') return 'never';
    $t = strtotime($iso);
    if (!$t) return 'never';
    $d = time() - $t;
    if ($d < 3600)  return max(1, (int) ($d / 60)) . 'm ago';
    if ($d < 86400) return (int) ($d / 3600) . 'h ago';
    if ($d < 2592000) return (int) ($d / 86400) . 'd ago';
    return date('j M Y', $t);
};
?>
<div class="container-fluid py-4" style="max-width:1200px">

  <?php
  /* The project you are ON, above the picker.
     The grid answers "which one?"; this answers "how is it going?" — the question you
     have once you have already chosen. Deliberately NOT one of the .proj-item cards: it
     is a status row, not a thing to pick, and it carries no destructive action (delete
     stays on the card below, in one place). */
  if (!empty($currentCard)):
      $cc = $currentCard;
      $b  = $cc['build'] ?? [];
      $pct = !empty($b['total']) ? (int) round(100 * (int) $b['done'] / (int) $b['total']) : 0;
  ?>
  <div class="card border-primary border-2 shadow-sm mb-4">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-start gap-3">

        <div class="flex-grow-1" style="min-width:16rem">
          <div class="text-uppercase text-body-secondary fw-semibold" style="font-size:.65rem;letter-spacing:.08em">
            Currently working on
          </div>
          <div class="d-flex align-items-baseline gap-2 flex-wrap">
            <span class="fs-3 fw-bold"><?= htmlspecialchars($cc['name']) ?></span>
            <code class="text-body-secondary"><?= htmlspecialchars($cc['slug']) ?></code>
          </div>

          <?php /* Tasks open on the Task Board inside the shell (Sidecar::app ?to=). */
                $__taskUrl = fn(int $id) => '/sidecar/app/workbench?to=' . rawurlencode('/workbench/view?id=' . $id); ?>
          <?php if (!empty($b['plan'])): ?>
            <div class="mt-2">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="<?= htmlspecialchars($__taskUrl((int) $b['planId'])) ?>" class="text-decoration-none d-inline-flex align-items-center gap-2" title="Open this plan">
                  <span class="badge text-bg-<?= $b['status'] === 'done' ? 'success' : ($b['status'] === 'stalled' ? 'danger' : 'primary') ?>">
                    <?= htmlspecialchars($b['status'] ?: 'draft') ?>
                  </span>
                  <span class="fw-semibold text-body"><?= htmlspecialchars($b['plan']) ?></span>
                </a>
              </div>
              <?php if (!empty($b['total'])): ?>
                <div class="d-flex align-items-center gap-2 mt-2" style="max-width:26rem">
                  <div class="progress flex-grow-1" style="height:.5rem" role="progressbar"
                       aria-label="Build progress" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar<?= $b['status'] === 'done' ? ' bg-success' : '' ?>" style="width:<?= $pct ?>%"></div>
                  </div>
                  <span class="small text-body-secondary text-nowrap">
                    <?= (int) $b['done'] ?>/<?= (int) $b['total'] ?> done
                  </span>
                </div>
              <?php endif; ?>
            </div>
          <?php elseif (empty($b['running'])): ?>
            <div class="text-body-secondary small mt-2">No builds yet — open the Builder to start one.</div>
          <?php endif; ?>
          <?php if (!empty($b['running'])): ?>
            <div class="small text-body-secondary mt-1 d-flex flex-wrap align-items-center gap-1">
              <?php if (in_array('running', array_column($b['running'], 'status'), true)): ?>
              <span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem" role="status"></span>
              <?php else: ?><i class="bi bi-pause-circle me-1" title="Waiting on you"></i><?php endif; ?>
              now:
              <?php foreach ($b['running'] as $i => $t): ?>
                <?= $i ? '<span aria-hidden="true">·</span>' : '' ?>
                <a href="<?= htmlspecialchars($__taskUrl($t['id'])) ?>" title="Open this task">
                  <?= htmlspecialchars($t['title']) ?></a><?php if ($t['status'] === 'awaiting'): ?>
                  <span class="badge text-bg-warning" title="Waiting on you">awaiting</span><?php endif; ?>
              <?php endforeach; ?>
              <?php if ($b['live'] > count($b['running'])): ?>
                <a href="/sidecar/app/workbench" class="ms-1">+<?= (int) ($b['live'] - count($b['running'])) ?> more</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>


          <div class="text-body-secondary small mt-2">
            Last change <?= htmlspecialchars($fmt($cc['lastUpdate'])) ?>
            <?php if ($cc['lastBy'] !== ''): ?> · <?= htmlspecialchars($cc['lastBy']) ?><?php endif; ?>
            <?php if ($cc['hostedDomain'] !== ''): ?>
              · <a href="https://<?= htmlspecialchars($cc['hostedDomain']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($cc['hostedDomain']) ?></a>
            <?php endif; ?>
          </div>
        </div>

        <div class="d-flex flex-column gap-2" style="min-width:12rem">
          <button class="btn btn-primary proj-pick" data-id="<?= (int) $cc['id'] ?>" type="button">
            Continue working
          </button>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach (($cc['links'] ?? []) as $l): ?>
              <a href="<?= htmlspecialchars($l['url']) ?>" class="btn btn-outline-secondary btn-sm"<?= !empty($l['external']) ? ' target="_blank" rel="noopener"' : '' ?>>
                <i class="bi bi-<?= htmlspecialchars($l['icon']) ?> me-1"></i><?= htmlspecialchars($l['label']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div>
      <h1 class="h4 fw-bold mb-0">Projects</h1>
      <div class="text-body-secondary small">
        Pick one to work on — it stays selected everywhere until you come back here.
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 ms-auto align-items-start">
      <div style="min-width:16rem">
        <input id="proj-search" class="form-control" type="search" autocomplete="off"
               placeholder="Search projects…" aria-label="Search projects">
      </div>
      <button class="btn btn-primary text-nowrap" type="button" data-bs-toggle="collapse" data-bs-target="#proj-new">
        <i class="bi bi-plus-lg me-1"></i>New Project
      </button>
    </div>
  </div>

  <?php
  /* Creation lives here, not in a sidecar. The sidecars work on whatever project is
     selected, so a "new instance" button inside one would create something you were not
     yet working on. Creating here selects it too, closing the loop. */
  ?>
  <div class="collapse mb-3" id="proj-new">
    <div class="card shadow-sm">
      <div class="card-body">
        <form id="proj-new-form" class="row g-2 align-items-end">
          <div class="col-12 col-sm-5">
            <label class="form-label small mb-0">Project name</label>
            <input id="proj-new-slug" class="form-control form-control-sm" placeholder="my-app"
                   autocomplete="off" spellcheck="false" required>
            <div class="form-text">Lowercase letters, then letters/numbers or hyphens.</div>
          </div>
          <div class="col-8 col-sm-4">
            <label class="form-label small mb-0">Engine</label>
            <select id="proj-new-engine" class="form-select form-select-sm">
              <?php foreach (\app\EngineRegistry::menu() as $engName => $engLabel): ?>
                <option value="<?= htmlspecialchars($engName) ?>"><?= htmlspecialchars($engLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4 col-sm-3">
            <button class="btn btn-primary btn-sm w-100" type="submit">Create &amp; work on it</button>
          </div>
          <div class="col-12"><div id="proj-new-msg" class="form-text"></div></div>
        </form>
      </div>
    </div>
  </div>

  <?php if (empty($projects)): ?>
    <div class="alert alert-info">You don't have any projects yet.</div>
  <?php else: ?>
    <div class="row g-3" id="proj-grid">
      <?php foreach ($projects as $p): $active = $p['id'] === $currentId; ?>
        <div class="col-12 col-md-6 col-lg-4 proj-item"
             data-search="<?= htmlspecialchars(strtolower($p['name'] . ' ' . $p['slug'] . ' ' . $p['hostedDomain'])) ?>">
          <div class="card h-100 shadow-sm <?= $active ? 'border-primary border-2' : '' ?>">
            <div class="card-body d-flex flex-column">

              <div class="d-flex align-items-start gap-2 mb-2">
                <div class="flex-grow-1">
                  <div class="fw-semibold fs-5"><?= htmlspecialchars($p['name']) ?></div>
                  <div class="text-body-secondary small"><code><?= htmlspecialchars($p['slug']) ?></code></div>
                </div>
                <?php if ($active): ?>
                  <span class="badge bg-primary">Working on</span>
                <?php elseif (!$p['owned']): ?>
                  <span class="badge bg-secondary-subtle text-secondary">Shared</span>
                <?php endif; ?>
              </div>

              <dl class="row row-cols-1 g-0 small mb-3 mt-1">
                <div class="d-flex justify-content-between border-top py-1">
                  <dt class="fw-normal text-body-secondary">Last update</dt>
                  <dd class="mb-0 text-end">
                    <?= htmlspecialchars($fmt($p['lastUpdate'])) ?>
                    <?php if ($p['lastBy'] !== ''): ?>
                      <span class="text-body-secondary">· <?= htmlspecialchars($p['lastBy']) ?></span>
                    <?php endif; ?>
                  </dd>
                </div>
                <div class="d-flex justify-content-between border-top py-1">
                  <dt class="fw-normal text-body-secondary">Published</dt>
                  <dd class="mb-0 text-end">
                    <?php if ($p['hostedDomain'] !== ''): ?>
                      <a href="https://<?= htmlspecialchars($p['hostedDomain']) ?>" target="_blank" rel="noopener">
                        <?= htmlspecialchars($p['hostedDomain']) ?>
                      </a>
                    <?php else: ?>
                      <span class="text-body-secondary">not published</span>
                    <?php endif; ?>
                  </dd>
                </div>
                <?php if (($p['isolation'] ?? '') !== ''): ?>
                <div class="d-flex justify-content-between border-top py-1">
                  <dt class="fw-normal text-body-secondary">Isolation</dt>
                  <dd class="mb-0 text-end">
                    <?php if ($p['isolation'] === 'active'): ?>
                      <span class="text-success"><i class="bi bi-shield-check"></i> Isolated</span>
                    <?php elseif ($p['isolation'] === 'pending'): ?>
                      <span class="text-body-secondary" title="Your project is already live on the shared pool; its own isolated environment is being set up (usually seconds).">
                        <span class="spinner-border spinner-border-sm" style="width:.6rem;height:.6rem" role="status"></span>
                        Live · finishing setup…
                      </span>
                    <?php else: /* failed — still live, maintenance will retry */ ?>
                      <span class="text-warning-emphasis" title="Your project is live and usable. Its isolated environment is delayed and will be retried automatically — no action needed.">
                        <i class="bi bi-shield-exclamation"></i> Live · isolation delayed
                      </span>
                    <?php endif; ?>
                  </dd>
                </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between border-top border-bottom py-1">
                  <dt class="fw-normal text-body-secondary">Team</dt>
                  <dd class="mb-0 text-end">
                    <?php if (empty($p['teams'])): ?>
                      <span class="text-body-secondary">just you</span>
                    <?php else: foreach ($p['teams'] as $t): ?>
                      <a href="/teams/view?id=<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></a>
                      <span class="text-body-secondary">(<?= (int) $t['members'] ?>)</span>
                    <?php endforeach; endif; ?>
                  </dd>
                </div>
              </dl>

              <?php if ($p['lastSubject'] !== ''): ?>
                <div class="text-body-secondary small fst-italic mb-3 text-truncate"
                     title="<?= htmlspecialchars($p['lastSubject']) ?>">
                  “<?= htmlspecialchars($p['lastSubject']) ?>”
                </div>
              <?php endif; ?>

              <div class="d-flex gap-2 mt-auto">
                <button class="btn <?= $active ? 'btn-outline-primary' : 'btn-primary' ?> flex-grow-1 proj-pick"
                        data-id="<?= (int) $p['id'] ?>" type="button">
                  <?= $active ? 'Continue' : 'Work on this' ?>
                </button>
                <?php if (!empty($p['deletable'])): ?>
                  <?php /* Deliberately small and quiet next to the thing you came here to
                           do. It opens a dialogue rather than acting — see the modal. */ ?>
                  <button class="btn btn-outline-danger proj-del" type="button"
                          data-id="<?= (int) $p['id'] ?>"
                          data-name="<?= htmlspecialchars($p['name']) ?>"
                          data-confirm="<?= htmlspecialchars($p['confirm']) ?>"
                          aria-label="Delete <?= htmlspecialchars($p['name']) ?>"
                          title="Delete this project">
                    <i class="bi bi-trash"></i>
                  </button>
                <?php endif; ?>
              </div>

            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div id="proj-empty" class="text-body-secondary small mt-3" hidden>No projects match that search.</div>
  <?php endif; ?>
</div>

<?php
/* Deleting a project is irreversible and there is no undo button anywhere, so the
   confirmation is the domain typed back exactly — the same phrase the service checks,
   handed to us by it. A dialogue you can dismiss with Escape, and a button that stays
   disabled until the words match, are what stand between a stray click and an erased
   app. It says plainly what survives (the archive) and what does not. */
?>
<div class="modal fade" id="proj-del-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Delete project</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">This permanently deletes <code id="proj-del-domain"></code>. It:</p>
        <ul class="small mb-3">
          <li>stops the jailed session and removes its connectors (a linked repo is kept)</li>
          <li>archives the folder to <code>public/&lt;slug&gt;.zip</code>, with config secrets stripped</li>
          <li>removes everything else, and the project itself</li>
        </ul>
        <label class="form-label small mb-1" for="proj-del-input">Type <code id="proj-del-domain2"></code> to confirm:</label>
        <input id="proj-del-input" class="form-control" autocomplete="off" spellcheck="false">
        <div id="proj-del-msg" class="small text-danger mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button id="proj-del-confirm" class="btn btn-danger" type="button" disabled>
          <i class="bi bi-trash me-1"></i>Delete permanently
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const csrf = <?= json_encode(csrf_token()) ?>;
  // Picking a project leads INTO the work, not back to the dashboard. Falls back to the
  // dashboard for a member without the build sidecar — see Projects::index.
  const workUrl = <?= json_encode($workUrl ?? '/dashboard') ?>;

  // Client-side filter: the list is per-member and small, so a round trip per keystroke
  // would cost more than it saves.
  const search = document.getElementById('proj-search'),
        items  = Array.prototype.slice.call(document.querySelectorAll('.proj-item')),
        empty  = document.getElementById('proj-empty');
  if (search) {
    search.addEventListener('input', function () {
      const q = search.value.trim().toLowerCase();
      let shown = 0;
      items.forEach(function (el) {
        const hit = !q || el.dataset.search.indexOf(q) !== -1;
        el.hidden = !hit;
        if (hit) shown++;
      });
      if (empty) empty.hidden = shown !== 0;
    });
  }

  const newForm = document.getElementById('proj-new-form');
  if (newForm) {
    newForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const slug = document.getElementById('proj-new-slug').value.trim().toLowerCase(),
            eng  = document.getElementById('proj-new-engine').value,
            msg  = document.getElementById('proj-new-msg'),
            btn  = newForm.querySelector('button[type=submit]');
      if (!slug) return;
      btn.disabled = true;
      msg.className = 'form-text text-body-secondary';
      msg.textContent = 'Provisioning… this can take a minute.';
      fetch('/projects/create', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, slug: slug, engine: eng}).toString()
      }).then(r => r.json()).then(function (j) {
        // Created AND selected, so go straight to work rather than back to a list — the
        // button says "Create & work on it", and it should mean it —
        // unless it came out incomplete, in which case stop and say what is wrong. Being
        // whisked to the dashboard is how a half-made project goes unnoticed until the
        // first publish fails.
        if (j && j.success && j.data && j.data.warning) {
          btn.disabled = false;
          msg.className = 'form-text text-danger';
          msg.textContent = j.data.warning;
          return;
        }
        if (j && j.success) { window.location.href = workUrl; return; }
        btn.disabled = false;
        msg.className = 'form-text text-danger';
        msg.textContent = (j && j.message) || 'Could not create the project.';
        // A refusal that has somewhere to send you gets a link, not an instruction to go
        // and find one. Built as DOM nodes with textContent/href rather than innerHTML —
        // this is server-supplied text and must never be parsed as markup.
        if (j && j.action_url) {
          var a = document.createElement('a');
          a.href = j.action_url;
          a.textContent = j.action_label || 'Continue';
          a.className = 'ms-1 fw-semibold';
          if (j.action_blank) { a.target = '_blank'; a.rel = 'noopener'; }
          msg.appendChild(document.createTextNode(' '));
          msg.appendChild(a);
        }
      }).catch(function () {
        btn.disabled = false;
        msg.className = 'form-text text-danger';
        msg.textContent = 'Network error.';
      });
    });
  }

  document.querySelectorAll('.proj-pick').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      fetch('/projects/select', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, id: btn.dataset.id}).toString()
      }).then(r => r.json()).then(function (j) {
        // Choosing a project is a means, not an end — go where the work is.
        if (j && j.success) window.location.href = workUrl;
        else { btn.disabled = false; tkAlert((j && j.message) || 'Could not select that project.', {type: 'error'}); }
      }).catch(function () { btn.disabled = false; tkAlert('Network error.', {type: 'error'}); });
    });
  });

  // --- delete -------------------------------------------------------------
  // The confirm button unlocks only on an exact match, so the dialogue cannot be
  // clicked through by reflex — you have to have read which project this is.
  var delModalEl = document.getElementById('proj-del-modal');
  if (delModalEl) {
    // Built on first click, not now: this script runs in the body and bootstrap's bundle
    // is loaded after it, so constructing the modal here throws before anything renders.
    var delModal  = null,
        modalFor  = function () {
          if (!delModal) delModal = bootstrap.Modal.getOrCreateInstance(delModalEl);
          return delModal;
        },
        delInput  = document.getElementById('proj-del-input'),
        delBtn    = document.getElementById('proj-del-confirm'),
        delMsg    = document.getElementById('proj-del-msg'),
        target    = null;

    document.querySelectorAll('.proj-del').forEach(function (btn) {
      btn.addEventListener('click', function () {
        target = { id: btn.dataset.id, name: btn.dataset.name, confirm: btn.dataset.confirm };
        document.getElementById('proj-del-domain').textContent  = target.confirm;
        document.getElementById('proj-del-domain2').textContent = target.confirm;
        delInput.value = '';
        delMsg.textContent = '';
        delBtn.disabled = true;
        modalFor().show();
        delModalEl.addEventListener('shown.bs.modal', function once() {
          delInput.focus();
          delModalEl.removeEventListener('shown.bs.modal', once);
        });
      });
    });

    delInput.addEventListener('input', function () {
      delBtn.disabled = !target || delInput.value.trim() !== target.confirm;
    });

    delBtn.addEventListener('click', function () {
      if (!target) return;
      delBtn.disabled = true;
      delMsg.className = 'small text-body-secondary mt-2';
      delMsg.textContent = 'Deleting…';
      fetch('/projects/delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, id: target.id, confirm: delInput.value.trim()}).toString()
      }).then(r => r.json()).then(function (j) {
        // Back to the picker, which is now the truth about what you have.
        if (j && j.success) { window.location.href = '/projects'; return; }
        delBtn.disabled = false;
        delMsg.className = 'small text-danger mt-2';
        delMsg.textContent = (j && j.message) || 'Could not delete the project.';
      }).catch(function () {
        delBtn.disabled = false;
        delMsg.className = 'small text-danger mt-2';
        delMsg.textContent = 'Network error.';
      });
    });
  }
})();
</script>
