<?php
/**
 * Pipelines — this install's pipeline editor: spreadsheet step builder + step-trace
 * debugger + `{` variable autocomplete. Moved in from the pipelines.tiknix sidecar
 * (COMPONENTS_PLAN.md, "Every app has its own /pipelines"); the JSON stays the on-disk
 * format only, behind the Advanced toggle. Rendered inside the app layout, which already
 * carries app.css and the design system.
 *
 * @var array  $components  type => {summary, fields[], config}
 * @var string $apiBase     this install's public base URL (for the REST endpoint hint)
 * @var int    $memberId
 */
$h = fn($s) => htmlspecialchars((string) $s);
?>
<style>
  .pe-inst select{width:100%;}
  .ui-content{padding-top:1.25rem;}   /* no plugin topbar — the tiknix shell provides it */
  .pe-list .item{cursor:pointer;border:1px solid var(--bs-border-color);background:var(--ui-surface);border-radius:.75rem;padding:.6rem .75rem;margin-bottom:.5rem;transition:.12s;}
  .pe-list .item:hover{border-color:var(--ui-primary);}
  .pe-list .item.active{border-color:var(--ui-primary);box-shadow:0 0 0 1px var(--ui-primary) inset;}
  .pe-list .item .nm{font-weight:600;font-family:var(--ui-ff-display);}

  /* spreadsheet step grid */
  .ss{border:1px solid var(--bs-border-color);border-radius:.9rem;overflow:hidden;background:var(--ui-surface);box-shadow:var(--ui-shadow);}
  .ss-cols{grid-template-columns:20px 26px minmax(6rem,1fr) 7.5rem 8.5rem 8.5rem 28px;}
  .ss-head,.ss-row{display:grid;gap:.4rem;align-items:center;padding:.3rem .55rem;}
  .ss-head{background:var(--ui-surface-soft);border-bottom:1px solid var(--bs-border-color);font-family:var(--ui-ff-mono);font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--bs-tertiary-color);}
  .ss-row-wrap{border-bottom:1px solid var(--bs-border-color);transition:background .1s;}
  .ss-row-wrap:last-child{border-bottom:none;}
  .ss-row-wrap:hover{background:var(--ui-surface-soft);}
  .ss-row-wrap.open{background:var(--ui-surface-soft);}
  .ss-row-wrap.dbg-cur{box-shadow:inset 3px 0 0 var(--ui-accent-report);}
  .ss-ghost{opacity:.35;}
  .ss-row .drag{cursor:grab;color:var(--bs-tertiary-color);text-align:center;}
  .ss-row .drag:active{cursor:grabbing;}
  .ss-row .idx{width:22px;height:22px;border-radius:50%;background:var(--ui-primary);color:#fff;display:grid;place-items:center;font-family:var(--ui-ff-mono);font-size:.72rem;font-weight:600;}
  .ss-summary{font-family:var(--ui-ff-mono);font-size:11px;color:var(--bs-tertiary-color);padding:0 .55rem .3rem 3rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ss-card{padding:.65rem .8rem .85rem;border-top:1px dashed var(--bs-border-color);}
  .fld-help{font-size:.76rem;color:var(--bs-tertiary-color);}
  .fld-label{font-size:.78rem;font-weight:600;color:var(--bs-secondary-color);margin-bottom:.15rem;}
  .fld-label .req,.req{color:var(--ui-accent-report);}
  .mono,#jsonBox,.bag-view{font-family:var(--ui-ff-mono);font-size:12.5px;}
  .st-completed{color:#3bbf7a}.st-failed{color:#e0559b}.st-running,.st-awaiting{color:#e0a23b}.st-pending,.st-paused{color:var(--bs-tertiary-color)}
  .trace-step{border:1px solid var(--bs-border-color);border-radius:.6rem;padding:.45rem .6rem;margin-bottom:.4rem;font-size:.85rem;}
  .trace-step.cur{border-color:var(--ui-primary);box-shadow:0 0 0 1px var(--ui-primary) inset;}
  /* variable autocomplete + chips */
  .vac{position:absolute;z-index:3000;background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:.5rem;box-shadow:0 6px 20px rgba(0,0,0,.18);max-height:240px;overflow:auto;font-size:12.5px;}
  .vac-item{display:flex;justify-content:space-between;gap:1rem;padding:.28rem .55rem;cursor:pointer;font-family:var(--ui-ff-mono);white-space:nowrap;}
  .vac-item.active{background:var(--ui-primary);color:#fff;}
  .vac-ty{opacity:.6;font-size:11px;}
  .var-chips{display:flex;flex-wrap:wrap;gap:.3rem;align-items:center;margin:0 0 .55rem;padding:.35rem .5rem;background:var(--ui-surface-soft);border-radius:.5rem;}
  .var-chips-lab{font-size:11px;color:var(--bs-tertiary-color);text-transform:uppercase;letter-spacing:.03em;margin-right:.15rem;}
  .var-chip{font-family:var(--ui-ff-mono);font-size:11.5px;padding:.1rem .4rem;border:1px solid var(--bs-border-color);border-radius:.4rem;cursor:pointer;background:var(--bs-body-bg);white-space:nowrap;}
  .var-chip:hover{background:var(--ui-primary);color:#fff;border-color:var(--ui-primary);}
  pre.io{background:var(--ui-surface-inset);border-radius:.5rem;padding:.5rem;margin:.35rem 0 0;font-size:11.5px;max-height:26vh;overflow:auto;white-space:pre-wrap;word-break:break-word;}
  .type-menu{max-height:60vh;overflow:auto;}
  .type-menu .t{font-family:var(--ui-ff-mono);}
  .comp code{color:var(--bs-link-color);}
  textarea.code{font-family:var(--ui-ff-mono);font-size:12.5px;white-space:pre;tab-size:2;line-height:1.55;}
  /* line-numbered code editor (auto-grows to ~25 rows, then scrolls) */
  .code-wrap{display:flex;align-items:stretch;border:1px solid var(--bs-border-color);border-radius:.375rem;overflow:hidden;background:var(--bs-body-bg);}
  .code-wrap:focus-within{border-color:var(--ui-primary);box-shadow:0 0 0 .15rem color-mix(in srgb,var(--ui-primary) 22%,transparent);}
  .code-gutter{flex:0 0 auto;min-width:2.4em;padding:.375rem .45rem;text-align:right;color:var(--bs-tertiary-color);background:var(--ui-surface-soft);font-family:var(--ui-ff-mono);font-size:12.5px;line-height:1.55;white-space:pre;overflow:hidden;user-select:none;border-right:1px solid var(--bs-border-color);}
  .code-wrap textarea.code{flex:1 1 auto;border:0!important;border-radius:0;box-shadow:none!important;resize:none;overflow:auto;}
</style>
<div class="pe-editor">
  <div class="row g-3">

    <!-- ============ pipeline list ============ -->
    <div class="col-lg-3">
      <?php /* This install's pipelines. The hidden #inst input keeps the editor's JS contract
         (it reads the API base from it) without offering a choice of project: the project
         is the app you are signed in to. */ ?>
      <input type="hidden" id="inst" value="<?= $h($site_name ?? 'this app') ?>" data-api-base="<?= $h($apiBase) ?>">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="ui-eyebrow">Pipelines</span>
        <button class="btn btn-sm btn-primary" onclick="newPipeline()"><i class="bi bi-plus-lg"></i> New</button>
      </div>
      <div id="plist" class="pe-list"></div>

      <?php /* The app's named agents (COMPONENTS_PLAN.md, "Named agents for the app"): what an
         `agent` step picks from. Name, pre-prompt, engine/model/timeout, kind (cli through the
         install's bin/claude, or any OpenAI-compatible endpoint), its own key. */ ?>
      <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
        <span class="ui-eyebrow">Agents</span>
        <button class="btn btn-sm btn-outline-primary" onclick="newAgent()"><i class="bi bi-plus-lg"></i> New</button>
      </div>
      <div id="agents-list" class="pe-list"></div>
      <div id="agent-slot"></div>
    </div>

    <!-- ============ builder ============ -->
    <div class="col-lg-6">
      <div class="d-flex gap-2 mb-3 flex-wrap">
        <button class="btn btn-sm btn-outline-info" onclick="validateDef()"><i class="bi bi-check2-circle"></i> Validate</button>
        <button class="btn btn-sm btn-primary" onclick="saveDef()"><i class="bi bi-save"></i> Save</button>
        <button class="btn btn-sm btn-success" onclick="runDef()"><i class="bi bi-play-fill"></i> Run</button>
        <button class="btn btn-sm btn-warning" onclick="debugStart()"><i class="bi bi-bug"></i> Debug</button>
        <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="toggleJson()"><i class="bi bi-code-slash"></i> JSON</button>
        <button class="btn btn-sm btn-outline-danger" aria-label="Delete pipeline" title="Delete pipeline" onclick="deleteDef()"><i class="bi bi-trash"></i></button>
      </div>
      <div id="msg" class="small mb-2"></div>

      <div id="builder">
        <div class="ui-panel"><div class="ui-panel-body" style="color:var(--bs-secondary-color)">Select a pipeline, or click <b>New</b>.</div></div>
      </div>

      <!-- advanced JSON -->
      <div id="jsonWrap" class="ui-panel mt-3" style="display:none">
        <div class="ui-panel-header"><span class="ui-eyebrow">Advanced · raw JSON</span>
          <button class="btn btn-sm btn-outline-primary" onclick="applyJson()">Apply JSON → builder</button></div>
        <div class="ui-panel-body"><textarea id="jsonBox" class="form-control code" rows="16" spellcheck="false"></textarea></div>
      </div>
    </div>

    <!-- ============ run / debug + reference ============ -->
    <div class="col-lg-3">
      <div class="ui-panel mb-3">
        <div class="ui-panel-header"><span class="ui-eyebrow">Run / Debug</span></div>
        <div class="ui-panel-body">
          <div id="runctl"></div>
          <div id="runbox" class="small"></div>
        </div>
      </div>

      <div class="ui-panel">
        <div class="ui-panel-header"><span class="ui-eyebrow">Step types</span></div>
        <div class="ui-panel-body comp small">
          <?php foreach ($components as $type => $c): if (!empty($c['internal'])) continue; ?>
            <div class="mb-2"><code><?= $h($type) ?></code> — <?= $h($c['summary'] ?? '') ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
"use strict";
const COMPONENTS = <?= json_encode($components, JSON_UNESCAPED_SLASHES) ?> || {};
const CSRF = <?= json_encode(csrf_token()) ?>;
const TYPES = Object.keys(COMPONENTS);
const PUBLIC_TYPES = TYPES.filter(t => !COMPONENTS[t].internal);   // internal steps (e.g. housekeep) are runtime plumbing — kept out of the palette
const $ = s => document.querySelector(s);
const inst = () => $('#inst') ? $('#inst').value : '';
// Public base URL of the selected instance (host that serves /pipeline/api/<slug>).
// #inst is a hidden input carrying the selected project (no dropdown), so read the
// attribute off the element itself rather than a selected <option>.
const instApiBase = () => { const s=$('#inst'); return (s&&s.getAttribute('data-api-base'))||''; };
function copyText(t,btn){
  const done=()=>{ if(btn){ const i=btn.querySelector('i'); if(i){ const p=i.className; i.className='bi bi-check2'; setTimeout(()=>i.className=p,1200);} } };
  // execCommand fallback works inside the sidecar iframe where the async Clipboard API
  // is often blocked; prompt only as a last resort.
  const fallback=()=>{ try{ const ta=document.createElement('textarea'); ta.value=t; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.top='-1000px'; ta.style.opacity='0'; document.body.appendChild(ta); ta.focus(); ta.select(); const ok=document.execCommand('copy'); document.body.removeChild(ta); if(ok){ done(); return; } }catch(e){} window.prompt('Copy:',t); };
  if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(t).then(done).catch(fallback); } else { fallback(); }
}
// --- REST test keys (client-side): paste a pk_ key once, saved per instance in this
//     browser tab, and reuse it to build runnable curls across the whole pipeline suite.
let SELECTED_PK = '';
function pkStoreKey(){ return 'pipe_pk_' + (inst() || '_'); }
function pkList(){ try{ return JSON.parse(sessionStorage.getItem(pkStoreKey()) || '[]'); }catch(e){ return []; } }
function pkSave(list){ try{ sessionStorage.setItem(pkStoreKey(), JSON.stringify(list)); }catch(e){} }
function pkAdd(){
  const k=(window.prompt('Paste a pk_ key for this instance (stored only in this browser tab):')||'').trim(); if(!k) return;
  const label=(window.prompt('Label for this key (optional):','key '+(pkList().length+1))||'').trim() || ('key '+(pkList().length+1));
  const list=pkList(); list.push({label:label,key:k}); pkSave(list); SELECTED_PK=k; renderBuilder();
}
function pkForget(){ if(!SELECTED_PK) return; const list=pkList().filter(x=>x.key!==SELECTED_PK); pkSave(list); SELECTED_PK=list.length?list[0].key:''; renderBuilder(); }
// Mint a fresh pk_ key ON the selected instance (its own DB, via trigger_secret) and
// drop it into the dropdown — no juggling key types or scopes; you get the right key.
function pkGenerate(btn){
  if(btn){ btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm"></span>'; }
  jpost('/pipelines/mintkey', {label: ''}).then(d=>{
    if(d && d.key){ const list=pkList(); list.push({label:(d.label||'editor key'), key:d.key}); pkSave(list); SELECTED_PK=d.key; renderBuilder(); msg('Test key minted — it fills the curl below.','info'); }
    else { if(btn){ btn.disabled=false; } msg('Could not mint a key','danger'); }
  }).catch(e=>{ if(btn){ btn.disabled=false; } msg(e.message||'Could not mint a key','danger'); });
}
function pkSelect(v){ SELECTED_PK=v; const el=document.getElementById('pk-curl'); if(el) el.textContent=pkCurl(); }
function pkCurl(){
  const base=instApiBase(); const slug=(DEF&&DEF.slug)?DEF.slug:'my-pipeline';
  const url=(base||'')+'/pipeline/api/'+slug; const key=SELECTED_PK||'pk_YOUR_KEY';
  return 'curl -X POST '+url+' \\\n  -H "Authorization: Bearer '+key+'" \\\n  -H "Content-Type: application/json" \\\n  -d \'{}\'';
}
let DEF = null, CURRENT = null, watchTimer = null, DEBUG = null, OPEN = null, sortable = null, UIDSEQ = 1, schedForceCustom = false, CONNECTORS = [], AGENTS = [], ENGINES = [];

// ---- theme toggle (shares the tiknix 'ui-theme' key) ----
$('#themeToggle') && ($('#themeToggle').onclick = () => {
  const cur = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-bs-theme', cur);
  try { localStorage.setItem('ui-theme', cur); } catch(e){}
});

// ---- helpers ----
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function msg(t,cls){ $('#msg').innerHTML = t ? `<span class="text-${cls}">${esc(t)}</span>` : ''; }
function trunc(v){ const s = typeof v==='string' ? v : JSON.stringify(v); return s && s.length>52 ? s.slice(0,52)+'…' : (s||''); }
async function jget(u){ const r=await fetch(u,{headers:{Accept:'application/json'}}); const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.message||('HTTP '+r.status)); return d; }
async function jpost(u,b){ const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':CSRF},body:new URLSearchParams(b)}); const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.message||('HTTP '+r.status)); return d; }
function uid(s){ Object.defineProperty(s,'__uid',{value:UIDSEQ++,enumerable:false,writable:true,configurable:true}); return s; }

const TEMPLATE = () => ({
  slug:"my-pipeline", name:"My pipeline", description:"",
  expose_as_tool:false, expose_as_api:false, trigger:{cron:""},
  context_schema:{ name:{ type:"string", required:true } },
  steps:[ { name:"greet", type:"transform", config:{ mode:"template", input:"Hello {context.name}" }, on_success:"exit", on_fail:"exit" } ]
});

// ---- pipeline list ----
async function loadList(){
  try{
    const d = await jget('/pipelines/list');
    const el=$('#plist'); el.innerHTML='';
    if(!d.pipelines.length){ el.innerHTML='<div class="small" style="color:var(--bs-tertiary-color)">No pipelines yet — click New.</div>'; return; }
    d.pipelines.forEach(p=>{
      const badges=(p.stateful?'<span class="ui-chip" style="padding:.05rem .45rem;font-size:.7rem" title="durable object"><i class="bi bi-box"></i> object</span> ':'')
        +(p.expose_as_tool?'<span class="ui-chip" style="padding:.05rem .45rem;font-size:.7rem">tool</span> ':'')
        +(p.expose_as_api?'<span class="ui-chip" style="padding:.05rem .45rem;font-size:.7rem">api</span> ':'')
        +(p.cron?'<span class="ui-chip" style="padding:.05rem .45rem;font-size:.7rem" title="'+esc(p.cron)+'"><i class="bi bi-clock"></i></span>':'');
      const div=document.createElement('div'); div.className='item'+(CURRENT===p.slug?' active':'');
      div.innerHTML=`<div class="d-flex justify-content-between align-items-start gap-2"><div><div class="nm">${esc(p.name)}</div><div class="small" style="color:var(--bs-tertiary-color)">${esc(p.slug)} · ${p.steps} steps</div></div><div class="text-end">${badges}</div></div>`;
      div.onclick=()=>openPipeline(p.slug);
      el.appendChild(div);
    });
  }catch(e){ msg(e.message,'danger'); }
}
async function openPipeline(slug){
  try{ const d=await jget('/pipelines/get?slug='+encodeURIComponent(slug));
    DEF=normalize(d.def); CURRENT=slug; DEBUG=null; SHAPES={}; OPEN=null; schedForceCustom=false; $('#runbox').innerHTML=''; msg('',''); renderBuilder(); loadShapes(); loadList();
  }catch(e){ msg(e.message,'danger'); }
}
function newPipeline(){ DEF=normalize(TEMPLATE()); CURRENT=null; DEBUG=null; SHAPES={}; OPEN=null; schedForceCustom=false; $('#runbox').innerHTML=''; msg('New pipeline — edit + Save.','info'); renderBuilder(); loadList(); }

// the instance's connected connectors (for the connection step's dropdown; no secrets)
async function loadConnectors(){ try{ const d=await jget('/pipelines/connectors'); CONNECTORS=d.connectors||[]; }catch(e){ CONNECTORS=[]; } if(DEF) renderBuilder(); }

// ---- agents: the app's named agents (Data page → Agents). Keys never arrive here. ----
async function loadAgents(){
  try{ const d=await jget('/pipelines/agents'); AGENTS=d.agents||[]; ENGINES=d.engines||[]; }
  catch(e){ AGENTS=[]; ENGINES=[]; document.getElementById('agents-list').innerHTML=`<div class="small text-danger">${esc(e.message)}</div>`; return; }
  renderAgents(); if(DEF) renderBuilder();
}
function renderAgents(){
  const el=document.getElementById('agents-list');
  if(!AGENTS.length){ el.innerHTML=`<div class="small" style="color:var(--bs-tertiary-color)">No agents yet. An <code>agent</code> step then runs on the install's engine and credential. Add one to give it a name, a pre-prompt and its own key.</div>`; return; }
  el.innerHTML = AGENTS.map(a=>`
    <div class="item" onclick="editAgent(${a.id})">
      <div class="d-flex align-items-center gap-2">
        <span class="nm flex-grow-1">${esc(a.name)}</span>
        ${a.is_default?'<span class="ui-chip">default</span>':''}
        <span class="ui-chip ${a.key_status==='unreadable'?'text-danger':''}" title="API key">${esc(a.key_status==='set'?'key':(a.key_status==='unreadable'?'key unreadable':'no key'))}</span>
      </div>
      <div class="small" style="color:var(--bs-secondary-color)">${esc(a.kind)} · ${esc(a.kind==='openai'?a.model:((a.engine||'install default')+(a.model?' / '+a.model:'')))} · ${a.timeout}s</div>
      ${a.description?`<div class="small text-truncate" style="color:var(--bs-tertiary-color)">${esc(a.description)}</div>`:''}
    </div>`).join('');
}
function agentForm(a){
  a = a || {id:0,name:'',description:'',kind:'cli',engine:'',model:'',endpoint:'',timeout:600,pre_prompt:'',is_default:!AGENTS.length,key_status:'unset'};
  const engOpts = ['',...ENGINES].map(e=>`<option value="${esc(e)}" ${e===a.engine?'selected':''}>${e||'— install default —'}</option>`).join('');
  return `
  <div class="ui-panel mt-2" id="agent-form">
    <div class="ui-panel-header"><span class="ui-eyebrow">${a.id?'Edit agent':'New agent'}</span></div>
    <div class="ui-panel-body">
      <div class="row g-2">
        <div class="col-6"><div class="fld-label">Name <span class="req">*</span></div><input class="form-control form-control-sm" id="ag-name" value="${esc(a.name)}" placeholder="data-append"></div>
        <div class="col-6"><div class="fld-label">Kind</div><select class="form-select form-select-sm" id="ag-kind" onchange="agentKindChanged()"><option value="cli" ${a.kind==='cli'?'selected':''}>cli — Claude Code / a registered engine</option><option value="openai" ${a.kind==='openai'?'selected':''}>openai — any OpenAI-compatible endpoint</option></select></div>
        <div class="col-12"><div class="fld-label">Description</div><input class="form-control form-control-sm" id="ag-desc" value="${esc(a.description)}" placeholder="What this agent is for"></div>
        <div class="col-6 ag-cli"><div class="fld-label">Engine</div><select class="form-select form-select-sm" id="ag-engine">${engOpts}</select></div>
        <div class="col-6 ag-openai"><div class="fld-label">Endpoint <span class="req">*</span></div><input class="form-control form-control-sm" id="ag-endpoint" value="${esc(a.endpoint)}" placeholder="https://api.openai.com/v1"></div>
        <div class="col-6"><div class="fld-label">Model</div><input class="form-control form-control-sm" id="ag-model" value="${esc(a.model)}" placeholder="cli: claude-opus-5-5 / opus / sonnet / haiku · openai: the model id"></div>
        <div class="col-6"><div class="fld-label">Timeout (s)</div><input type="number" class="form-control form-control-sm" id="ag-timeout" value="${a.timeout||600}" min="5" max="3600"></div>
        <div class="col-12"><div class="fld-label">API key <span class="small" style="color:var(--bs-tertiary-color)">— ${esc(a.key_status==='set'?'a key is stored; type a new one to replace it':(a.key_status==='unreadable'?'the stored key cannot be decrypted — replace it':'none stored'))}</span></div>
          <input type="password" class="form-control form-control-sm" id="ag-key" autocomplete="new-password" placeholder="${a.kind==='cli'?'optional — blank uses the install\'s credential chain':'sk-…'}">
          ${a.key_status!=='unset'?`<label class="form-check-label small mt-1"><input type="checkbox" class="form-check-input" id="ag-clearkey"> clear the stored key</label>`:''}</div>
        <div class="col-12"><div class="fld-label">Pre-prompt <span class="small" style="color:var(--bs-tertiary-color)">— the agent's system prompt; a step's own <code>system</code> is appended</span></div>
          <textarea class="form-control form-control-sm code" id="ag-pre" rows="6">${esc(a.pre_prompt)}</textarea></div>
        <div class="col-12"><label class="form-check-label small"><input type="checkbox" class="form-check-input" id="ag-default" ${a.is_default?'checked':''}> default agent (used by steps that name none)</label></div>
      </div>
      <div class="d-flex gap-2 mt-3 flex-wrap">
        <button class="btn btn-sm btn-primary" onclick="saveAgent(${a.id})"><i class="bi bi-save"></i> Save</button>
        ${a.id?`<button class="btn btn-sm btn-outline-success" onclick="testAgent(${a.id})"><i class="bi bi-chat-dots"></i> Test</button>`:''}
        ${a.id?`<button class="btn btn-sm btn-outline-danger ms-auto" onclick="deleteAgent(${a.id})"><i class="bi bi-trash"></i></button>`:''}
        <button class="btn btn-sm btn-outline-secondary" onclick="closeAgentForm()">Close</button>
      </div>
      <div id="agent-msg" class="small mt-2"></div>
    </div>
  </div>`;
}
function agentKindChanged(){ const k=document.getElementById('ag-kind').value; document.querySelectorAll('.ag-cli').forEach(e=>e.style.display=k==='cli'?'':'none'); document.querySelectorAll('.ag-openai').forEach(e=>e.style.display=k==='openai'?'':'none'); }
function newAgent(){ document.getElementById('agent-slot').innerHTML=agentForm(null); agentKindChanged(); }
function editAgent(id){ const a=AGENTS.find(x=>x.id===id); if(!a) return; document.getElementById('agent-slot').innerHTML=agentForm(a); agentKindChanged(); }
function closeAgentForm(){ document.getElementById('agent-slot').innerHTML=''; }
function agentMsg(t,cls){ const m=document.getElementById('agent-msg'); if(m){ m.className='small mt-2 '+(cls||''); m.textContent=t; } }
async function saveAgent(id){
  const g=x=>document.getElementById(x);
  const body={ id, name:g('ag-name').value.trim(), description:g('ag-desc').value.trim(), kind:g('ag-kind').value,
    engine:g('ag-engine').value, model:g('ag-model').value.trim(), endpoint:g('ag-endpoint').value.trim(),
    timeout:g('ag-timeout').value, pre_prompt:g('ag-pre').value, api_key:g('ag-key').value,
    clear_key:(g('ag-clearkey')&&g('ag-clearkey').checked)?'1':'0', is_default:g('ag-default').checked?'1':'0' };
  try{ const d=await jpost('/pipelines/agentsave', body); if(!d.ok){ agentMsg((d.errors||[]).join(' · '),'text-danger'); return; }
    await loadAgents(); editAgent(d.agent.id); agentMsg('Saved ✓','text-success'); }
  catch(e){ agentMsg(e.message,'text-danger'); }
}
async function testAgent(id){
  agentMsg('Running…',''); 
  try{ const d=await jpost('/pipelines/agenttest',{id}); agentMsg((d.ok?'✓ ':'✗ ')+(d.ok?d.output:d.error)+' ('+d.ms+' ms'+(d.meta&&d.meta.model?', '+d.meta.model:'')+')', d.ok?'text-success':'text-danger'); }
  catch(e){ agentMsg(e.message,'text-danger'); }
}
async function deleteAgent(id){
  const a=AGENTS.find(x=>x.id===id); if(!a||!confirm('Delete agent '+a.name+'?')) return;
  try{ await jpost('/pipelines/agentdelete',{id}); closeAgentForm(); await loadAgents(); }catch(e){ agentMsg(e.message,'text-danger'); }
}
function connStyle(type){ const c=CONNECTORS.find(x=>x.connector===type); return c?c.style:(type==='shopify'?'graphql':'rest'); }

function normalize(def){
  def=def||{}; def.steps=Array.isArray(def.steps)?def.steps:[];
  def.steps.forEach(s=>{ s.config=s.config||{}; s.on_success=s.on_success||'next'; s.on_fail=s.on_fail||'exit'; if(s.__uid===undefined) uid(s); });
  def.context_schema=def.context_schema||{}; def.trigger=def.trigger||{};
  return def;
}

// ---- builder render ----
function renderBuilder(){
  if(!DEF) return;
  const settings = `
    <div class="ui-panel mb-3"><div class="ui-panel-body">
      <div class="row g-2">
        <div class="col-6"><div class="fld-label">Slug <span class="req">*</span></div>
          <input class="form-control form-control-sm" data-meta="slug" value="${esc(DEF.slug||'')}"></div>
        <div class="col-6"><div class="fld-label">Name</div>
          <input class="form-control form-control-sm" data-meta="name" value="${esc(DEF.name||'')}"></div>
        <div class="col-12"><div class="fld-label">Description</div>
          <input class="form-control form-control-sm" data-meta="description" value="${esc(DEF.description||'')}"></div>
        <div class="col-6 d-flex align-items-center gap-2 pt-2">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-meta="expose_as_tool" ${DEF.expose_as_tool?'checked':''}><label class="form-check-label small">Expose as MCP tool</label></div>
        </div>
        <div class="col-6 d-flex align-items-center gap-2 pt-2">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-meta="expose_as_api" ${DEF.expose_as_api?'checked':''}><label class="form-check-label small">Expose as REST API</label></div>
        </div>
        ${DEF.expose_as_tool?toolInfo():''}
        ${DEF.expose_as_api?apiEndpointInfo():''}
        <div class="col-12 d-flex align-items-center gap-2">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-meta="stateful" ${DEF.stateful?'checked':''}><label class="form-check-label small"><b>Durable object</b> — keep state across messages, addressed by id, with alarms</label></div>
        </div>
        ${DEF.stateful?durableInfo():''}
        <div class="col-12"${DEF.stateful?' style="display:none"':''}><div class="fld-label">Schedule</div><div id="sched"></div></div>
        <div class="col-12"><div class="fld-label">${DEF.stateful?'Message fields':'Context variables'}</div><div id="ctxRows"></div>
          <button class="btn btn-sm btn-outline-secondary mt-1" onclick="addCtxVar()"><i class="bi bi-plus"></i> variable</button></div>
      </div>
    </div></div>`;

  const rows = DEF.steps.map((s,i)=>renderRow(s,i)).join('') ||
    '<div class="p-3 small" style="color:var(--bs-tertiary-color)">No steps yet — add one below.</div>';
  const grid = `
    <div class="ss">
      <div class="ss-head ss-cols"><span></span><span>#</span><span>Name</span><span>Type</span><span>→ ok</span><span>→ fail</span><span></span></div>
      <div id="steps">${rows}</div>
    </div>`;

  const addMenu = `
    <div class="dropdown mt-2">
      <button class="btn btn-outline-primary w-100" data-bs-toggle="dropdown"><i class="bi bi-plus-lg"></i> Add step</button>
      <ul class="dropdown-menu type-menu w-100">
        ${PUBLIC_TYPES.map(t=>`<li><a class="dropdown-item" href="#" onclick="addStep('${t}');return false"><span class="t">${esc(t)}</span><div class="small" style="color:var(--bs-tertiary-color)">${esc(COMPONENTS[t].summary||'')}</div></a></li>`).join('')}
      </ul>
    </div>`;

  $('#builder').innerHTML = settings + grid + addMenu;
  initSortable(); renderCtxRows(); renderSchedule(); renderRunControls(); syncJson(); decorateCode();
}

// durable-object handler info (shown when stateful): the variable palette + writeback rules
function durableInfo(){
  return `<div class="col-12"><div class="p-2" style="background:var(--ui-surface-soft);border-radius:.6rem">
    <div class="fld-label mb-1"><i class="bi bi-box"></i> Durable object handler (onMessage / onAlarm)</div>
    <div class="small mono">{state.*} · {message.*} · {trigger} <span style="color:var(--bs-tertiary-color)">(message|alarm)</span> · {object.key}</div>
    <div class="fld-help mt-1">The last step's output object <b>merges into state</b>. Control keys: <code>__alarm</code> ("+5 minutes" | null) arms/clears an alarm; <code>__destroy</code>: true deletes the object. Reach it at <span class="mono">POST /pipeline/object/&lt;slug&gt;?key=&lt;id&gt;</span>.</div>
  </div></div>`;
}

// An expose_as_tool pipeline isn't a URL — it's AUTO-registered as the MCP tool
// tiknix:pipe_<slug> on the instance's own MCP server, found via tools/list. Show
// the identifier a client will see, not a fake endpoint.
function toolInfo(){
  const base = instApiBase();
  const slug = (DEF && DEF.slug) ? DEF.slug : 'my-pipeline';
  const name = 'tiknix:pipe_' + slug;
  const mcp  = (base||'') + '/mcp/message';
  return `<div class="col-12"><div class="p-2" style="background:var(--ui-surface-soft);border-radius:.6rem">
    <div class="fld-label mb-1"><i class="bi bi-plugin"></i> MCP tool</div>
    <div class="d-flex align-items-center gap-2">
      <code class="mono text-truncate" style="flex:1" title="${esc(name)}">${esc(name)}</code>
      <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Copy tool name" onclick="copyText('${esc(name)}',this)"><i class="bi bi-clipboard"></i></button>
    </div>
    <div class="fld-help mt-1">
      Auto-registered on this instance's MCP server${base?` (<span class="mono">${esc(mcp)}</span>)`:''} — no separate URL to call.
      Clients discover it via <span class="mono">tools/list</span> and invoke it with <span class="mono">tools/call</span> once connected with the instance's MCP key.
      Its arguments are this pipeline's context variables.
    </div>
  </div></div>`;
}

// The live REST endpoint for an expose_as_api pipeline — shown under the switch so
// there's no guessing what URL to call. Uses the selected instance's own base URL.
function apiEndpointInfo(){
  const base = instApiBase();
  const slug = (DEF && DEF.slug) ? DEF.slug : 'my-pipeline';
  const url  = (base ? base : '') + '/pipeline/api/' + slug;
  const keys = pkList();
  if (!SELECTED_PK && keys.length) SELECTED_PK = keys[0].key;
  const opts = keys.length
    ? keys.map(k=>`<option value="${esc(k.key)}"${k.key===SELECTED_PK?' selected':''}>${esc(k.label)} · ${esc(k.key.slice(0,10))}…</option>`).join('')
    : '<option value="">— no keys saved —</option>';
  return `<div class="col-12"><div class="p-2" style="background:var(--ui-surface-soft);border-radius:.6rem">
    <div class="fld-label mb-1"><i class="bi bi-hdd-network"></i> REST endpoint</div>
    <div class="d-flex align-items-center gap-2">
      <span class="ui-chip" style="padding:.05rem .4rem;font-size:.7rem">POST</span>
      <code class="mono text-truncate" style="flex:1" title="${esc(url)}">${esc(url)}</code>
      <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Copy endpoint" onclick="copyText('${esc(url)}',this)"><i class="bi bi-clipboard"></i></button>
    </div>
    <div class="d-flex align-items-center gap-2 mt-2">
      <span class="fld-label mb-0" style="min-width:2.5rem">Key</span>
      <select class="form-select form-select-sm mono" style="max-width:240px;font-size:.75rem" onchange="pkSelect(this.value)">${opts}</select>
      <button class="btn btn-sm btn-outline-success py-0 px-1" title="Mint a fresh key on this instance" onclick="pkGenerate(this)"><i class="bi bi-key"></i> Generate</button>
      <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Paste + save a pk_ key for this instance" onclick="pkAdd()"><i class="bi bi-plus-lg"></i></button>
      ${SELECTED_PK?`<button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Forget the selected key" onclick="pkForget()"><i class="bi bi-trash"></i></button>`:''}
      <button class="btn btn-sm btn-outline-secondary py-0 px-1 ms-auto" title="Copy the full curl" onclick="copyText(document.getElementById('pk-curl').textContent,this)"><i class="bi bi-clipboard"></i> Copy curl</button>
    </div>
    <pre class="mono" id="pk-curl" style="white-space:pre-wrap;word-break:break-all;background:var(--ui-surface);border-radius:.4rem;padding:.5rem;font-size:.72rem;margin:.4rem 0 0;border:1px solid var(--ui-border,#2a3350)">${esc(pkCurl())}</pre>
    <div class="fld-help mt-1">
      Keys are saved only in this browser tab, per instance — paste one with <i class="bi bi-plus-lg"></i> (mint at <span class="mono">${esc((base||'')+'/pipeline/keys')}</span> or on the workspace Integrations page). Pick a key and it fills the curl for every pipeline, so you can test the whole suite.
      Body is the context JSON; append <span class="mono">?async=1</span> for a poll-able <span class="mono">run_id</span>.
      ${base?'':'<span class="text-warning">This instance has no <span class="mono">[app] baseurl</span> set, so only the path is shown.</span>'}
    </div>
  </div></div>`;
}

// Run/Debug panel body — a message sender for durable objects, else a test-context box.
function renderRunControls(){
  const host=$('#runctl'); if(!host) return;
  if(DEF && DEF.stateful){
    host.innerHTML = `
      <label class="fld-label">Object key <span class="req">*</span></label>
      <input id="objkey" class="form-control form-control-sm mono mb-2" placeholder="e.g. conversation-42" value="demo">
      <label class="fld-label">Trigger</label>
      <select id="trigger" class="form-select form-select-sm mb-2"><option value="message">message</option><option value="alarm">alarm</option></select>
      <label class="fld-label">Message (JSON)</label>
      <textarea id="ctx" class="form-control form-control-sm code mb-1" rows="3" spellcheck="false">{}</textarea>
      <button class="btn btn-sm btn-success w-100" onclick="deliverMsg()"><i class="bi bi-send"></i> Send</button>`;
  } else {
    host.innerHTML = `<label class="fld-label">Test context (JSON)</label>
      <textarea id="ctx" class="form-control form-control-sm code mb-2" rows="3" spellcheck="false">{}</textarea>`;
  }
}

// ---- schedule (cron) builder — friendly UI over the stored 5-field cron ----
const DOW=['Su','Mo','Tu','We','Th','Fr','Sa'];
const SCHED_DEFAULT={off:'',minutes:'*/15 * * * *',hours:'0 */1 * * *',daily:'0 9 * * *',weekly:'0 9 * * 1',monthly:'0 9 1 * *'};
function pad2(n){ return String(n).padStart(2,'0'); }
function hmToTime(h,m){ return pad2(h||0)+':'+pad2(m||0); }
function parseCron(expr){
  expr=(expr||'').trim(); if(!expr) return {mode:'off'};
  const f=expr.split(/\s+/); if(f.length!==5) return {mode:'custom', raw:expr};
  const [mi,ho,dom,mon,dow]=f, num=s=>/^\d+$/.test(s), star=(...a)=>a.every(x=>x==='*'); let m;
  if((m=mi.match(/^\*\/(\d+)$/)) && star(ho,dom,mon,dow)) return {mode:'minutes', n:+m[1]};
  if(mi==='0' && (m=ho.match(/^\*\/(\d+)$/)) && star(dom,mon,dow)) return {mode:'hours', n:+m[1]};
  if(num(mi)&&num(ho)&&star(dom,mon,dow)) return {mode:'daily', h:+ho, m:+mi};
  if(num(mi)&&num(ho)&&dom==='*'&&mon==='*'&&/^[0-6](,[0-6])*$/.test(dow)) return {mode:'weekly', h:+ho, m:+mi, days:dow.split(',').map(Number)};
  if(num(mi)&&num(ho)&&num(dom)&&mon==='*'&&dow==='*') return {mode:'monthly', h:+ho, m:+mi, dom:+dom};
  return {mode:'custom', raw:expr};
}
function buildCron(s){
  switch(s.mode){
    case 'minutes': return `*/${s.n||15} * * * *`;
    case 'hours':   return `0 */${s.n||1} * * *`;
    case 'daily':   return `${s.m||0} ${s.h||0} * * *`;
    case 'weekly':  { const d=(s.days&&s.days.length?s.days:[1]).slice().sort((a,b)=>a-b); return `${s.m||0} ${s.h||0} * * ${d.join(',')}`; }
    case 'monthly': return `${s.m||0} ${s.h||0} ${s.dom||1} * *`;
    case 'custom':  return (s.raw||'').trim();
    default:        return '';
  }
}
function cronEnglish(s){
  switch(s.mode){
    case 'off':     return 'no schedule — runs on manual / API / trigger only';
    case 'minutes': return `every ${s.n} minute${s.n==1?'':'s'}`;
    case 'hours':   return `every ${s.n} hour${s.n==1?'':'s'}`;
    case 'daily':   return `daily at ${hmToTime(s.h,s.m)}`;
    case 'weekly':  { const d=(s.days||[]).slice().sort((a,b)=>a-b).map(x=>DOW[x]); return `weekly on ${d.join(', ')||'—'} at ${hmToTime(s.h,s.m)}`; }
    case 'monthly': return `monthly on day ${s.dom} at ${hmToTime(s.h,s.m)}`;
    default:        return 'custom expression';
  }
}
function validCronClient(expr){ const f=(expr||'').trim().split(/\s+/); if(f.length!==5) return false; const R=[[0,59],[0,23],[1,31],[1,12],[0,7]];
  return f.every((spec,i)=>spec.split(',').every(part=>{ if(part==='') return false; let p=part; if(p.includes('/')){ const [pp,st]=p.split('/'); if(!/^\d+$/.test(st)||+st<1) return false; p=pp; } if(p==='*') return true; if(p.includes('-')){ const [a,b]=p.split('-'); return /^\d+$/.test(a)&&/^\d+$/.test(b)&&+a>=R[i][0]&&+b<=R[i][1]&&+a<=+b; } return /^\d+$/.test(p)&&+p>=R[i][0]&&+p<=R[i][1]; })); }
function renderSchedule(){
  const host=$('#sched'); if(!host||!DEF) return;
  const cur=(DEF.trigger&&DEF.trigger.cron)||'';
  const s = schedForceCustom ? {mode:'custom', raw:cur} : parseCron(cur);
  const modes=[['off','Off — manual / API only'],['minutes','Every N minutes'],['hours','Every N hours'],['daily','Daily at…'],['weekly','Weekly on…'],['monthly','Monthly on…'],['custom','Custom cron']];
  const modeSel=`<select id="schedMode" class="form-select form-select-sm" style="max-width:13rem">${modes.map(([v,l])=>`<option value="${v}" ${s.mode===v?'selected':''}>${l}</option>`).join('')}</select>`;
  let ctl='';
  if(s.mode==='minutes'||s.mode==='hours'){
    const base=(s.mode==='minutes'?[1,2,5,10,15,20,30]:[1,2,3,4,6,8,12]); if(s.n&&!base.includes(s.n)) base.push(s.n);
    ctl=`Every <select id="schedN" class="form-select form-select-sm d-inline-block" style="width:auto">${base.sort((a,b)=>a-b).map(o=>`<option ${o===s.n?'selected':''}>${o}</option>`).join('')}</select> ${s.mode}`;
  } else if(s.mode==='daily'){
    ctl=`at <input type="time" id="schedTime" class="form-control form-control-sm d-inline-block" style="width:auto" value="${hmToTime(s.h,s.m)}">`;
  } else if(s.mode==='weekly'){
    ctl=`<div class="btn-group btn-group-sm mb-2" role="group">${DOW.map((d,i)=>`<button type="button" class="btn btn-outline-secondary sched-day ${(s.days||[]).includes(i)?'active':''}" data-day="${i}">${d}</button>`).join('')}</div><div>at <input type="time" id="schedTime" class="form-control form-control-sm d-inline-block" style="width:auto" value="${hmToTime(s.h,s.m)}"></div>`;
  } else if(s.mode==='monthly'){
    ctl=`on day <input type="number" id="schedDom" class="form-control form-control-sm d-inline-block" style="width:5rem" min="1" max="31" value="${s.dom||1}"> at <input type="time" id="schedTime" class="form-control form-control-sm d-inline-block" style="width:auto" value="${hmToTime(s.h,s.m)}">`;
  } else if(s.mode==='custom'){
    ctl=`<input id="schedRaw" class="form-control form-control-sm mono" placeholder="*/15 * * * *" value="${esc(s.raw||(DEF.trigger&&DEF.trigger.cron)||'')}">`;
  }
  host.innerHTML=`${modeSel}<div id="schedCtl" class="mt-2">${ctl}</div><div class="fld-help mt-1" id="schedOut"></div>`;
  $('#schedMode').onchange=e=>{ DEF.trigger=DEF.trigger||{}; const mode=e.target.value;
    if(mode==='custom'){ schedForceCustom=true; if(!(DEF.trigger.cron||'').trim()) DEF.trigger.cron='*/15 * * * *'; }
    else { schedForceCustom=false; DEF.trigger.cron=SCHED_DEFAULT[mode]; }
    renderSchedule(); };
  host.querySelectorAll('#schedN,#schedTime,#schedDom,#schedRaw').forEach(el=>el.addEventListener('input',syncSchedule));
  host.querySelectorAll('.sched-day').forEach(b=>b.addEventListener('click',()=>{ b.classList.toggle('active'); syncSchedule(); }));
  syncSchedule();
}
function readSchedState(){
  const mode=$('#schedMode')?$('#schedMode').value:'off', s={mode};
  if(mode==='minutes'||mode==='hours'){ s.n=parseInt($('#schedN').value,10)||1; }
  else if(mode==='daily'||mode==='weekly'||mode==='monthly'){ const t=($('#schedTime')?$('#schedTime').value:'00:00').split(':'); s.h=parseInt(t[0],10)||0; s.m=parseInt(t[1],10)||0;
    if(mode==='weekly') s.days=[...document.querySelectorAll('.sched-day.active')].map(b=>+b.dataset.day);
    if(mode==='monthly') s.dom=parseInt($('#schedDom').value,10)||1; }
  else if(mode==='custom'){ s.raw=$('#schedRaw')?$('#schedRaw').value:''; }
  return s;
}
function syncSchedule(){
  const s=readSchedState(), cron=buildCron(s);
  DEF.trigger=DEF.trigger||{}; if(cron==='') delete DEF.trigger.cron; else DEF.trigger.cron=cron;
  const out=$('#schedOut');
  if(out){
    if(s.mode==='off') out.innerHTML=cronEnglish(s);
    else if(s.mode==='custom') out.innerHTML = validCronClient(cron) ? `<span class="mono">${esc(cron)}</span> · custom` : `<span class="text-danger">invalid cron — need 5 fields (min hour day month weekday)</span>`;
    else out.innerHTML=`<span class="mono">${esc(cron)}</span> · ${esc(cronEnglish(s))}`;
  }
  syncJson();
}

function flowOptions(step,i,which){
  const others=DEF.steps.filter((_,k)=>k!==i).map(s=>s.name);
  const val=step[which]|| (which==='on_success'?'next':'exit');
  const opts=['next','exit',...others.map(n=>'goto:'+n)];
  if(String(val).startsWith('goto:') && !others.includes(String(val).slice(5))) opts.push(val);
  return `<select class="form-select form-select-sm no-toggle" data-si="${i}" data-flow="${which}">
    ${opts.map(o=>`<option value="${esc(o)}" ${o===val?'selected':''}>${esc(o)}</option>`).join('')}</select>`;
}

function summarize(step){
  const c=step.config||{}, t=step.type;
  if(t==='branch') return `${c.left||''} ${c.op||''} ${c.right??''}`.trim();
  if(t==='connection'){ const a=c.arguments||{}; if(!c.connector) return ''; return c.tool==='graphql' ? `${c.connector} · graphql` : `${c.connector} · ${(a.method||'GET')} ${a.path||''}`.trim(); }
  if(t==='http') return `${c.method||'GET'} ${c.url||''}`.trim();
  if(t==='transform') return `${c.mode||''}${c.input!=null?': '+trunc(c.input):''}`;
  const comp=COMPONENTS[t]||{fields:[]};
  for(const f of (comp.fields||[])){ if(c[f.name]!=null && c[f.name]!=='') return `${f.name}: ${trunc(c[f.name])}`; }
  return '';
}

function renderRow(step,i){
  const comp=COMPONENTS[step.type]||{fields:[]};
  const open=step.__uid===OPEN;
  const cardBody = step.type==='connection'
    ? renderConnCard(step,i)
    : '<div class="row g-2">'+(comp.fields||[]).map(f=>renderField(f,step.config[f.name],i)).join('')+'</div>';
  const typeOpts=TYPES.filter(t=>!COMPONENTS[t].internal||t===step.type).map(t=>`<option value="${t}" ${t===step.type?'selected':''}>${t}</option>`).join('');
  const sum=summarize(step);
  return `<div class="ss-row-wrap ${open?'open':''}" data-uid="${step.__uid}">
    <div class="ss-row ss-cols">
      <span class="drag" title="drag to reorder"><i class="bi bi-grip-vertical"></i></span>
      <span class="idx">${i+1}</span>
      <input class="form-control form-control-sm no-toggle" data-si="${i}" data-meta="name" value="${esc(step.name||'')}">
      <select class="form-select form-select-sm no-toggle" data-si="${i}" data-meta="type">${typeOpts}</select>
      ${flowOptions(step,i,'on_success')}
      ${flowOptions(step,i,'on_fail')}
      <button class="ui-btn-icon no-toggle" style="width:26px;height:26px" title="Delete step" onclick="removeStep(${i})"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="ss-summary">${sum?esc(sum):'<span style="opacity:.55">— click to configure —</span>'}</div>
    <div class="ss-card" ${open?'':'hidden'}>${open?varChips(i):''}${cardBody}</div>
  </div>`;
}

// The connection step's Postman-style card: pick a connector (keys hidden), then an
// adaptive request form — Shopify → GraphQL query + variables; REST → method/path/body.
function renderConnCard(step,i){
  const c=step.config||{};
  if(!CONNECTORS.length){
    return `<div class="small" style="color:var(--bs-tertiary-color)">No connectors on this instance. Connect a store/account in tiknix (Connections), or use the <code>http</code> step for a plain call.</div>`;
  }
  const cur = (c.connector!=null) ? (c.connector+'|'+(c.environment||'')) : '';
  const opts = `<option value="">— pick a connector —</option>` + CONNECTORS.map(x=>{
    const v=x.connector+'|'+x.environment;
    return `<option value="${esc(v)}" ${v===cur?'selected':''}>${esc(x.name)} · ${esc(x.connector)} (${esc(x.environment)})</option>`;
  }).join('');
  let form='';
  if(c.connector){
    const style = c.tool==='graphql' ? 'graphql' : (c.tool==='request' ? 'rest' : connStyle(c.connector));
    const a=c.arguments||{};
    if(style==='graphql'){
      form=`
        <div class="col-12"><div class="fld-label">GraphQL query <span class="req">*</span></div>
          <textarea class="form-control form-control-sm code" rows="5" data-si="${i}" data-conn="query" placeholder="query($ids:[ID!]!){ nodes(ids:$ids){ id } }">${esc(a.query||'')}</textarea>
          <div class="fld-help">Runs against the connected store's Admin GraphQL API — host, version & auth injected server-side.</div></div>
        <div class="col-12"><div class="fld-label">Variables</div>
          <textarea class="form-control form-control-sm code" rows="2" data-si="${i}" data-conn="variables" placeholder='{"ids":"{context.item_ids}"}'>${esc(a.variables&&Object.keys(a.variables).length?JSON.stringify(a.variables):'')}</textarea>
          <div class="fld-help">JSON object → GraphQL variables. Values may reference {context.x}.</div></div>`;
    } else {
      const methods=['GET','POST','PUT','PATCH','DELETE'];
      form=`
        <div class="col-4"><div class="fld-label">Method</div>
          <select class="form-select form-select-sm" data-si="${i}" data-conn="method">${methods.map(m=>`<option ${((a.method||'GET')===m)?'selected':''}>${m}</option>`).join('')}</select></div>
        <div class="col-8"><div class="fld-label">Path <span class="req">*</span></div>
          <input class="form-control form-control-sm mono" data-si="${i}" data-conn="path" placeholder="/v1/charges" value="${esc(a.path||'')}"></div>
        <div class="col-12"><div class="fld-label">Body</div>
          <textarea class="form-control form-control-sm code" rows="3" data-si="${i}" data-conn="body" placeholder='{"amount":"{context.amount}","currency":"usd"}'>${esc(a.body&&Object.keys(a.body).length?JSON.stringify(a.body,null,0):'')}</textarea>
          <div class="fld-help">JSON params — form-encoded for writes, query-string for GET. Values may reference {context.x}.</div></div>`;
    }
  }
  return `<div class="row g-2">
    <div class="col-12"><div class="fld-label">Connector <span class="req">*</span></div>
      <select class="form-select form-select-sm" data-si="${i}" data-conn="connector">${opts}</select></div>
    ${form}
  </div>`;
}

function renderField(f,val,i){
  const req=f.required?' <span class="req">*</span>':'';
  const lab=`<div class="fld-label">${esc(f.label||f.name)}${req}</div>`;
  const help=f.help?`<div class="fld-help">${esc(f.help)}</div>`:'';
  const da=`data-si="${i}" data-field="${f.name}" data-ftype="${f.type}"`;
  const col = (f.type==='textarea'||f.type==='keyval'||f.type==='list') ? 'col-12' : 'col-6';
  let input='';
  if(f.type==='textarea'){ input=`<textarea class="form-control form-control-sm code" rows="2" ${da}>${esc(val||'')}</textarea>`; }
  else if(f.type==='number'){ input=`<input type="number" class="form-control form-control-sm" ${da} value="${val==null?'':esc(val)}">`; }
  else if(f.type==='bool'){ return `<div class="col-6"><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" ${da} ${val?'checked':''}><label class="form-check-label fld-label">${esc(f.label||f.name)}</label></div>${help}</div>`; }
  else if(f.type==='select'){
    // A dynamic select takes its options from a list the page loaded (agents), not the schema.
    const opts = f.dynamic==='agents' ? AGENTS.map(a=>a.name) : (f.options||[]);
    const blank = f.dynamic==='agents' ? (AGENTS.some(a=>a.is_default) ? '— default: '+AGENTS.find(a=>a.is_default).name+' —' : (AGENTS.length?'— pick an agent —':'— no agents configured (install engine) —')) : '—';
    input=`<select class="form-select form-select-sm" ${da}><option value="">${esc(blank)}</option>${opts.map(o=>`<option value="${esc(o)}" ${o===val?'selected':''}>${esc(o)}</option>`).join('')}</select>`;
  }
  else if(f.type==='keyval'){ input=`<textarea class="form-control form-control-sm code" rows="2" placeholder='{"key":"value"}' ${da}>${esc(val&&Object.keys(val).length?JSON.stringify(val):'')}</textarea>`; }
  else if(f.type==='list'){ input=`<textarea class="form-control form-control-sm code" rows="2" placeholder="one value per line" ${da}>${esc(Array.isArray(val)?val.join('\n'):'')}</textarea>`; }
  else { input=`<input class="form-control form-control-sm" ${da} value="${esc(val==null?'':val)}">`; }
  return `<div class="${col}">${lab}${input}${help}</div>`;
}

// ---- drag reorder ----
function initSortable(){
  const el=document.getElementById('steps');
  if(!el || typeof Sortable==='undefined') return;
  if(sortable){ try{ sortable.destroy(); }catch(e){} }
  sortable=Sortable.create(el,{ handle:'.drag', animation:150, ghostClass:'ss-ghost', draggable:'.ss-row-wrap',
    onEnd:()=>{
      const order=[...el.querySelectorAll('.ss-row-wrap')].map(w=>w.dataset.uid);
      DEF.steps.sort((a,b)=>order.indexOf(String(a.__uid))-order.indexOf(String(b.__uid)));
      renderBuilder();
    } });
}

// ---- expand / collapse (accordion) ----
function toggleRow(u){ OPEN = (OPEN===u)?null:u;
  document.querySelectorAll('#steps .ss-row-wrap').forEach(w=>{
    const on = String(OPEN)===w.dataset.uid;
    w.classList.toggle('open',on);
    const card=w.querySelector('.ss-card'); if(card) card.hidden=!on;
    if(on&&card) card.querySelectorAll('textarea.code').forEach(t=>t._fit&&t._fit());   // re-measure now that it's visible
  });
}

// ---- builder events (delegated) ----
$('#builder') && $('#builder').addEventListener('input', onBuilderChange);
$('#builder') && $('#builder').addEventListener('change', onBuilderChange);
$('#builder') && $('#builder').addEventListener('click', e=>{
  if(e.target.closest('.ss-card')) return;                                          // clicks inside an open card
  if(e.target.closest('input,select,textarea,button,a,label,.no-toggle')) return;   // interactive controls
  const wrap=e.target.closest('.ss-row-wrap'); if(!wrap) return;
  toggleRow(parseInt(wrap.dataset.uid,10));
});
function onBuilderChange(e){
  const t=e.target; if(!DEF) return;
  const meta=t.getAttribute('data-meta'), si=t.getAttribute('data-si'), field=t.getAttribute('data-field'), flow=t.getAttribute('data-flow'), conn=t.getAttribute('data-conn');
  if(meta && si===null){   // top-level meta
    if(meta==='stateful'){ DEF.stateful=t.checked; renderBuilder(); return; }
    if(meta==='expose_as_api'){ DEF.expose_as_api=t.checked; syncJson(); renderBuilder(); return; }
    if(meta==='expose_as_tool'){ DEF.expose_as_tool=t.checked; syncJson(); renderBuilder(); return; }
    else if(meta==='cron'){ DEF.trigger=DEF.trigger||{}; DEF.trigger.cron=t.value; }
    else { DEF[meta]=t.value; }
    syncJson(); return;
  }
  const i=parseInt(si,10); if(isNaN(i)||!DEF.steps[i]) return;
  if(meta==='name'){ DEF.steps[i].name=t.value; syncJson(); if(e.type==='change') rerenderFlows(); return; }
  if(meta==='type'){ DEF.steps[i].type=t.value; DEF.steps[i].config={}; OPEN=DEF.steps[i].__uid; renderBuilder(); return; }
  if(flow){ DEF.steps[i][flow]=t.value; syncJson(); return; }
  if(conn){ setConnField(i,conn,t); return; }
  if(field){ setStepField(i,field,t); refreshSummary(i); syncJson(); return; }
}
function setConnField(i,key,el){
  const step=DEF.steps[i]; step.config=step.config||{};
  if(key==='connector'){
    const [type,env]=(el.value||'').split('|');
    const typeChanged = type !== step.config.connector;   // env-only change keeps the request
    if(!type){ delete step.config.connector; delete step.config.environment; delete step.config.tool; step.config.arguments={}; }
    else { step.config.connector=type; step.config.environment=env||'';
      step.config.tool = connStyle(type)==='graphql' ? 'graphql' : 'request';
      if(typeChanged || !step.config.arguments) step.config.arguments={};   // reset the shape on a type switch
      if(step.config.tool==='request' && step.config.arguments.method==null) step.config.arguments.method='GET'; }
    OPEN=step.__uid; renderBuilder(); return;   // switch the adaptive form
  }
  const a = step.config.arguments = step.config.arguments||{};
  if(key==='query'){ if(el.value) a.query=el.value; else delete a.query; }
  else if(key==='method'){ a.method=el.value; }
  else if(key==='path'){ if(el.value) a.path=el.value; else delete a.path; }
  else if(key==='variables'||key==='body'){ const v=el.value.trim(); if(!v){ delete a[key]; el.classList.remove('is-invalid'); } else { try{ a[key]=JSON.parse(v); el.classList.remove('is-invalid'); }catch(_){ el.classList.add('is-invalid'); } } }
  refreshSummary(i); syncJson();
}
function setStepField(i,name,el){
  const ftype=el.getAttribute('data-ftype'); const cfg=DEF.steps[i].config;
  if(ftype==='bool'){ cfg[name]=el.checked; return; }
  if(ftype==='number'){ if(el.value==='') delete cfg[name]; else cfg[name]=Number(el.value); return; }
  if(ftype==='keyval'){ const v=el.value.trim(); if(!v){ delete cfg[name]; el.classList.remove('is-invalid'); return; } try{ cfg[name]=JSON.parse(v); el.classList.remove('is-invalid'); }catch(_){ el.classList.add('is-invalid'); } return; }
  if(ftype==='list'){ const arr=el.value.split('\n').map(s=>s.trim()).filter(Boolean); if(arr.length) cfg[name]=arr; else delete cfg[name]; return; }
  if(el.value==='') delete cfg[name]; else cfg[name]=el.value;
}
function refreshSummary(i){ const w=document.querySelector(`#steps .ss-row-wrap[data-uid="${DEF.steps[i].__uid}"] .ss-summary`);
  if(w){ const s=summarize(DEF.steps[i]); w.innerHTML = s?esc(s):'<span style="opacity:.55">— click to configure —</span>'; } }
function rerenderFlows(){ DEF.steps.forEach((s,i)=>['on_success','on_fail'].forEach(w=>{ const sel=document.querySelector(`#steps select[data-si="${i}"][data-flow="${w}"]`); if(sel) sel.outerHTML=flowOptions(s,i,w); })); }

function addStep(type){ const n=DEF.steps.length+1; const s=uid({name:type+n, type:type, config:{}, on_success:'next', on_fail:'exit'}); DEF.steps.push(s); OPEN=s.__uid; renderBuilder(); }
function removeStep(i){ DEF.steps.splice(i,1); renderBuilder(); }

// ---- context schema rows ----
function renderCtxRows(){
  const host=$('#ctxRows'); if(!host) return; host.innerHTML='';
  Object.entries(DEF.context_schema||{}).forEach(([k,spec])=>{
    spec=spec||{}; const row=document.createElement('div'); row.className='d-flex gap-1 mb-1';
    row.innerHTML=`<input class="form-control form-control-sm" value="${esc(k)}" data-ck="name" placeholder="name">
      <select class="form-select form-select-sm" data-ck="type" style="max-width:6.5rem">
        ${['string','number','boolean','object'].map(t=>`<option ${((spec.type||'string')===t)?'selected':''}>${t}</option>`).join('')}</select>
      <div class="form-check form-switch d-flex align-items-center" title="required"><input class="form-check-input" type="checkbox" data-ck="required" ${spec.required?'checked':''}></div>
      <button class="ui-btn-icon" style="width:30px;height:30px" onclick="delCtxVar('${esc(k)}')"><i class="bi bi-x"></i></button>`;
    host.appendChild(row);
  });
  host.querySelectorAll('[data-ck]').forEach(el=> el.addEventListener('change', syncCtxVars));
}
function syncCtxVars(){
  const rows=$('#ctxRows').querySelectorAll('.d-flex'); const out={};
  rows.forEach(r=>{ const name=r.querySelector('[data-ck="name"]').value.trim(); if(!name) return;
    out[name]={ type:r.querySelector('[data-ck="type"]').value, required:r.querySelector('[data-ck="required"]').checked }; });
  DEF.context_schema=out; syncJson();
}
function addCtxVar(){ DEF.context_schema=DEF.context_schema||{}; let n='var',i=1; while(DEF.context_schema[n]) n='var'+(++i); DEF.context_schema[n]={type:'string',required:false}; renderCtxRows(); syncJson(); }
function delCtxVar(k){ delete DEF.context_schema[k]; renderCtxRows(); syncJson(); }

// ---- JSON advanced ----
function syncJson(){ const b=$('#jsonBox'); if(b) b.value=JSON.stringify(DEF,null,2); }
function toggleJson(){ const w=$('#jsonWrap'); w.style.display = w.style.display==='none' ? 'block':'none'; }
function applyJson(){ try{ DEF=normalize(JSON.parse($('#jsonBox').value)); OPEN=null; schedForceCustom=false; renderBuilder(); msg('Applied JSON ✓','success'); }catch(e){ msg('Invalid JSON: '+e.message,'danger'); } }

// ---- validate / save / delete ----
async function validateDef(){ if(!DEF) return;
  try{ const d=await jpost('/pipelines/validate',{def:JSON.stringify(DEF)});
    d.valid? msg('Valid ✓','success') : msg('Invalid: '+d.errors.join('; '),'danger'); }catch(e){ msg(e.message,'danger'); } }
async function saveDef(){ if(!DEF) return;
  try{ const d=await jpost('/pipelines/save',{def:JSON.stringify(DEF)});
    if(d.ok){ CURRENT=DEF.slug; msg('Saved '+d.file+' ✓','success'); loadList(); } else msg('Not saved: '+(d.errors||[]).join('; '),'danger');
  }catch(e){ msg(e.message,'danger'); } }
async function deleteDef(){ if(!CURRENT||!confirm('Delete '+CURRENT+'?')) return;
  try{ await jpost('/pipelines/delete',{slug:CURRENT}); CURRENT=null; DEF=null; $('#builder').innerHTML=''; loadList(); msg('Deleted.','secondary'); }catch(e){ msg(e.message,'danger'); } }

// ---- durable object: deliver a message / alarm ----
async function deliverMsg(){
  if(!DEF||!DEF.slug){ msg('Save first.','warning'); return; }
  const key=$('#objkey')?$('#objkey').value.trim():''; if(!key){ msg('An object key is required.','warning'); return; }
  const trigger=$('#trigger')?$('#trigger').value:'message';
  let message={}; const raw=$('#ctx')?$('#ctx').value.trim():''; if(raw){ try{ message=JSON.parse(raw); }catch(e){ msg('Message is not valid JSON.','danger'); return; } }
  try{ await saveDef();
    const d=await jpost('/pipelines/deliver',{slug:DEF.slug,key:key,trigger:trigger,message:JSON.stringify(message)});
    renderDelivery(d);
  }catch(e){ msg(e.message,'danger'); }
}
function renderDelivery(d){
  const badge = d.busy?'<span class="st-failed">busy</span>':(d.ok?'<span class="st-completed">ok</span>':'<span class="st-failed">'+esc(d.status||'error')+'</span>');
  let h=`<div class="mb-1"><b>${esc(d.trigger||'message')}</b> → <span class="mono">${esc(d.key||'')}</span> ${badge}</div>`;
  if(d.destroyed){ h+=`<div class="small st-failed">object destroyed</div>`; }
  else { h+=`<div class="small" style="color:var(--bs-tertiary-color)">state</div><pre class="io">${esc(JSON.stringify(d.state,null,2))}</pre>`;
    if(d.wake_at) h+=`<div class="fld-help">⏰ alarm armed (wake_at ${esc(d.wake_at)})</div>`; }
  $('#runbox').innerHTML=h;
}

// ---- normal run + watch ----
async function runDef(){ if(!DEF||!DEF.slug){ msg('Save first.','warning'); return; }
  if(DEF.stateful){ return deliverMsg(); }
  try{ await saveDef(); const d=await jpost('/pipelines/run',{slug:DEF.slug,context:$('#ctx').value||'{}'}); watchRun(d.run_id); }catch(e){ msg(e.message,'danger'); } }
function watchRun(runId){
  if(watchTimer) clearInterval(watchTimer); DEBUG=null;
  const render=r=>{ let h=`<div class="mb-1"><b>Run #${runId}</b> <span class="st-${r.status}">${esc(r.status)}</span> <span style="color:var(--bs-tertiary-color)">${r.steps_done}/${r.steps_total}</span></div>`;
    (r.steps||[]).forEach(s=>{
      h+=`<div class="st-${s.status}">${s.status==='completed'?'✓':s.status==='failed'?'✗':'…'} <b>${esc(s.step)}</b> <span style="color:var(--bs-tertiary-color)">[${esc(s.type)}]${s.exit?' exit '+s.exit:''} ${s.duration_ms||0}ms</span></div>`;
      if(s.stderr && (s.status==='failed'||s.exit)) h+=`<pre class="io st-failed">${esc(s.stderr)}</pre>`;   // WHY it failed
    });
    if(r.await_prompt) h+=`<div class="st-awaiting mt-1">⏸ awaiting: ${esc(r.await_prompt)}</div>`;
    if(r.error) h+=`<div class="st-failed small mt-1"><b>Failed:</b> ${esc(r.error)}</div>`;
    if(r.output!=null) h+=`<div class="small mt-2" style="color:var(--bs-tertiary-color)">output</div><pre class="io">${esc(JSON.stringify(r.output,null,2))}</pre>`;
    $('#runbox').innerHTML=h; };
  const poll=async()=>{ try{ const r=await jget('/pipelines/runstatus?run_id='+runId); render(r);
    if(['completed','failed','paused'].includes(r.status)){ clearInterval(watchTimer); watchTimer=null; } }catch(e){} };
  $('#runbox').innerHTML='<div class="small" style="color:var(--bs-tertiary-color)">Starting…</div>'; poll(); watchTimer=setInterval(poll,1000);
}

// ---- debugger ----
async function debugStart(){ if(!DEF||!DEF.slug){ msg('Save first.','warning'); return; }
  try{ await saveDef(); $('#runbox').innerHTML='<div class="small" style="color:var(--bs-tertiary-color)">Starting debug…</div>';
    const bp=await jpost('/pipelines/debug',{slug:DEF.slug,context:$('#ctx').value||'{}'}); renderDebug(bp);
  }catch(e){ msg(e.message,'danger'); $('#runbox').innerHTML=''; } }
function dbgMsg(t){ const dm=document.getElementById('debugMsg'); if(dm) dm.textContent=t; else msg(t,'danger'); }
async function debugAct(action){
  const patch=$('#patchBox')?$('#patchBox').value.trim():'';
  let parsed={}; if(patch){ try{ parsed=JSON.parse(patch); }catch(e){ dbgMsg('Inject data is not valid JSON.'); return; } }
  try{ const bp=await jpost('/pipelines/debugstep',{run_id:DEBUG.run_id,action:action,patch:JSON.stringify(parsed)}); renderDebug(bp); }
  catch(e){ dbgMsg(e.message); }
}
function highlightDebugRow(stepName){
  document.querySelectorAll('#steps .ss-row-wrap').forEach(w=>{ const nm=w.querySelector('[data-meta="name"]'); w.classList.toggle('dbg-cur', !!stepName && nm && nm.value===stepName); });
}
function renderDebug(bp){
  DEBUG=bp;
  const paused=bp.debug && bp.status==='paused';
  highlightDebugRow(paused?bp.last_step:null);
  let h=`<div class="mb-2"><b>Debug #${bp.run_id}</b> <span class="st-${bp.status}">${esc(bp.status)}</span> <span style="color:var(--bs-tertiary-color)">${bp.steps_done}/${bp.steps_total}</span></div>`;
  (bp.steps||[]).forEach(s=>{
    const cur=paused && s.step===bp.last_step;
    h+=`<div class="trace-step ${cur?'cur':''}"><div><span class="st-${s.status}">${s.status==='completed'?'✓':s.status==='failed'?'✗':'…'}</span> <b>${esc(s.step)}</b> <span style="color:var(--bs-tertiary-color)">[${esc(s.type)}] ${s.duration_ms||0}ms</span></div>`;
    if(cur){
      if(s.input!=null) h+=`<div class="small mt-1" style="color:var(--bs-tertiary-color)">resolved input</div><pre class="io">${esc(JSON.stringify(s.input,null,2))}</pre>`;
      if(s.output!=null) h+=`<div class="small" style="color:var(--bs-tertiary-color)">output</div><pre class="io">${esc(JSON.stringify(s.output,null,2))}</pre>`;
      if(s.stderr) h+=`<pre class="io st-failed">${esc(s.stderr)}</pre>`;
    } else if(s.status==='failed' && s.stderr){   // surface WHY at the step that failed
      h+=`<pre class="io st-failed">${esc(s.stderr)}</pre>`;
    }
    h+=`</div>`;
  });
  if(paused){
    const nextType=(DEF.steps.find(x=>x.name===bp.next_step)||{}).type;
    const hint=(COMPONENTS[nextType]&&COMPONENTS[nextType].fields||[]).map(f=>`<code>${esc(f.name)}</code>`).join(' · ');
    h+=`<div class="mt-2 p-2" style="background:var(--ui-surface-soft);border-radius:.6rem">
      <div class="fld-label">Next: <b>${esc(bp.next_step||'—')}</b>${nextType?` <span style="color:var(--bs-tertiary-color)">[${esc(nextType)}]</span>`:''}</div>
      ${hint?`<div class="fld-help mb-1">consumes: ${hint}</div>`:''}
      <label class="fld-label">Inject data (merged into the bag)</label>
      <textarea id="patchBox" class="form-control form-control-sm code" rows="3" placeholder='{"context":{"key":"value"}}'></textarea>
      <details class="mt-1"><summary class="small" style="color:var(--bs-tertiary-color)">current data (bag)</summary><pre class="io bag-view">${esc(JSON.stringify(bp.bag,null,2))}</pre></details>
      <div id="debugMsg" class="st-failed small mt-1"></div>
      <div class="d-flex gap-1 mt-2">
        <button class="btn btn-sm btn-warning" onclick="debugAct('step')"><i class="bi bi-skip-end"></i> Step</button>
        <button class="btn btn-sm btn-success" onclick="debugAct('end')"><i class="bi bi-play-fill"></i> Continue</button>
        <button class="btn btn-sm btn-outline-danger ms-auto" onclick="debugAct('abort')"><i class="bi bi-x-lg"></i> Abort</button>
      </div></div>`;
  } else if(bp.output!=null){
    h+=`<div class="small mt-2" style="color:var(--bs-tertiary-color)">final output</div><pre class="io">${esc(JSON.stringify(bp.output,null,2))}</pre>`;
  }
  if(bp.error) h+=`<div class="st-failed small mt-1">${esc(bp.error)}</div>`;
  $('#runbox').innerHTML=h;
}

// ============ variable autocomplete + chips ============
// Suggestions come from: (1) the pipeline's context_schema, (2) each PRIOR step's output —
// static reserved keys always, upgraded to the real field tree once a run exists (SHAPES,
// team-shared + PII-safe) or a live debug trace is open (DEBUG.bag), (3) time/run built-ins.
// Tokens mirror the runtime bag exactly: {greet.data.shop.name} == {prev.data.shop.name}.
let SHAPES = {};      // persisted per-step OUTPUT shapes (keys/types), team-shared, PII-safe
let VAC = null;       // active autocomplete popup state
let VACLAST = null;   // last eligible field focused (chip-insert target)

async function loadShapes(){
  if(!CURRENT){ SHAPES={}; return; }
  try{ const d=await jget('/pipelines/varshapes?slug='+encodeURIComponent(CURRENT));
       SHAPES=(d&&d.shapes)?d.shapes:{}; }catch(e){ SHAPES={}; }
  if(DEF) renderBuilder();
}
function leafNode(t){ return {type:t, children:{}, leaf:true}; }
function jsShape(v){   // a live bag value → walkable node
  if(Array.isArray(v)) return {type:'array', children:{'0':jsShape(v.length?v[0]:null)}};
  if(v&&typeof v==='object'){ const ch={}; let n=0; for(const k in v){ if(++n>80) break; ch[k]=jsShape(v[k]); } return {type:'object', children:ch}; }
  return leafNode(v===null?'null':typeof v);
}
function srvNode(sh){   // server shape {t,keys|of} → node
  if(!sh||typeof sh!=='object') return leafNode('any');
  if(sh.t==='object'){ const ch={}, K=sh.keys||{}; for(const k in K) ch[k]=srvNode(K[k]); return {type:'object', children:ch}; }
  if(sh.t==='array') return {type:'array', children:{'0':srvNode(sh.of||{})}};
  return leafNode(sh.t||'any');
}
// A prior step, flattened like the runtime bag: output keys hoisted + reserved meta.
function stepNode(step){
  const name=step.name;
  if(DEBUG&&DEBUG.bag&&DEBUG.bag[name]!==undefined) return jsShape(DEBUG.bag[name]);  // live: already flattened+meta
  const out=SHAPES[name]?srvNode(SHAPES[name]):null, ch={};
  if(out&&out.children) for(const k in out.children) ch[k]=out.children[k];            // flatten output to top
  ch.output=out||leafNode('any'); ch.stdout=leafNode('string'); ch.stderr=leafNode('string'); ch.exit=leafNode('int');
  const cfg=step.config||{}, ich={}; for(const k in cfg) ich[k]=leafNode(typeof cfg[k]);
  ch.input={type:'object', children:ich};
  return {type:'object', children:ch};
}
function prevNode(step){   // {prev.*} = flattened output ONLY (no meta), matching the runtime
  if(DEBUG&&DEBUG.bag&&DEBUG.bag.prev!==undefined) return jsShape(DEBUG.bag.prev);
  return SHAPES[step.name]?srvNode(SHAPES[step.name]):{type:'object', children:{}};
}
function buildVarTree(si){
  const roots={}, cs=(DEF&&DEF.context_schema)||{}, cch={};
  for(const k in cs) cch[k]=leafNode((cs[k]&&cs[k].type)||'string');
  roots.context={type:'object', children:cch};
  for(let j=0;j<si;j++){ const s=DEF.steps[j]; if(s&&s.name) roots[s.name]=stepNode(s); }
  if(si>0&&DEF.steps[si-1]) roots.prev=prevNode(DEF.steps[si-1]);
  roots.time={type:'object', children:{now:leafNode('string'),date:leafNode('string'),ts:leafNode('int'),iso:leafNode('string')}};
  ['run_id','run_uid','run_directory','pipeline_slug'].forEach(k=>roots[k]=leafNode('string'));
  return roots;
}
function vacEligible(el){
  if(!el||(el.tagName!=='TEXTAREA'&&el.tagName!=='INPUT')) return false;
  if(el.tagName==='INPUT'){ const t=(el.getAttribute('type')||'text').toLowerCase(); if(t!=='text'&&t!=='') return false; }
  const conn=el.getAttribute('data-conn');
  return el.hasAttribute('data-field')||conn==='path'||conn==='query'||conn==='variables'||conn==='body';
}
function vacStepIndex(el){ const si=el.getAttribute('data-si'); return si==null?(DEF?DEF.steps.length:0):parseInt(si,10); }
function tokenAt(el){
  const pos=el.selectionStart; if(pos==null) return null;
  const s=el.value.slice(0,pos), open=s.lastIndexOf('{'); if(open<0) return null;
  if(s.indexOf('}',open)>=0) return null;
  const partial=s.slice(open+1); if(!/^[a-zA-Z0-9_.\-]*$/.test(partial)) return null;
  return {start:open, partial};
}
function vacItems(si,partial){
  let children=buildVarTree(si); const segs=partial.split('.'), last=segs.pop();
  for(const seg of segs){ const n=children[seg]; if(!n||!n.children) return []; children=n.children; }
  return Object.keys(children).filter(k=>k!=='…'&&k.toLowerCase().startsWith(last.toLowerCase()))
    .slice(0,40).map(k=>({name:k, node:children[k], base:segs.concat(k).join('.')}));
}
function vacHide(){ if(VAC){ VAC.box.remove(); VAC=null; } }
function vacPaint(){
  VAC.box.innerHTML=VAC.items.map((it,i)=>{
    const ty=it.node.type||''; const tag=it.node.leaf?esc(ty):(ty==='array'?'[ ]':'{…}');
    return `<div class="vac-item ${i===VAC.active?'active':''}" data-i="${i}"><span>${esc(it.name)}</span><span class="vac-ty">${tag}</span></div>`;
  }).join('');
}
function vacShow(el){
  if(!DEF){ vacHide(); return; }
  const tk=tokenAt(el); if(!tk){ vacHide(); return; }
  const items=vacItems(vacStepIndex(el), tk.partial); if(!items.length){ vacHide(); return; }
  if(!VAC){ const box=document.createElement('div'); box.className='vac'; document.body.appendChild(box); VAC={box}; }
  VAC.el=el; VAC.tk=tk; VAC.items=items; VAC.active=0; vacPaint();
  const r=el.getBoundingClientRect();
  VAC.box.style.left=(window.scrollX+r.left)+'px';
  VAC.box.style.top=(window.scrollY+r.bottom+2)+'px';
  VAC.box.style.minWidth=Math.min(360,Math.max(200,r.width))+'px';
}
function vacAccept(i){
  const it=VAC.items[i]; if(!it) return; const el=VAC.el, tk=VAC.tk, leaf=it.node.leaf;
  const before=el.value.slice(0,tk.start); let after=el.value.slice(el.selectionStart);
  if(leaf&&after[0]==='}') after=after.slice(1);
  const insert='{'+it.base+(leaf?'}':'.');
  el.value=before+insert+after; const caret=(before+insert).length; el.selectionStart=el.selectionEnd=caret;
  el.dispatchEvent(new Event('input',{bubbles:true})); el.focus();
  if(leaf) vacHide(); else vacShow(el);
}
function collectLeaves(node,base,out,depth){
  if(out.length>=20||depth>4) return; const ch=node.children||{};
  for(const k in ch){ if(k==='…') continue; const c=ch[k], p=base?base+'.'+k:k;
    if(c.leaf) out.push(p); else collectLeaves(c,p,out,depth+1); if(out.length>=20) return; }
}
function varChips(si){
  if(!DEF) return ''; const roots=buildVarTree(si), leaves=[];
  if(roots.context) collectLeaves(roots.context,'context',leaves,0);
  if(roots.prev) collectLeaves(roots.prev,'prev',leaves,0);
  for(let j=0;j<si;j++){ const s=DEF.steps[j]; if(s&&s.name&&roots[s.name]) collectLeaves(roots[s.name],s.name,leaves,0); }
  const uniq=[...new Set(leaves)].slice(0,18);
  const chips=uniq.map(p=>`<span class="var-chip" data-tok="${esc('{'+p+'}')}">{${esc(p)}}</span>`).join('');
  return `<div class="var-chips"><span class="var-chips-lab"><i class="bi bi-braces"></i> insert</span>`+
    (chips||'<span class="fld-help mb-0">type <code>{</code> for suggestions · run a debug trace to surface real fields</span>')+`</div>`;
}
function insertTok(tok){
  const el=(VACLAST&&document.body.contains(VACLAST))?VACLAST:null; if(!el) return;
  const pos=el.selectionStart!=null?el.selectionStart:el.value.length;
  el.value=el.value.slice(0,pos)+tok+el.value.slice(pos);
  const caret=pos+tok.length; el.selectionStart=el.selectionEnd=caret;
  el.dispatchEvent(new Event('input',{bubbles:true})); el.focus();
}
// Wrap each config code textarea with a synced line-number gutter; auto-grow to a 25-row
// cap (small fields stay compact), then scroll. Runs after every renderBuilder — the builder
// innerHTML is fully replaced each render, so there are never stale wrappers to clean up.
function decorateCode(){
  document.querySelectorAll('#steps textarea.code:not([data-lined])').forEach(ta=>{
    ta.setAttribute('data-lined','1'); ta.removeAttribute('rows');
    const wrap=document.createElement('div'); wrap.className='code-wrap';
    const gut=document.createElement('div'); gut.className='code-gutter';
    ta.parentNode.insertBefore(wrap,ta); wrap.appendChild(gut); wrap.appendChild(ta);
    const cs=getComputedStyle(ta), lh=parseFloat(cs.lineHeight)||18;
    const pad=(parseFloat(cs.paddingTop)||0)+(parseFloat(cs.paddingBottom)||0);
    const MINH=Math.round(3*lh+pad), MAXH=Math.round(25*lh+pad);
    const fit=()=>{
      const n=ta.value.split('\n').length||1; let g=''; for(let i=1;i<=n;i++) g+=i+'\n'; gut.textContent=g;
      ta.style.height='auto'; ta.style.height=Math.min(MAXH,Math.max(MINH,ta.scrollHeight))+'px';
      gut.scrollTop=ta.scrollTop;
    };
    ta._fit=fit;
    ta.addEventListener('input',fit);
    ta.addEventListener('scroll',()=>{ gut.scrollTop=ta.scrollTop; });
    fit();
  });
}
document.addEventListener('focusin', e=>{ if(vacEligible(e.target)) VACLAST=e.target; });
document.addEventListener('input', e=>{ if(vacEligible(e.target)) vacShow(e.target); }, true);
document.addEventListener('mousedown', e=>{ if(e.target.closest && (e.target.closest('.vac')||e.target.closest('.var-chip'))) e.preventDefault(); });  // keep the field focused/caret
document.addEventListener('click', e=>{
  const item=e.target.closest&&e.target.closest('.vac-item'); if(item&&VAC){ vacAccept(parseInt(item.dataset.i,10)); return; }
  const chip=e.target.closest&&e.target.closest('.var-chip'); if(chip){ insertTok(chip.getAttribute('data-tok')); return; }
  if(VAC&&!(e.target.closest&&e.target.closest('.vac'))) vacHide();
});
document.addEventListener('keydown', e=>{
  if(!VAC||VAC.el!==e.target) return;
  if(e.key==='ArrowDown'){ VAC.active=(VAC.active+1)%VAC.items.length; vacPaint(); e.preventDefault(); }
  else if(e.key==='ArrowUp'){ VAC.active=(VAC.active-1+VAC.items.length)%VAC.items.length; vacPaint(); e.preventDefault(); }
  else if(e.key==='Enter'||e.key==='Tab'){ vacAccept(VAC.active); e.preventDefault(); }
  else if(e.key==='Escape'){ vacHide(); e.preventDefault(); }
});

// ---- boot ----
loadList(); loadConnectors(); loadAgents();
</script>
