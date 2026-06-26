<?php
/**
 * AI Builder view — instance picker + jailed Terminal/Chat + git changes panel.
 *
 * Vars: $instances (Instance beans), $selected (bean|null), $ab_sub, $ab_token,
 *       $ab_wspath, $ab_chat_wspath, $ab_hasInstance, $csrf
 */
$csrfTok = csrf_token();
$selId   = $selected ? (int)$selected->id : 0;
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.min.css">
<style>
  #ab-terminal { height: 68vh; width: 100%; background:#1e1e1e; border-radius:.375rem; padding:8px; }
  #ab-chat-log { height: 58vh; overflow-y:auto; background:#1e1e1e; border-radius:.375rem; padding:1rem; }
  .ab-msg { margin-bottom:.85rem; }
  .ab-msg.user { text-align:right; }
  .ab-msg.user .bubble { display:inline-block; background:#0d6efd; color:#fff; padding:.5rem .75rem; border-radius:.5rem; max-width:90%; white-space:pre-wrap; }
  .ab-msg.assistant .bubble { background:#2b3035; color:#e9ecef; padding:.6rem .85rem; border-radius:.5rem; }
  .ab-msg.assistant .bubble p:last-child { margin-bottom:0; }
  .ab-msg.assistant .bubble pre { background:#11141a; padding:.6rem .75rem; border-radius:.375rem; overflow-x:auto; }
  .ab-msg.assistant .bubble code { color:#7ee787; }
  .ab-msg.assistant .bubble pre code { color:#e9ecef; }
  /* Single animated activity pill (replaces the long Bash/Read/... list) */
  .ab-activity { display:inline-flex; align-items:center; gap:.4rem; font-size:.78rem; color:#9aa4b2;
                 background:#23272e; border:1px solid #343a40; border-radius:1rem; padding:.15rem .6rem; margin:.1rem 0 .4rem; cursor:default; }
  .ab-activity .dot { width:.5rem; height:.5rem; border-radius:50%; background:#0dcaf0; animation:abpulse 1s ease-in-out infinite; }
  .ab-activity.done .dot { background:#198754; animation:none; }
  .ab-activity.done { cursor:pointer; }
  .ab-activity .tool { color:#0dcaf0; font-weight:600; transition:opacity .15s; }
  @keyframes abpulse { 0%,100%{opacity:.35; transform:scale(.8);} 50%{opacity:1; transform:scale(1.15);} }
  .ab-activity-list { font-size:.72rem; color:#8b949e; margin:.1rem 0 .5rem; padding-left:.4rem; display:none; }
  .ab-activity-list.show { display:block; }
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
      <span class="badge text-bg-secondary align-self-center"><i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($selected->slug) ?>.tiknix
        <span id="ab-status" class="ms-1">· connecting…</span></span>
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
          <?php else: foreach ($instances as $inst): ?>
            <a href="/aibuilder/open/<?= (int)$inst->id ?>"
               class="list-group-item list-group-item-action <?= ($selId === (int)$inst->id) ? 'active' : '' ?>">
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

    <?php if (!$ab_hasInstance): ?>
      <div class="col-lg-9">
        <div class="card shadow-sm"><div class="card-body text-center text-body-secondary py-5">
          <i class="bi bi-arrow-left-circle fs-1 d-block mb-3"></i>
          Select an instance to open its sandboxed Terminal and Chat, or create a new one.
        </div></div>
      </div>
    <?php else: ?>
      <!-- Builder surface -->
      <div class="col-lg-6">
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-chat" type="button"><i class="bi bi-chat-dots me-1"></i>Chat</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-term" type="button"><i class="bi bi-terminal me-1"></i>Terminal</button></li>
        </ul>
        <div class="tab-content border border-top-0 rounded-bottom p-2 bg-body-tertiary">
          <div class="tab-pane fade show active" id="tab-chat" role="tabpanel">
            <div id="ab-chat-log"></div>
            <form id="ab-chat-form" class="d-flex gap-2 mt-2">
              <button type="button" id="ab-chat-clear" class="btn btn-outline-secondary" title="New conversation (clears this chat)"><i class="bi bi-trash"></i></button>
              <input id="ab-chat-input" class="form-control" placeholder="Ask the AI to build or change something…" autocomplete="off">
              <button class="btn btn-primary" type="submit"><i class="bi bi-send"></i></button>
            </form>
          </div>
          <div class="tab-pane fade" id="tab-term" role="tabpanel">
            <div id="ab-terminal"></div>
            <p class="text-body-secondary small mt-2 mb-0"><i class="bi bi-shield-lock me-1"></i>Sandboxed to this instance. First time on <code>claude</code>, run <code>claude setup-token</code> here.</p>
          </div>
        </div>
      </div>

      <!-- Changes + checkpoints -->
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
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.min.js"></script>
<script>
const AB = {
  id: <?= $selId ?>,
  token: <?= json_encode($ab_token) ?>,
  wsPath: <?= json_encode($ab_wspath) ?>,
  chatWsPath: <?= json_encode($ab_chat_wspath) ?>,
  csrf: <?= json_encode($csrfTok) ?>,
  has: <?= $ab_hasInstance ? 'true' : 'false' ?>,
};
const esc = s => (s||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
const renderMd = t => { try { return DOMPurify.sanitize(marked.parse(t||'')); } catch(e){ return esc(t); } };

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

  // --- Terminal (lazy) ---
  let termReady=false, term, termWs;
  function initTerminal(){
    if (termReady) return; termReady=true;
    term=new Terminal({cursorBlink:true,fontSize:13,scrollback:50000,fontFamily:'ui-monospace,Menlo,monospace',theme:{background:'#1e1e1e'}});
    const fit=new FitAddon.FitAddon(); term.loadAddon(fit); term.open(document.getElementById('ab-terminal')); fit.fit();
    freshToken().then(tok=>{
      termWs=new WebSocket(wsBase+AB.wsPath+'?token='+encodeURIComponent(tok));
      termWs.onopen=()=>{ setStatus('terminal connected'); termWs.send(JSON.stringify({type:'resize',cols:term.cols,rows:term.rows})); };
      termWs.onmessage=e=>term.write(typeof e.data==='string'?e.data:new Uint8Array(e.data));
      termWs.onclose=()=>setStatus('terminal disconnected');
      term.onData(d=>{ if(termWs.readyState===WebSocket.OPEN) termWs.send(JSON.stringify({type:'input',data:d})); });
      window.addEventListener('resize',()=>{ fit.fit(); if(termWs.readyState===WebSocket.OPEN) termWs.send(JSON.stringify({type:'resize',cols:term.cols,rows:term.rows})); });
    });
  }
  document.querySelector('[data-bs-target="#tab-term"]').addEventListener('shown.bs.tab', initTerminal);

  // --- Chat ---
  const log=document.getElementById('ab-chat-input')?document.getElementById('ab-chat-log'):null;
  const chatForm=document.getElementById('ab-chat-form'), chatInput=document.getElementById('ab-chat-input');
  let chatWs=null, sessionId=null, sending=false, cur=null;

  // Persist transcript + Claude session id per instance so a refresh doesn't wipe it.
  const STORE='ab_chat_'+AB.id;
  function saveChat(){ try{ localStorage.setItem(STORE, JSON.stringify({sid:sessionId, html:log.innerHTML})); }catch(e){} }
  function restoreChat(){ try{ const s=JSON.parse(localStorage.getItem(STORE)||'null');
    if(s&&s.html){ log.innerHTML=s.html; sessionId=s.sid||null;
      log.querySelectorAll('.ab-activity.done').forEach(a=>a.addEventListener('click',()=>{ const l=a.nextElementSibling; if(l&&l.classList.contains('ab-activity-list')) l.classList.toggle('show'); }));
      log.scrollTop=log.scrollHeight; } }catch(e){} }

  function addUser(t){ const d=document.createElement('div'); d.className='ab-msg user';
    d.innerHTML='<div class="bubble">'+esc(t)+'</div>'; log.appendChild(d); log.scrollTop=log.scrollHeight; saveChat(); }
  function newTurn(){
    const wrap=document.createElement('div'); wrap.className='ab-msg assistant';
    const act=document.createElement('div'); act.className='ab-activity'; act.style.display='none';
    act.innerHTML='<span class="dot"></span><span class="label">working</span> <span class="tool"></span><span class="cnt"></span>';
    const list=document.createElement('div'); list.className='ab-activity-list';
    const bub=document.createElement('div'); bub.className='bubble'; bub.innerHTML='<span class="text-body-secondary">…</span>';
    wrap.appendChild(act); wrap.appendChild(list); wrap.appendChild(bub);
    log.appendChild(wrap); log.scrollTop=log.scrollHeight;
    act.addEventListener('click',()=>{ if(act.classList.contains('done')) list.classList.toggle('show'); });
    return {wrap, act, list, bub, raw:'', tools:[]};
  }
  function tick(c, name){
    c.tools.push(name);
    c.act.style.display='inline-flex';
    c.act.querySelector('.tool').textContent=name;
    c.act.querySelector('.cnt').textContent=' · '+c.tools.length;
    c.act.querySelector('.tool').style.opacity=0; requestAnimationFrame(()=>c.act.querySelector('.tool').style.opacity=1);
  }
  function finishTurn(c){
    if (c.tools.length){
      c.act.classList.add('done'); c.act.querySelector('.label').textContent='';
      c.act.querySelector('.tool').textContent='✓ '+c.tools.length+' action'+(c.tools.length>1?'s':'');
      c.act.querySelector('.cnt').textContent=''; c.act.title='Click to expand';
      c.list.innerHTML=c.tools.map(t=>esc(t)).join(' · ');
    } else c.act.style.display='none';
    if(!c.raw) c.bub.innerHTML='<span class="text-body-secondary">(no text response)</span>';
    saveChat();
  }

  function connectChat(){
    return freshToken().then(tok=>new Promise((resolve,reject)=>{
      chatWs=new WebSocket(wsBase+AB.chatWsPath+'?token='+encodeURIComponent(tok));
      chatWs.onopen=()=>setStatus('chat connected');
      chatWs.onclose=()=>{ setStatus('chat disconnected'); chatWs=null; };
      chatWs.onerror=()=>{ setStatus('chat error'); reject(); };
      chatWs.onmessage=e=>{ let m; try{m=JSON.parse(e.data);}catch{return;}
        switch(m.type){
          case 'ready': resolve(); break;
          case 'start': cur=newTurn(); break;
          case 'session': sessionId=m.id; saveChat(); break;
          case 'delta': if(!cur) cur=newTurn(); cur.raw+=(m.text||''); cur.bub.innerHTML=renderMd(cur.raw); log.scrollTop=log.scrollHeight; break;
          case 'tool': if(!cur) cur=newTurn(); tick(cur, m.name||'tool'); break;
          case 'auth_required': if(!cur) cur=newTurn(); cur.bub.innerHTML='⚠️ Not logged in. Open the Terminal tab and run <code>claude setup-token</code>.'; sending=false; break;
          case 'error': if(!cur) cur=newTurn(); cur.bub.innerHTML='⚠️ '+esc(m.error||'error'); finishTurn(cur); sending=false; break;
          case 'done': if(cur) finishTurn(cur); cur=null; sending=false; refreshChanges(true); break;
        }
      };
    }));
  }

  chatForm.addEventListener('submit', function(e){
    e.preventDefault(); const text=chatInput.value.trim(); if(!text||sending) return;
    addUser(text); chatInput.value=''; sending=true; window._preChange=new Set(lastChangePaths);
    const send=()=>chatWs.send(JSON.stringify({type:'chat',message:text,sessionId:sessionId||undefined}));
    if(chatWs&&chatWs.readyState===WebSocket.OPEN) send();
    else connectChat().then(send).catch(()=>{ if(!cur)cur=newTurn(); cur.bub.innerHTML='⚠️ Could not connect.'; sending=false; });
  });

  // --- Changes panel ---
  let lastChangePaths=[];
  function refreshChanges(){
    fetch('/aibuilder/changes?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(j=>{
        const box=document.getElementById('ab-changes'); const files=(j.data&&j.data.files)||[];
        const pre=window._preChange||new Set();
        if(!files.length){ box.innerHTML='<div class="text-body-secondary small">No changes since last checkpoint.</div>'; lastChangePaths=[]; return; }
        box.innerHTML=files.map(f=>{
          const code=(f.status||'?').replace(/[^MADRU?]/g,'').charAt(0)||'M';
          const fresh=!pre.has(f.path)?' fresh':'';
          return '<div class="ab-file'+fresh+'"><span class="st '+code+'">'+esc(code)+'</span><span class="path">'+esc(f.path)+'</span></div>';
        }).join('');
        lastChangePaths=files.map(f=>f.path);
      }).catch(()=>{});
  }
  document.getElementById('ab-changes-refresh').addEventListener('click',()=>{ window._preChange=new Set(lastChangePaths); refreshChanges(); });

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
    post('/aibuilder/checkpoint',{label:inp.value.trim()}).then(j=>{ inp.value=''; loadCheckpoints(); refreshChanges(); }).finally(()=>btn.disabled=false);
  });
  function doRollback(ckpt){
    if(!confirm('Roll back to '+ckpt+'? This restores code AND data to that checkpoint.')) return;
    post('/aibuilder/rollback/'+encodeURIComponent(ckpt),{}).then(j=>{ loadCheckpoints(); refreshChanges(); });
  }

  // New-conversation button: clear transcript + start a fresh Claude session.
  document.getElementById('ab-chat-clear').addEventListener('click',()=>{
    if(!confirm('Clear this conversation and start fresh?')) return;
    log.innerHTML=''; sessionId=null; try{ localStorage.removeItem(STORE); }catch(e){}
  });

  // init
  setStatus('connecting…'); restoreChat(); refreshChanges(); loadCheckpoints();
  connectChat().catch(()=>setStatus('idle'));
}
</script>
