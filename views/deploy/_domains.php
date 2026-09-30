<?php
/**
 * Domains, for a project in its own container (lib/TenantDomains.php).
 *
 * Its main address, and each other domain pointed at it — every one a site of its own, with
 * its own admin, database and data in the app. Adding checks the domain's DNS, gets its
 * certificate, gives it its own config and database in the app, and routes it; certificates
 * renew themselves (tenant.php --renew-certs, daily). Viewing is for anyone on the project;
 * adding and removing are the owner's (the controller decides; the buttons follow).
 *
 * Vars: $instance
 */
$__iid = (int) $instance->id;
?>
<div class="card shadow-sm mb-2" id="domains-card">
  <div class="card-body">
    <div class="d-flex align-items-start gap-3">
      <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
        <i class="bi bi-globe2 fs-5 text-primary"></i>
      </div>
      <div class="flex-grow-1">
        <div class="fw-semibold">Domains</div>
        <div class="text-body-secondary small mt-1">
          Every domain here is a site of its own: its own admin, database and data, running this project's code.
          Changes you build go live on all of them.
        </div>

        <div id="dom-list" class="mt-3 small text-body-secondary">Loading…</div>

        <div id="dom-add" class="mt-3" hidden>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <input id="dom-input" class="form-control form-control-sm" style="max-width:18rem"
                   placeholder="client.com" autocomplete="off" spellcheck="false" aria-label="Domain to add">
            <button id="dom-go" class="btn btn-primary btn-sm" type="button">
              <i class="bi bi-plus-lg me-1"></i>Add domain
            </button>
          </div>
          <div class="form-text mt-1">
            First give the domain an <strong>A record</strong> pointing at <code id="dom-ip">…</code>.
            Adding checks that, gets its certificate and sets up its site — usually under a minute.
          </div>
        </div>
        <div id="dom-msg" class="small mt-2" role="status"></div>
      </div>
    </div>
  </div>
</div>
<script>
(() => {
  const iid = <?= $__iid ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const say = (cls, html) => { $('dom-msg').className = 'small mt-2 ' + cls; $('dom-msg').innerHTML = html; };
  async function call(url, fields) {
    const opts = {headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf}};
    if (fields) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/x-www-form-urlencoded'; opts.body = new URLSearchParams({id: iid, ...fields}); }
    const r = await fetch(url + (fields ? '' : '?id=' + iid), opts);
    const j = await r.json().catch(() => ({success: false, message: 'HTTP ' + r.status}));
    return j;
  }
  function render(d) {
    const rows = [`<li class="list-group-item d-flex align-items-center gap-2">
        <i class="bi bi-house text-body-secondary"></i>
        <a href="https://${esc(d.main)}" target="_blank" rel="noopener" class="fw-semibold">${esc(d.main)}</a>
        <span class="badge text-bg-light border ms-auto">main site</span></li>`];
    for (const x of d.domains) {
      const cert = x.cert === 'own' ? `certificate until ${esc(new Date(x.cert_expires).toLocaleDateString())} · renews itself` : 'certificate: wildcard';
      rows.push(`<li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-globe text-body-secondary"></i>
        <a href="https://${esc(x.domain)}" target="_blank" rel="noopener">${esc(x.domain)}</a>
        <span class="text-body-secondary">${cert}</span>
        ${d.can_manage ? `<button class="btn btn-outline-danger btn-sm ms-auto dom-rm" data-d="${esc(x.domain)}" type="button" aria-label="Remove ${esc(x.domain)}">Remove</button>` : ''}
      </li>`);
    }
    $('dom-list').innerHTML = `<ul class="list-group">${rows.join('')}</ul>`;
    $('dom-ip').textContent = d.server_ip || '(unknown)';
    $('dom-add').hidden = !d.can_manage;
    if (!d.can_manage) say('text-body-secondary', 'Only the project\'s owner can add or remove domains.');
    document.querySelectorAll('.dom-rm').forEach(b => b.addEventListener('click', () => remove(b.dataset.d)));
  }
  async function load() {
    const j = await call('/deploy/domains');
    if (!j.success) { $('dom-list').innerHTML = `<span class="text-danger">${esc(j.message)}</span>`; return; }
    render(j.data);
  }
  async function add() {
    const domain = $('dom-input').value.trim().toLowerCase();
    if (!domain) return;
    $('dom-go').disabled = true;
    say('text-body-secondary', `Checking ${esc(domain)}'s DNS, getting its certificate, setting up its site…`);
    const j = await call('/deploy/domainadd', {domain});
    $('dom-go').disabled = false;
    if (!j.success) { say('text-danger', esc(j.message)); return; }
    $('dom-input').value = '';
    say('text-success', esc(j.message) + '<br><span class="text-body-secondary">' + (j.data.steps || []).map(esc).join('<br>') + '</span>');
    load();
  }
  async function remove(domain) {
    if (!await tkConfirm(`Stop serving ${domain}? Its site stops answering; its data stays in the app.`, {okText: 'Remove', danger: true})) return;
    say('text-body-secondary', `Removing ${esc(domain)}…`);
    const j = await call('/deploy/domainremove', {domain});
    if (!j.success) { say('text-danger', esc(j.message)); return; }
    say('text-success', esc(j.message));
    load();
  }
  $('dom-go').addEventListener('click', add);
  $('dom-input').addEventListener('keydown', e => { if (e.key === 'Enter') add(); });
  load();
})();
</script>
