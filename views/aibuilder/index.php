<?php
/**
 * AI Builder view — instance picker + jailed Terminal/Chat for the selected one.
 *
 * Vars: $instances (Instance beans), $selected (bean|null), $ab_sub, $ab_token,
 *       $ab_wspath, $ab_chat_wspath, $ab_hasInstance, $csrf
 */
$csrfTok = csrf_token();
$selId   = $selected ? (int)$selected->id : 0;
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.min.css">
<style>
  #ab-terminal { height: 70vh; width: 100%; background:#1e1e1e; border-radius:.375rem; padding:8px; }
  #ab-chat-log { height: 60vh; overflow-y:auto; background:#1e1e1e; border-radius:.375rem; padding:1rem; }
  .ab-msg { margin-bottom:.75rem; white-space:pre-wrap; word-break:break-word; }
  .ab-msg .bubble { display:inline-block; padding:.5rem .75rem; border-radius:.5rem; max-width:90%; }
  .ab-msg.user { text-align:right; }
  .ab-msg.user .bubble { background:#0d6efd; color:#fff; }
  .ab-msg.assistant .bubble { background:#2b3035; color:#e9ecef; }
  .ab-tool { font-size:.75rem; color:#0dcaf0; margin:.25rem 0; }
  .ab-inst-item.active { border-color:#0d6efd; }
</style>

<div class="container py-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div>
      <h1 class="h3 fw-bold mb-0"><i class="bi bi-robot me-2"></i>AI Builder</h1>
      <p class="text-body-secondary mb-0">Build and customize software with AI. Every instance is sandboxed — roll back any change.</p>
    </div>
    <?php if ($ab_hasInstance): ?>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge text-bg-secondary align-self-center"><i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($selected->slug) ?>.tiknix</span>
      <button id="ab-checkpoint" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bookmark-plus me-1"></i>Checkpoint</button>
      <button id="ab-rollback" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Roll back</button>
      <span id="ab-status" class="badge text-bg-secondary align-self-center">Connecting…</span>
    </div>
    <?php endif; ?>
  </div>

  <div class="row g-3">
    <!-- Instance picker -->
    <div class="col-lg-3">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Your Instances</span>
          <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#ab-new-form"><i class="bi bi-plus-lg"></i></button>
        </div>
        <div class="collapse <?= empty($instances) ? 'show' : '' ?>" id="ab-new-form">
          <div class="card-body border-bottom">
            <form id="ab-create-form">
              <div class="mb-2">
                <label class="form-label small mb-1">Name (slug)</label>
                <input name="slug" class="form-control form-control-sm" placeholder="myapp" pattern="[a-z][a-z0-9]{1,49}" required>
                <div class="form-text">Lowercase letters/numbers, becomes <code>&lt;slug&gt;.tiknix</code>.</div>
              </div>
              <div class="mb-2">
                <label class="form-label small mb-1">Engine</label>
                <select name="engine" class="form-select form-select-sm">
                  <option value="claude">Claude Code</option>
                  <option value="qwen">qwen-code</option>
                </select>
              </div>
              <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-hammer me-1"></i>Create instance</button>
              <div id="ab-create-msg" class="form-text"></div>
            </form>
          </div>
        </div>
        <div class="list-group list-group-flush">
          <?php if (empty($instances)): ?>
            <div class="list-group-item text-body-secondary small">No instances yet. Create one above.</div>
          <?php else: foreach ($instances as $inst): ?>
            <a href="/aibuilder/open/<?= (int)$inst->id ?>"
               class="list-group-item list-group-item-action ab-inst-item <?= ($selId === (int)$inst->id) ? 'active' : '' ?>">
              <div class="d-flex justify-content-between">
                <span class="fw-semibold"><?= htmlspecialchars($inst->displayName ?: $inst->slug) ?></span>
                <span class="badge text-bg-dark"><?= htmlspecialchars($inst->engine) ?></span>
              </div>
              <small class="<?= ($selId === (int)$inst->id) ? '' : 'text-body-secondary' ?>"><?= htmlspecialchars($inst->slug) ?>.tiknix</small>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- Builder surface -->
    <div class="col-lg-9">
      <?php if (!$ab_hasInstance): ?>
        <div class="card shadow-sm"><div class="card-body text-center text-body-secondary py-5">
          <i class="bi bi-arrow-left-circle fs-1 d-block mb-3"></i>
          Select an instance to open its sandboxed Terminal and Chat, or create a new one.
        </div></div>
      <?php else: ?>
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-chat" type="button"><i class="bi bi-chat-dots me-1"></i>Chat</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-term" type="button"><i class="bi bi-terminal me-1"></i>Terminal</button></li>
        </ul>
        <div class="tab-content border border-top-0 rounded-bottom p-2 bg-body-tertiary">
          <!-- Chat -->
          <div class="tab-pane fade show active" id="tab-chat" role="tabpanel">
            <div id="ab-chat-log"></div>
            <form id="ab-chat-form" class="d-flex gap-2 mt-2">
              <input id="ab-chat-input" class="form-control" placeholder="Ask the AI to build or change something…" autocomplete="off">
              <button class="btn btn-primary" type="submit"><i class="bi bi-send"></i></button>
            </form>
          </div>
          <!-- Terminal -->
          <div class="tab-pane fade" id="tab-term" role="tabpanel">
            <div id="ab-terminal"></div>
            <p class="text-body-secondary small mt-2 mb-0"><i class="bi bi-shield-lock me-1"></i>Sandboxed to this instance only. First time on the <code>claude</code> engine, run <code>claude setup-token</code> here to log in your account.</p>
          </div>
        </div>

        <!-- Rollback modal -->
        <div class="modal fade" id="ab-rollback-modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Roll back to a checkpoint</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <p class="text-body-secondary">This restores code AND data to the chosen checkpoint. Later changes are undone (kept recoverable).</p>
            <select id="ab-ckpt-list" class="form-select"></select>
          </div>
          <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button id="ab-rollback-confirm" class="btn btn-warning">Roll back</button></div>
        </div></div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const AB = {
  id: <?= $selId ?>,
  token: <?= json_encode($ab_token) ?>,
  wsPath: <?= json_encode($ab_wspath) ?>,
  chatWsPath: <?= json_encode($ab_chat_wspath) ?>,
  csrf: <?= json_encode($csrfTok) ?>,
  has: <?= $ab_hasInstance ? 'true' : 'false' ?>,
};

// --- create instance --------------------------------------------------------
const createForm = document.getElementById('ab-create-form');
if (createForm) createForm.addEventListener('submit', function (e) {
  e.preventDefault();
  const btn = createForm.querySelector('button[type=submit]');
  const msg = document.getElementById('ab-create-msg');
  btn.disabled = true; msg.textContent = 'Provisioning… this can take a minute.';
  const body = new URLSearchParams({
    slug: createForm.slug.value.trim(),
    engine: createForm.engine.value,
    csrf_token: AB.csrf,
  });
  fetch('/aibuilder/create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': AB.csrf, 'X-Requested-With': 'XMLHttpRequest' },
    body: body.toString()
  }).then(r => r.json()).then(j => {
    if (j.success && j.data && j.data.id) { window.location = '/aibuilder/open/' + j.data.id; }
    else { msg.textContent = j.message || 'Failed.'; btn.disabled = false; }
  }).catch(() => { msg.textContent = 'Network error.'; btn.disabled = false; });
});

if (AB.has) {
  const statusEl = document.getElementById('ab-status');
  const setStatus = (t, c) => { statusEl.textContent = t; statusEl.className = 'badge align-self-center text-bg-' + c; };

  // Fresh short-lived token for each socket connect (the rendered one may be stale).
  const freshToken = () => fetch('/aibuilder/refresh?id=' + AB.id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json()).then(j => (j.success && j.data && j.data.token) ? j.data.token : AB.token)
    .catch(() => AB.token);

  const wsBase = (location.protocol === 'https:' ? 'wss' : 'ws') + '://' + location.host;

  // --- Terminal (node bridge :3990) ---
  let term, fit, termWs, termReady = false;
  function initTerminal() {
    if (termReady) return; termReady = true;
    term = new Terminal({ cursorBlink: true, fontSize: 13, scrollback: 50000,
      fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', theme: { background: '#1e1e1e' } });
    fit = new FitAddon.FitAddon(); term.loadAddon(fit);
    term.open(document.getElementById('ab-terminal')); fit.fit();
    freshToken().then(tok => {
      termWs = new WebSocket(wsBase + AB.wsPath + '?token=' + encodeURIComponent(tok));
      termWs.onopen = () => { setStatus('Connected', 'success'); termWs.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows })); };
      termWs.onmessage = (e) => term.write(typeof e.data === 'string' ? e.data : new Uint8Array(e.data));
      termWs.onclose = () => setStatus('Disconnected', 'secondary');
      termWs.onerror = () => setStatus('Error', 'danger');
      term.onData(d => { if (termWs.readyState === WebSocket.OPEN) termWs.send(JSON.stringify({ type: 'input', data: d })); });
      window.addEventListener('resize', () => { fit.fit(); if (termWs.readyState === WebSocket.OPEN) termWs.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows })); });
    });
  }
  // Lazy-init terminal when its tab is first shown (xterm needs a visible element to fit).
  document.querySelector('[data-bs-target="#tab-term"]').addEventListener('shown.bs.tab', initTerminal);

  // --- Chat (php bridge :3991) ---
  const log = document.getElementById('ab-chat-log');
  const chatForm = document.getElementById('ab-chat-form');
  const chatInput = document.getElementById('ab-chat-input');
  let chatWs = null, sessionId = null, curBubble = null, sending = false;

  const esc = s => s.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  function addMsg(role, text) {
    const d = document.createElement('div'); d.className = 'ab-msg ' + role;
    const b = document.createElement('div'); b.className = 'bubble'; b.innerHTML = esc(text || '');
    d.appendChild(b); log.appendChild(d); log.scrollTop = log.scrollHeight; return b;
  }
  function addTool(name) {
    const d = document.createElement('div'); d.className = 'ab-tool'; d.innerHTML = '<i class="bi bi-tools"></i> ' + esc(name);
    log.appendChild(d); log.scrollTop = log.scrollHeight;
  }

  function connectChat() {
    return freshToken().then(tok => new Promise((resolve, reject) => {
      chatWs = new WebSocket(wsBase + AB.chatWsPath + '?token=' + encodeURIComponent(tok));
      chatWs.onopen = () => setStatus('Connected', 'success');
      chatWs.onclose = () => { setStatus('Disconnected', 'secondary'); chatWs = null; };
      chatWs.onerror = () => { setStatus('Error', 'danger'); reject(); };
      chatWs.onmessage = (e) => {
        let m; try { m = JSON.parse(e.data); } catch { return; }
        switch (m.type) {
          case 'ready': resolve(); break;
          case 'start': curBubble = addMsg('assistant', ''); break;
          case 'session': sessionId = m.id; break;
          case 'delta': if (!curBubble) curBubble = addMsg('assistant', ''); curBubble.innerHTML += esc(m.text || ''); log.scrollTop = log.scrollHeight; break;
          case 'tool': addTool(m.name || 'tool'); break;
          case 'auth_required': addMsg('assistant', '⚠️ Not logged in. Open the Terminal tab and run: claude setup-token'); sending = false; break;
          case 'error': addMsg('assistant', '⚠️ ' + (m.error || 'error')); sending = false; break;
          case 'done': sending = false; curBubble = null; break;
        }
      };
    }));
  }

  chatForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const text = chatInput.value.trim();
    if (!text || sending) return;
    addMsg('user', text); chatInput.value = ''; sending = true;
    const send = () => chatWs.send(JSON.stringify({ type: 'chat', message: text, sessionId: sessionId || undefined }));
    if (chatWs && chatWs.readyState === WebSocket.OPEN) send();
    else connectChat().then(send).catch(() => { addMsg('assistant', '⚠️ Could not connect.'); sending = false; });
  });

  // --- Checkpoint / Rollback ---
  const post = (url, extra) => fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': AB.csrf, 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams(Object.assign({ csrf_token: AB.csrf, id: AB.id }, extra || {})).toString()
  }).then(r => r.json());

  document.getElementById('ab-checkpoint').addEventListener('click', function () {
    this.disabled = true;
    post('/aibuilder/checkpoint', {}).then(j => addTool('[checkpoint] ' + (j.success ? 'saved' : (j.message || 'failed')))).finally(() => this.disabled = false);
  });

  const rbModal = new bootstrap.Modal(document.getElementById('ab-rollback-modal'));
  document.getElementById('ab-rollback').addEventListener('click', function () {
    fetch('/aibuilder/checkpoints?id=' + AB.id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json()).then(j => {
        const sel = document.getElementById('ab-ckpt-list'); sel.innerHTML = '';
        ((j.data && j.data.checkpoints) || []).forEach(c => { const o = document.createElement('option'); o.value = c.name; o.textContent = c.name + '  (' + c.date + ')'; sel.appendChild(o); });
        if (!sel.options.length) { const o = document.createElement('option'); o.value = 'checkpoint-baseline'; o.textContent = 'checkpoint-baseline'; sel.appendChild(o); }
        rbModal.show();
      });
  });
  document.getElementById('ab-rollback-confirm').addEventListener('click', function () {
    const ckpt = document.getElementById('ab-ckpt-list').value; this.disabled = true;
    post('/aibuilder/rollback/' + encodeURIComponent(ckpt), {}).then(j => { addTool('[rollback] ' + (j.success ? 'restored ' + ckpt : (j.message || 'failed'))); rbModal.hide(); }).finally(() => this.disabled = false);
  });

  // Auto-connect chat on load.
  setStatus('Connecting…', 'secondary');
  connectChat().catch(() => setStatus('Idle', 'secondary'));
}
</script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.min.js"></script>
