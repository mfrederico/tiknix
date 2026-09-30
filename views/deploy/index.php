<?php
/**
 * Deploy (controls/Deploy.php): where the selected project goes live.
 * Vars: $instance, $inContainer, $canManage, $drivers (key => label, blurb, fields)
 */
$__iid = (int) $instance->id;
$__name = (string) ($instance->displayName ?: $instance->slug);
?>
<div class="container py-4" style="max-width:960px">
  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-rocket-takeoff fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Deploy</h1>
      <div class="text-body-secondary small">where <?= htmlspecialchars($__name) ?> goes live</div>
    </div>
  </div>

<?php if (!$inContainer): ?>
  <div class="alert alert-light border">
    <i class="bi bi-info-circle me-1"></i>
    <?= htmlspecialchars($__name) ?> still runs as a copy on the platform, not in its own container, so it deploys through the
    <a href="/sidecar/app/publisher" class="text-decoration-underline">Publisher</a>. Domains and exports move here when it moves into its own container.
  </div>
<?php else: ?>
  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-lightning-charge me-1"></i>
    This project runs in its own container: what you build <em>is</em> the live site — a merged change is live on every domain below at once.
  </div>

  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2" style="letter-spacing:.06em">Domains</h2>
  <?php include __DIR__ . '/_domains.php'; ?>

  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-4" style="letter-spacing:.06em">Export</h2>
  <div class="card shadow-sm mb-2" id="export-card">
    <div class="card-body">
      <div class="d-flex align-items-start gap-3">
        <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
          <i class="bi bi-hdd-network fs-5 text-primary"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold">Copies on your own servers</div>
          <div class="text-body-secondary small mt-1">
            Ship this project's code to a server you control, over SSH. The key is made and kept on the platform — the app never holds it — and what ships is the code as it runs now: no database, no secrets, no <code>vendor/</code>.
          </div>
          <div id="exp-list" class="mt-3 small text-body-secondary">Loading…</div>
          <?php if ($canManage): ?>
            <div class="mt-3 d-flex flex-wrap gap-2" id="exp-new">
              <?php foreach ($drivers as $__k => $__d): ?>
                <button class="btn btn-outline-primary btn-sm exp-add" type="button" data-driver="<?= htmlspecialchars($__k) ?>">
                  <i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($__d['label']) ?>
                </button>
              <?php endforeach; ?>
            </div>
            <div id="exp-form" class="mt-3"></div>
          <?php else: ?>
            <div class="small text-body-secondary mt-2">Only the project's owner can add or run exports.</div>
          <?php endif; ?>
          <div id="exp-msg" class="small mt-2" role="status"></div>
        </div>
      </div>
    </div>
  </div>

<script>
(() => {
  const iid = <?= $__iid ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;
  const DRIVERS = <?= json_encode($drivers, JSON_UNESCAPED_SLASHES) ?>;
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const say = (cls, html) => { $('exp-msg').className = 'small mt-2 ' + cls; $('exp-msg').innerHTML = html; };
  let TARGETS = [], CAN = false;
  async function call(url, fields) {
    const opts = {headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf}};
    if (fields) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/x-www-form-urlencoded'; opts.body = new URLSearchParams({id: iid, ...fields}); }
    const r = await fetch(url + (fields ? '' : '?id=' + iid), opts);
    return r.json().catch(() => ({success: false, message: 'HTTP ' + r.status}));
  }
  function when(s) { return s ? new Date(s.replace(' ', 'T')).toLocaleString() : ''; }
  function render() {
    if (!TARGETS.length) { $('exp-list').innerHTML = 'No exports yet.'; return; }
    $('exp-list').innerHTML = '<ul class="list-group">' + TARGETS.map(t => {
      const st = t.status || {};
      const last = t.last_run_at ? `<span class="${t.last_ok ? 'text-success' : 'text-danger'}">${t.last_ok ? '✓' : '✗'} ${esc(when(t.last_run_at))}</span> — ${esc(t.last_message.slice(0, 160))}` : '<span class="text-body-secondary">never run</span>';
      const key = st.publicKey ? `<details class="mt-1"><summary class="small">Key to authorise on the server (append to <code>~${esc(t.config.user || '')}/.ssh/authorized_keys</code>)</summary><textarea class="form-control form-control-sm font-monospace mt-1" rows="3" readonly aria-label="Public key for ${esc(t.label)}">${esc(st.publicKey)}</textarea></details>` : '';
      return `<li class="list-group-item" id="exp-${t.id}">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <i class="bi bi-hdd text-body-secondary"></i><strong>${esc(t.label)}</strong>
          <span class="text-body-secondary">${esc(t.driver_label)} · ${esc(st.target || '')}${t.config.path ? ':' + esc(t.config.path) : ''}</span>
          ${CAN ? `<span class="ms-auto d-flex gap-1">
            <button class="btn btn-primary btn-sm exp-run" data-id="${t.id}" type="button">Export now</button>
            <button class="btn btn-outline-secondary btn-sm exp-verify" data-id="${t.id}" type="button">Test connection</button>
            <button class="btn btn-outline-danger btn-sm exp-del" data-id="${t.id}" type="button" aria-label="Remove ${esc(t.label)}">Remove</button></span>` : ''}
        </div>
        <div class="small mt-1">${last}</div>${key}
      </li>`;
    }).join('') + '</ul>';
    document.querySelectorAll('.exp-run').forEach(b => b.addEventListener('click', () => run(+b.dataset.id)));
    document.querySelectorAll('.exp-verify').forEach(b => b.addEventListener('click', () => verify(+b.dataset.id)));
    document.querySelectorAll('.exp-del').forEach(b => b.addEventListener('click', () => del(+b.dataset.id)));
  }
  async function load() {
    const j = await call('/deploy/targets');
    if (!j.success) { $('exp-list').innerHTML = `<span class="text-danger">${esc(j.message)}</span>`; return; }
    TARGETS = j.data.targets; CAN = j.data.can_manage; render();
  }
  function form(driver) {
    const d = DRIVERS[driver];
    const fields = d.fields.map(f => {
      const id = 'expf-' + f.name, req = f.required ? '<span class="text-danger">*</span>' : '';
      const input = f.type === 'textarea'
        ? `<textarea id="${id}" class="form-control form-control-sm font-monospace" rows="2" placeholder="${esc(f.placeholder || '')}"></textarea>`
        : `<input id="${id}" class="form-control form-control-sm" placeholder="${esc(f.placeholder || '')}" autocomplete="off" spellcheck="false">`;
      return `<div class="${f.type === 'textarea' ? 'col-12' : 'col-sm-4'}"><label class="form-label small fw-semibold mb-1" for="${id}">${esc(f.label || f.name)}${req}</label>${input}${f.help ? `<div class="form-text">${esc(f.help)}</div>` : ''}</div>`;
    }).join('');
    $('exp-form').innerHTML = `<div class="border rounded p-3">
      <div class="fw-semibold mb-1">${esc(d.label)}</div><div class="small text-body-secondary mb-2">${esc(d.blurb)}</div>
      <div class="row g-2">
        <div class="col-12"><label class="form-label small fw-semibold mb-1" for="expf-label">Name</label><input id="expf-label" class="form-control form-control-sm" placeholder="e.g. client's server"></div>
        ${fields}
      </div>
      <div class="d-flex gap-2 mt-3"><button class="btn btn-primary btn-sm" id="exp-save" type="button">Save</button><button class="btn btn-outline-secondary btn-sm" id="exp-cancel" type="button">Cancel</button></div>
    </div>`;
    $('exp-cancel').addEventListener('click', () => { $('exp-form').innerHTML = ''; });
    $('exp-save').addEventListener('click', async () => {
      const body = {driver, label: $('expf-label').value.trim()};
      d.fields.forEach(f => { body[f.name] = $('expf-' + f.name).value.trim(); });
      const j = await call('/deploy/targetsave', body);
      if (!j.success) { say('text-danger', esc(j.message)); return; }
      $('exp-form').innerHTML = ''; say('text-success', esc(j.message)); await load();
    });
  }
  async function run(id) {
    say('text-body-secondary', 'Exporting…');
    const j = await call('/deploy/targetrun', {target: id});
    say(j.success ? 'text-success' : 'text-danger', esc(j.message).replace(/\n/g, '<br>'));
    load();
  }
  async function verify(id) {
    say('text-body-secondary', 'Connecting…');
    const j = await call('/deploy/targetverify', {target: id});
    say(j.success ? 'text-success' : 'text-danger', esc(j.message || (j.success ? 'Connected.' : 'Could not connect.')).replace(/\n/g, '<br>'));
    load();
  }
  async function del(id) {
    const t = TARGETS.find(x => x.id === id);
    if (!await tkConfirm(`Remove the export "${t ? t.label : id}"? Nothing on the server is touched.`, {okText: 'Remove', danger: true})) return;
    const j = await call('/deploy/targetdelete', {target: id});
    say(j.success ? 'text-success' : 'text-danger', esc(j.message)); load();
  }
  document.querySelectorAll('.exp-add').forEach(b => b.addEventListener('click', () => form(b.dataset.driver)));
  load();
})();
</script>
<?php endif; ?>
</div>
