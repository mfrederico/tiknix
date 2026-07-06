<?php
/**
 * AI Builder view — instance picker + jailed Terminal + git changes/checkpoint/plan panel.
 *
 * Vars: $instances (Instance beans), $selected (bean|null), $ab_sub, $ab_token,
 *       $ab_wspath, $ab_hasInstance, $csrf
 */
$csrfTok = csrf_token();
$selId   = $selected ? (int)$selected->id : 0;
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.min.css">
<style>
  #ab-terminal { height: 70vh; width: 100%; background:#1e1e1e; border-radius:.375rem; padding:8px; }
  /* Loud "which instance am I in" banner */
  .ab-working { border:2px solid var(--bs-primary); }
  .ab-working .lbl { font-size:.6rem; letter-spacing:.06em; }
  /* Active instance in the left nav */
  .list-group-item.active .ab-caret { display:inline; }
  .ab-caret { display:none; }
  #ab-changes { max-height:40vh; overflow-y:auto; }
  .ab-file { display:flex; gap:.5rem; align-items:center; font-family:ui-monospace,Menlo,monospace; font-size:.78rem; padding:.15rem 0; }
  .ab-file .st { width:1.4rem; text-align:center; border-radius:.2rem; font-weight:700; font-size:.7rem; }
  .ab-file .st.M{background:#3a2f00;color:#e3b341}.ab-file .st.A{background:#0f2e15;color:#3fb950}
  .ab-file .st.D{background:#3a1113;color:#f85149}.ab-file .st.R{background:#0b2b3a;color:#39c5cf}.ab-file .st.U{background:#2d2233;color:#bc8cff}
  .ab-file.fresh { background:rgba(13,202,240,.10); border-radius:.25rem; }
  .ab-ckpt { font-size:.8rem; padding:.4rem 0; border-bottom:1px solid #2b3035; }
  .ab-ckpt .desc { color:#adb5bd; }
</style>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div>
      <h1 class="h3 fw-bold mb-0"><i class="bi bi-robot me-2"></i>AI Builder</h1>
      <p class="text-body-secondary mb-0">Build software with AI. Every instance is sandboxed — checkpoint and roll back any change.</p>
    </div>
    <?php if ($ab_hasInstance): ?>
      <div class="ab-working d-flex align-items-center gap-2 px-3 py-2 rounded-3 bg-primary-subtle">
        <i class="bi bi-hdd-network-fill text-primary fs-5"></i>
        <div class="lh-sm">
          <div class="lbl text-uppercase text-body-secondary fw-semibold">Working on</div>
          <div class="fw-bold"><?= htmlspecialchars($selected->slug) ?>.tiknix
            <span id="ab-status" class="fw-normal text-body-secondary small">· connecting…</span></div>
        </div>
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
                <div class="form-text">Becomes <code>&lt;slug&gt;.tiknix</code>.</div>
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
          <?php else: foreach ($instances as $inst): $isSel = ($selId === (int)$inst->id); ?>
            <a href="/aibuilder/open/<?= (int)$inst->id ?>"
               class="list-group-item list-group-item-action <?= $isSel ? 'active' : '' ?>">
              <div class="d-flex justify-content-between">
                <span class="fw-semibold"><i class="bi bi-caret-right-fill ab-caret me-1"></i><?= htmlspecialchars($inst->displayName ?: $inst->slug) ?></span>
                <span class="badge text-bg-dark"><?= htmlspecialchars($inst->engine) ?></span>
              </div>
              <small class="<?= $isSel ? '' : 'text-body-secondary' ?>"><?= htmlspecialchars($inst->slug) ?>.tiknix</small>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$ab_hasInstance): ?>
      <div class="col-lg-9">
        <div class="card shadow-sm"><div class="card-body text-center text-body-secondary py-5">
          <i class="bi bi-arrow-left-circle fs-1 d-block mb-3"></i>
          Select an instance to open its sandboxed Terminal, or create a new one.
        </div></div>
      </div>
    <?php else: ?>
      <!-- Builder surface: Terminal -->
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-terminal me-1"></i>Terminal</span>
            <span class="text-body-secondary small"><i class="bi bi-shield-lock me-1"></i>Sandboxed to <?= htmlspecialchars($selected->slug) ?>.tiknix</span>
          </div>
          <div class="card-body p-2 bg-body-tertiary">
            <div id="ab-terminal"></div>
            <p class="text-body-secondary small mt-2 mb-0">
              Type <code>claude</code> to start the agent. If it asks you to sign in, run <code>claude setup-token</code> and open the link it prints.
              Hold <kbd>Shift</kbd> and drag to select/copy; right-click to paste.
            </p>
          </div>
        </div>
      </div>

      <!-- Changes + checkpoints + plan -->
      <div class="col-lg-3">
        <div class="card shadow-sm mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-file-diff me-1"></i>Changes <small class="text-body-secondary">since checkpoint</small></span>
            <button id="ab-changes-refresh" class="btn btn-sm btn-outline-secondary" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
          </div>
          <div class="card-body">
            <div id="ab-changes"><div class="text-body-secondary small">No changes yet.</div></div>
          </div>
        </div>
        <div class="card shadow-sm">
          <div class="card-header fw-semibold"><i class="bi bi-bookmark-plus me-1"></i>Checkpoint</div>
          <div class="card-body">
            <form id="ab-ckpt-form" class="d-flex gap-2 mb-2">
              <input id="ab-ckpt-desc" class="form-control form-control-sm" placeholder="Describe this checkpoint…" maxlength="200">
              <button class="btn btn-success btn-sm" type="submit" title="Save checkpoint"><i class="bi bi-save"></i></button>
            </form>
            <div id="ab-ckpt-list" class="small"></div>
          </div>
        </div>
        <div class="card shadow-sm mt-3">
          <div class="card-header fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Plan (decompose)</div>
          <div class="card-body">
            <form id="ab-plan-form" class="mb-2">
              <textarea id="ab-plan-input" class="form-control form-control-sm mb-2" rows="2" placeholder="Describe a feature to decompose into tasks…"></textarea>
              <div class="d-flex gap-2">
                <button id="ab-plan-copy" class="btn btn-info btn-sm flex-fill" type="submit"><i class="bi bi-clipboard-plus me-1"></i>Copy plan prompt</button>
                <button id="ab-plan-ingest" class="btn btn-outline-info btn-sm" type="button" title="Ingest the plan the agent wrote"><i class="bi bi-box-arrow-in-down"></i></button>
              </div>
            </form>
            <div id="ab-plan-hint" class="text-body-secondary mb-2" style="font-size:.72rem"></div>
            <div id="ab-plan-list" class="small"></div>
          </div>
        </div>
        <div class="card shadow-sm mt-3">
          <div class="card-header fw-semibold"><i class="bi bi-github me-1"></i>Publish to GitHub</div>
          <div class="card-body">
            <div id="ab-gh-state" class="small text-body-secondary mb-2">Checking connection…</div>
            <button id="ab-publish" class="btn btn-dark btn-sm w-100" type="button"><i class="bi bi-cloud-upload me-1"></i>Publish</button>
            <div id="ab-publish-msg" class="small mt-2"></div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-web-links@0.11.0/lib/addon-web-links.min.js"></script>
<script>
const AB = {
  id: <?= $selId ?>,
  token: <?= json_encode($ab_token) ?>,
  wsPath: <?= json_encode($ab_wspath) ?>,
  csrf: <?= json_encode($csrfTok) ?>,
  has: <?= $ab_hasInstance ? 'true' : 'false' ?>,
};
const esc = s => (s||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));

// --- create instance --------------------------------------------------------
const createForm = document.getElementById('ab-create-form');
if (createForm) createForm.addEventListener('submit', function (e) {
  e.preventDefault();
  const btn = createForm.querySelector('button[type=submit]'), msg = document.getElementById('ab-create-msg');
  btn.disabled = true; msg.textContent = 'Provisioning… this can take a minute.';
  fetch('/aibuilder/create', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':AB.csrf,'X-Requested-With':'XMLHttpRequest'},
    body:new URLSearchParams({slug:createForm.slug.value.trim(), engine:createForm.engine.value, csrf_token:AB.csrf}).toString()
  }).then(r=>r.json()).then(j=>{
    if (j.success && j.data && j.data.id) window.location = '/aibuilder/open/' + j.data.id;
    else { msg.textContent = j.message || 'Failed.'; btn.disabled = false; }
  }).catch(()=>{ msg.textContent='Network error.'; btn.disabled=false; });
});

if (AB.has) {
  const statusEl = document.getElementById('ab-status');
  const setStatus = t => { statusEl.textContent = '· ' + t; };
  const freshToken = () => fetch('/aibuilder/refresh?id='+AB.id, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(j=>(j.success&&j.data&&j.data.token)?j.data.token:AB.token).catch(()=>AB.token);
  const wsBase = (location.protocol==='https:'?'wss':'ws') + '://' + location.host;

  // --- Terminal ---
  let term, termWs;
  function initTerminal(){
    term=new Terminal({cursorBlink:true,fontSize:13,scrollback:50000,fontFamily:'ui-monospace,Menlo,monospace',theme:{background:'#1e1e1e'}});
    const fit=new FitAddon.FitAddon(); term.loadAddon(fit);
    // Clickable URLs — makes the `claude setup-token` OAuth link openable without selecting it.
    try { term.loadAddon(new WebLinksAddon.WebLinksAddon((e,uri)=>window.open(uri,'_blank','noopener'))); } catch(e){}
    const el=document.getElementById('ab-terminal');
    term.open(el); fit.fit();

    // Copy-on-select: releasing the mouse over a selection copies it to the clipboard.
    el.addEventListener('mouseup', ()=>{
      const sel=term.getSelection();
      if(sel && navigator.clipboard) navigator.clipboard.writeText(sel).catch(()=>{});
    });
    // Right-click pastes from the clipboard into the PTY.
    el.addEventListener('contextmenu', ev=>{
      ev.preventDefault();
      if(navigator.clipboard) navigator.clipboard.readText().then(t=>{ if(t) term.paste(t); }).catch(()=>{});
    });

    freshToken().then(tok=>{
      termWs=new WebSocket(wsBase+AB.wsPath+'?token='+encodeURIComponent(tok));
      termWs.onopen=()=>{ setStatus('terminal connected'); termWs.send(JSON.stringify({type:'resize',cols:term.cols,rows:term.rows})); };
      termWs.onmessage=e=>term.write(typeof e.data==='string'?e.data:new Uint8Array(e.data));
      termWs.onclose=()=>setStatus('terminal disconnected');
      term.onData(d=>{ if(termWs.readyState===WebSocket.OPEN) termWs.send(JSON.stringify({type:'input',data:d})); });
      window.addEventListener('resize',()=>{ fit.fit(); if(termWs.readyState===WebSocket.OPEN) termWs.send(JSON.stringify({type:'resize',cols:term.cols,rows:term.rows})); });
    });
  }

  // --- Changes panel (polls so terminal edits show up live) ---
  let lastChangePaths=[];
  function refreshChanges(){
    fetch('/aibuilder/changes?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(j=>{
        const box=document.getElementById('ab-changes'); const files=(j.data&&j.data.files)||[];
        const prev=new Set(lastChangePaths);
        if(!files.length){ box.innerHTML='<div class="text-body-secondary small">No changes since last checkpoint.</div>'; lastChangePaths=[]; return; }
        box.innerHTML=files.map(f=>{
          const code=(f.status||'?').replace(/[^MADRU?]/g,'').charAt(0)||'M';
          const fresh=!prev.has(f.path)?' fresh':'';
          return '<div class="ab-file'+fresh+'"><span class="st '+code+'">'+esc(code)+'</span><span class="path">'+esc(f.path)+'</span></div>';
        }).join('');
        lastChangePaths=files.map(f=>f.path);
      }).catch(()=>{});
  }
  document.getElementById('ab-changes-refresh').addEventListener('click',()=>{ lastChangePaths=[]; refreshChanges(); });

  // --- Checkpoints ---
  function loadCheckpoints(){
    fetch('/aibuilder/checkpoints?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(j=>{
        const box=document.getElementById('ab-ckpt-list'); const cps=(j.data&&j.data.checkpoints)||[];
        if(!cps.length){ box.innerHTML='<div class="text-body-secondary">No checkpoints yet.</div>'; return; }
        box.innerHTML=cps.map(c=>'<div class="ab-ckpt"><div class="d-flex justify-content-between"><span class="fw-semibold">'+esc(c.name.replace(/^checkpoint-/,''))+'</span>'
          +'<button class="btn btn-link btn-sm p-0 ab-rb" data-ckpt="'+esc(c.name)+'" title="Roll back to here"><i class="bi bi-arrow-counterclockwise"></i></button></div>'
          +(c.description?'<div class="desc">'+esc(c.description)+'</div>':'')
          +'<div class="text-body-secondary" style="font-size:.72rem">'+esc(c.date)+' · '+esc(c.commit)+'</div></div>').join('');
        box.querySelectorAll('.ab-rb').forEach(b=>b.addEventListener('click',()=>doRollback(b.dataset.ckpt)));
      }).catch(()=>{});
  }
  const post=(url,extra)=>fetch(url,{method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':AB.csrf,'X-Requested-With':'XMLHttpRequest'},
    body:new URLSearchParams(Object.assign({csrf_token:AB.csrf,id:AB.id},extra||{})).toString()}).then(r=>r.json());

  document.getElementById('ab-ckpt-form').addEventListener('submit',function(e){
    e.preventDefault(); const inp=document.getElementById('ab-ckpt-desc'); const btn=this.querySelector('button'); btn.disabled=true;
    post('/aibuilder/checkpoint',{label:inp.value.trim()}).then(j=>{
      inp.value=''; loadCheckpoints(); refreshChanges();
      const p=j.data&&j.data.publish; if(p){ const m=ghMsg();
        if(p.ok&&p.pr&&p.pr.url){ m.className='small mt-2 text-success'; m.innerHTML='<i class="bi bi-check-circle me-1"></i>Auto-published — <a href="'+esc(p.pr.url)+'" target="_blank" rel="noopener">PR #'+esc(String(p.pr.number||''))+'</a>'; }
        else if(p.ok){ m.className='small mt-2 text-success'; m.innerHTML='<i class="bi bi-check-circle me-1"></i>'+esc(p.message||'Auto-pushed'); }
        else { m.className='small mt-2 text-danger'; m.textContent='Auto-publish failed: '+(p.error||''); } }
    }).finally(()=>btn.disabled=false);
  });
  function doRollback(ckpt){
    if(!confirm('Roll back to '+ckpt+'? This restores code AND data to that checkpoint.')) return;
    post('/aibuilder/rollback/'+encodeURIComponent(ckpt),{}).then(j=>{ loadCheckpoints(); refreshChanges(); });
  }

  // --- Plan mode (terminal-driven: copy prompt -> run in Terminal -> ingest) ---
  const planHint=document.getElementById('ab-plan-hint');
  function planNote(html){ planHint.innerHTML=html; }
  const planForm=document.getElementById('ab-plan-form');
  if(planForm) planForm.addEventListener('submit',function(e){
    e.preventDefault(); const req=document.getElementById('ab-plan-input').value.trim(); if(!req) return;
    const prompt="PLAN MODE — do NOT modify application files. First use the codebase_map and whatprovides MCP tools to ground yourself in THIS codebase. Then decompose the request into a small, ordered set of concrete tasks and deliver it by calling the submit_plan tool with {title, summary, subtasks:[{title, description, priority 1-4, engine claude|qwen, files[]}]}. After submit_plan succeeds, reply with only: PLAN_WRITTEN\nRequest: "+req;
    if(navigator.clipboard) navigator.clipboard.writeText(prompt).then(()=>{
      planNote('✅ Prompt copied. Paste it into the <strong>Terminal</strong> (right-click). When the agent prints <code>PLAN_WRITTEN</code>, click <i class="bi bi-box-arrow-in-down"></i> to ingest.');
    }).catch(()=>planNote('⚠️ Could not copy — select and copy the prompt manually.'));
    else planNote('⚠️ Clipboard unavailable in this browser.');
  });
  document.getElementById('ab-plan-ingest').addEventListener('click',function(){
    this.disabled=true; planNote('Ingesting…');
    post('/aibuilder/planingest',{}).then(j=>{
      if(j.success){ planNote('✅ Plan ingested.'); loadPlans(); loadCheckpoints(); }
      else planNote('⚠️ '+(j.message||'No plan found. Did the agent call submit_plan?'));
    }).catch(()=>planNote('⚠️ Ingest failed.')).finally(()=>{ this.disabled=false; });
  });
  function loadPlans(){
    fetch('/aibuilder/plan?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(j=>{
      const box=document.getElementById('ab-plan-list'); const plans=(j.data&&j.data.plans)||[];
      if(!plans.length){ box.innerHTML='<div class="text-body-secondary">No plans yet.</div>'; return; }
      box.innerHTML=plans.map(p=>'<div class="ab-ckpt"><div class="fw-semibold">'+esc(p.title)+'</div>'
        +(p.checkpoint?'<div class="text-body-secondary" style="font-size:.72rem">baseline: '+esc(p.checkpoint)+'</div>':'')
        +p.subtasks.map(s=>'<div class="ab-file"><span class="st M">P'+esc(String(s.priority))+'</span><span>'+esc(s.title)+' <span class="badge text-bg-dark">'+esc(s.engine)+'</span></span></div>').join('')
        +'</div>').join('');
    }).catch(()=>{});
  }

  // --- Publish to GitHub (push + PR; first-time opens setup in a new tab) ---
  let ghConnected=false, ghRepo='';
  function loadGhStatus(){
    fetch('/connections/status?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(j=>{
        const st=document.getElementById('ab-gh-state');
        ghConnected=!!(j.data&&j.data.connected);
        if(ghConnected&&j.data.connection){ const c=j.data.connection; ghRepo=c.repo||'';
          st.innerHTML='<i class="bi bi-check-circle text-success me-1"></i>Connected: <strong>'+esc(ghRepo)+'</strong>'
            +(c.autoPublish?' <span class="badge text-bg-info">auto-publish</span>':''); }
        else st.innerHTML='<i class="bi bi-plug me-1"></i>Not connected. Publish will open GitHub setup.';
      }).catch(()=>{});
  }
  const ghMsg=()=>document.getElementById('ab-publish-msg');
  document.getElementById('ab-publish').addEventListener('click',function(){
    if(!ghConnected){
      window.open('/connections/setup?id='+AB.id,'_blank');
      ghMsg().className='small mt-2 text-body-secondary';
      ghMsg().innerHTML='Complete GitHub setup in the new tab, then click <strong>Publish</strong> again.';
      return;
    }
    const btn=this; btn.disabled=true;
    ghMsg().className='small mt-2 text-body-secondary'; ghMsg().textContent='Pushing & opening PR…';
    post('/connections/publish',{}).then(j=>{
      const m=ghMsg(); const pr=j.data&&j.data.pr;
      if(j.success&&pr&&pr.url){ m.className='small mt-2 text-success';
        m.innerHTML='<i class="bi bi-check-circle me-1"></i>'+esc(j.message||'Published')+' — <a href="'+esc(pr.url)+'" target="_blank" rel="noopener">PR #'+esc(String(pr.number||''))+'</a>'; }
      else if(j.success){ m.className='small mt-2 text-success';
        m.innerHTML='<i class="bi bi-check-circle me-1"></i>'+esc(j.message||'Pushed')+(j.data&&j.data.note?' — '+esc(j.data.note):''); }
      else { m.className='small mt-2 text-danger'; m.textContent=j.message||'Publish failed.'; }
    }).catch(()=>{ const m=ghMsg(); m.className='small mt-2 text-danger'; m.textContent='Network error.'; })
      .finally(()=>btn.disabled=false);
  });
  window.addEventListener('message',function(ev){
    if(ev.origin===location.origin&&ev.data&&ev.data.type==='gh-connected'){
      loadGhStatus(); const m=ghMsg(); m.className='small mt-2 text-success';
      m.innerHTML='<i class="bi bi-check-circle me-1"></i>GitHub connected. Click <strong>Publish</strong>.'; }
  });

  // init
  setStatus('connecting…'); initTerminal(); refreshChanges(); loadCheckpoints(); loadPlans(); loadGhStatus();
  setInterval(refreshChanges, 4000);
}
</script>
