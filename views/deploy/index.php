<?php
/**
 * Deploy (controls/Deploy.php): where the selected project goes live.
 * Vars: $instance, $canManage, $drivers (key => label, blurb, fields)
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
          <div class="fw-semibold">Your repository, and copies on your own servers</div>
          <div class="text-body-secondary small mt-1">
            Keep a GitHub repository in step with this app — each request opened there as a pull request, and what you push to its branch brought back in and checked — or ship the code to a server you control, over SSH.
            Keys and tokens are made and kept on the platform; the app never holds them, and no database or secret ever leaves it.
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
  // How a GitHub repository is connected: shown on the add form and on the card until the first push.
  function githubHowto(open, saved) {
    return `<details class="mt-2 border rounded px-2 py-1 bg-body-tertiary" ${open ? 'open' : ''}><summary class="small fw-semibold">How to set it up</summary>
      <ol class="small mb-1 mt-2 ps-3">
        <li class="mb-1"><strong>Create the repository on GitHub, empty</strong> — no README, licence or .gitignore. A repository GitHub already put a commit in shares no history with this app and is refused.</li>
        <li class="mb-1"><strong>Name it here</strong> as <code>owner/name</code>${saved ? ' (done)' : ''}. The branch is <code>main</code> unless you say otherwise.</li>
        <li class="mb-1"><strong>Add the deploy key</strong> — on GitHub: the repository → Settings → Deploy keys → Add deploy key. Paste the key ${saved ? 'shown below' : 'shown once this is saved'}, and tick <strong>Allow write access</strong>.</li>
        <li class="mb-1"><strong>Add the webhook</strong> — Settings → Webhooks → Add webhook: the payload URL and secret ${saved ? 'below' : 'shown once this is saved'}, content type <code>application/json</code>, <em>Just the push event</em>. This is how what you push comes back into the app.</li>
        <li class="mb-1"><strong>For a pull request per request</strong>, make a fine-grained token (GitHub → Settings → Developer settings → Fine-grained tokens) for this one repository, with <em>Contents</em> and <em>Pull requests</em> set to read and write, and paste it in the token field${saved ? ' — to change it later, remove this connection and add it again; the deploy key stays the same' : ''}. It is kept sealed on the platform and never shown again.</li>
        <li class="mb-1"><strong>Test the connection</strong>, then <strong>Push now</strong>: the first push sends the app's whole history.</li>
      </ol>
      <div class="small text-body-secondary mb-1">After that: each request is opened as a pull request and merged, so <code>main</code> always equals the live app. What you push to <code>main</code> comes back into the app — a restore point first — and is checked like a request. A conflict leaves the app as it was and puts its side on <code>tiknix/app</code> for you to merge on GitHub.</div>
    </details>`;
  }
  function render() {
    if (!TARGETS.length) { $('exp-list').innerHTML = 'No exports yet.'; return; }
    $('exp-list').innerHTML = '<ul class="list-group">' + TARGETS.map(t => {
      const st = t.status || {};
      const last = t.last_run_at ? `<span class="${t.last_ok ? 'text-success' : 'text-danger'}">${t.last_ok ? '✓' : '✗'} ${esc(when(t.last_run_at))}</span> — ${esc(t.last_message.slice(0, 160))}` : '<span class="text-body-secondary">never run</span>';
      const gh = t.driver === 'github';
      const key = st.publicKey ? (gh
        ? `<details class="mt-1" ${st.lastUsed ? '' : 'open'}><summary class="small">Deploy key — on GitHub: the repository → Settings → Deploy keys → Add deploy key, and tick <strong>Allow write access</strong></summary><textarea class="form-control form-control-sm font-monospace mt-1" rows="2" readonly aria-label="Deploy key for ${esc(t.label)}">${esc(st.publicKey)}</textarea></details>`
        : `<details class="mt-1"><summary class="small">Key to authorise on the server (append to <code>~${esc(t.config.user || '')}/.ssh/authorized_keys</code>)</summary><textarea class="form-control form-control-sm font-monospace mt-1" rows="3" readonly aria-label="Public key for ${esc(t.label)}">${esc(st.publicKey)}</textarea></details>`) : '';
      const hook = gh && st.webhookUrl ? `<details class="mt-1" ${st.pulled ? '' : 'open'}><summary class="small">Webhook — on GitHub: Settings → Webhooks → Add webhook; content type <code>application/json</code>, just the push event</summary>
          <div class="row g-2 mt-1"><div class="col-md-7"><label class="form-label small mb-0">Payload URL</label><input class="form-control form-control-sm font-monospace" readonly value="${esc(st.webhookUrl)}"></div>
          ${t.webhook_secret ? `<div class="col-md-5"><label class="form-label small mb-0">Secret</label><input class="form-control form-control-sm font-monospace" readonly value="${esc(t.webhook_secret)}"></div>` : ''}</div></details>` : '';
      const sync = gh ? `<div class="small mt-1 text-body-secondary">
          ${st.tokenSet ? '<i class="bi bi-check-circle text-success"></i> Each request opens a pull request' : '<i class="bi bi-dash-circle"></i> No token: changes are pushed without a pull request'}
          ${st.pushed ? ' · last pushed ' + esc(when(st.pushed.replace('T', ' ').slice(0, 19))) : ''}${st.pulled ? ' · last brought in ' + esc(when(st.pulled.replace('T', ' ').slice(0, 19))) : ''}
          ${st.waiting ? ' · <span class="text-warning-emphasis">changes from GitHub wait for the build to finish</span>' : ''}
          ${Object.keys(st.prs || {}).length ? '<br>Pull requests: ' + Object.entries(st.prs).reverse().map(([n, u]) => `<a href="${esc(u)}" target="_blank" rel="noopener">#${esc(n)}</a>`).join(' ') : ''}</div>` : '';
      return `<li class="list-group-item" id="exp-${t.id}">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <i class="bi bi-${gh ? 'github' : 'hdd'} text-body-secondary"></i><strong>${esc(t.label)}</strong>
          <span class="text-body-secondary">${esc(t.driver_label)} · ${esc(st.target || '')}${t.config.path ? ':' + esc(t.config.path) : ''}</span>
          ${CAN ? `<span class="ms-auto d-flex gap-1">
            <button class="btn btn-primary btn-sm exp-run" data-id="${t.id}" type="button">${gh ? 'Push now' : 'Export now'}</button>
            ${gh ? `<button class="btn btn-outline-primary btn-sm exp-pull" data-id="${t.id}" type="button">Bring in changes now</button>` : ''}
            <button class="btn btn-outline-secondary btn-sm exp-verify" data-id="${t.id}" type="button">Test connection</button>
            <button class="btn btn-outline-danger btn-sm exp-del" data-id="${t.id}" type="button" aria-label="Remove ${esc(t.label)}">Remove</button></span>` : ''}
        </div>
        <div class="small mt-1">${last}</div>${sync}${gh ? githubHowto(!st.pushed, true) : ''}${key}${hook}
      </li>`;
    }).join('') + '</ul>';
    document.querySelectorAll('.exp-run').forEach(b => b.addEventListener('click', () => run(+b.dataset.id)));
    document.querySelectorAll('.exp-verify').forEach(b => b.addEventListener('click', () => verify(+b.dataset.id)));
    document.querySelectorAll('.exp-pull').forEach(b => b.addEventListener('click', () => pull(+b.dataset.id)));
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
        : `<input id="${id}" class="form-control form-control-sm" ${f.type === 'secret' ? 'type="password" autocomplete="new-password"' : 'autocomplete="off"'} placeholder="${esc(f.placeholder || '')}" spellcheck="false">`;
      return `<div class="${f.type === 'textarea' ? 'col-12' : 'col-sm-4'}"><label class="form-label small fw-semibold mb-1" for="${id}">${esc(f.label || f.name)}${req}</label>${input}${f.help ? `<div class="form-text">${esc(f.help)}</div>` : ''}</div>`;
    }).join('');
    $('exp-form').innerHTML = `<div class="border rounded p-3">
      <div class="fw-semibold mb-1">${esc(d.label)}</div><div class="small text-body-secondary mb-2">${esc(d.blurb)}</div>${driver === 'github' ? githubHowto(true, false) : ''}
      <div class="row g-2">
        <div class="col-12"><label class="form-label small fw-semibold mb-1" for="expf-label">Name</label><input id="expf-label" class="form-control form-control-sm" placeholder="${driver === 'github' ? 'e.g. inresonance on GitHub' : 'e.g. client\'s server'}"></div>
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
  async function pull(id) {
    say('text-body-secondary', 'Bringing in what was pushed to GitHub…');
    const j = await call('/deploy/targetpull', {target: id});
    say(j.success ? 'text-success' : 'text-danger', esc(j.message).replace(/\n/g, '<br>'));
    load();
  }
  async function verify(id) {
    say('text-body-secondary', 'Connecting…');
    const j = await call('/deploy/targetverify', {target: id});
    const more = (j.data && j.data.detail) ? '<br>' + j.data.detail.map(esc).join('<br>') : '';
    say(j.success ? 'text-success' : 'text-danger', esc(j.message || (j.success ? 'Connected.' : 'Could not connect.')).replace(/\n/g, '<br>') + more);
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

  <?php if ($quickbooks > 0): /* connected through the platform's Intuit app (ConnectorRoutes) */ ?>
  <?= \app\Ui::notice(['tone' => 'info', 'title' => 'QuickBooks keeps working through Tiknix',
      'text' => 'This project has ' . $quickbooks . ' QuickBooks ' . ($quickbooks === 1 ? 'company' : 'companies') . ' connected through Tiknix. '
              . 'Exported to a server of your own, it keeps working the same way while your Tiknix account is active: Tiknix renews its access every hour and passes on its change notices. '
              . 'To leave Tiknix entirely, create your own Intuit app; put its keys in conf/quickbooks.ini ([oauth] client_id and client_secret, [webhook] verifier_token); '
              . 'register https://your-domain/connections/callback/quickbooks and https://your-domain/webhook/quickbooks on it; then connect each company once more under Connections. Your data stays as it is.']) ?>
  <?php endif; ?>

  <?php /* QA Testing (QA-TESTING-PLAN.md): the door when the member has it, the upgrade when it
           is on offer, "coming soon" until then. $qa = {state: enabled|upsell|soon, url}. */ ?>
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-4" style="letter-spacing:.06em">Before it goes live</h2>
  <div class="card shadow-sm mb-2 <?= $qa['state'] === 'upsell' ? 'border-primary' : '' ?>" id="qa-card">
    <div class="card-body">
      <div class="d-flex align-items-start gap-3">
        <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
          <i class="bi bi-clipboard2-check fs-5 text-primary"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
            QA Testing
            <?php if ($qa['state'] === 'soon'): ?>
              <span class="badge bg-body-secondary text-body-secondary border">Coming soon</span>
            <?php elseif ($qa['state'] === 'upsell'): ?>
              <span class="badge bg-primary-subtle text-primary-emphasis border">Premium</span>
            <?php endif; ?>
          </div>
          <div class="text-body-secondary small mt-1">
            Describe what should stay true about <?= htmlspecialchars($__name) ?> — a member only sees their own invoices, the booking form refuses a past date —
            and have it checked from outside the app: its pages, its API, its MCP server. A light per test, receipts for each run, and findings you can hand to the Builder.
          </div>
          <?php if ($qa['state'] === 'enabled'): ?>
            <a href="<?= htmlspecialchars($qa['url']) ?>" class="btn btn-primary btn-sm mt-3"><i class="bi bi-clipboard2-check me-1"></i>Open QA Testing</a>
          <?php elseif ($qa['state'] === 'upsell'): ?>
            <a href="<?= htmlspecialchars($qa['url']) ?>" class="btn btn-primary btn-sm mt-3"><i class="bi bi-stars me-1"></i>Ask us to switch it on</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
