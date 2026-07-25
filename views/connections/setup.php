<?php
/**
 * GitHub connection setup — opened in a new tab from the AI Builder "Publish" flow.
 * Vars: $instance, $connection (array|null), $isDefault (bool), $prefill (array|null),
 *       $oauthEnabled (bool), $oauthReturn (bool), $oauthError (bool)
 */
$iid   = (int)$instance->id;
$pf    = $prefill ?? ['owner' => '', 'repo' => ''];
$pfUrl = (!empty($pf['owner']) && !empty($pf['repo'])) ? 'https://github.com/' . $pf['owner'] . '/' . $pf['repo'] : '';
?>
<div class="container py-4" style="max-width:640px">
  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-github fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Connect GitHub</h1>
      <div class="text-body-secondary small">for <code><?= htmlspecialchars(($instance->slug) ?? '') ?>.tiknix</code></div>
    </div>
  </div>

  <?php if (!empty($isDefault)): ?>
    <div class="alert alert-warning py-2 small">
      <i class="bi bi-shield-lock me-1"></i>This is the <strong>tiknix core (default)</strong> instance —
      publishing opens a pull request into <strong>main</strong>
      (<code><?= htmlspecialchars(($pf['owner'] ?? '') . '/' . ($pf['repo'] ?? '')) ?></code>).
    </div>
  <?php endif; ?>

  <?php if (!empty($oauthError)): ?>
    <div class="alert alert-danger py-2 small"><i class="bi bi-x-circle me-1"></i>GitHub authorization failed or was cancelled. Please try again.</div>
  <?php endif; ?>

  <?php if (!empty($connection)): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center py-2">
      <span><i class="bi bi-check-circle me-1"></i>Connected to <strong><?= htmlspecialchars(($connection['repo']) ?? '') ?></strong></span>
      <button id="gh-disconnect" class="btn btn-sm btn-outline-danger" data-cid="<?= (int)$connection['id'] ?>">Disconnect</button>
    </div>

    <!-- Custom domains: map repo branches to your own domains (like multiple stores) -->
    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-globe2"></i><span class="fw-semibold">Custom domains</span>
        <span class="text-body-secondary small ms-auto">branch &rarr; your domain</span>
      </div>
      <div class="card-body">
        <p class="small text-body-secondary mb-2">
          Map a branch of <code><?= htmlspecialchars(($connection['repo']) ?? '') ?></code> to your own domain.
          At your DNS, add a <strong>CNAME</strong> from the domain to
          <code><?= htmlspecialchars(($instance->slug) ?? '') ?>.tiknix.com</code>, then <strong>Verify</strong>.
          <span class="text-body-secondary">Going live (HTTPS + serving) is provisioned by us for now.</span>
        </p>
        <div id="rt-list" class="mb-2 small text-body-secondary">Loading…</div>
        <form id="rt-form" class="row g-2 align-items-end">
          <div class="col-12 col-sm-5">
            <label class="form-label small mb-0">Domain</label>
            <input id="rt-domain" class="form-control form-control-sm" placeholder="app.example.com" autocomplete="off" spellcheck="false" required>
          </div>
          <div class="col-8 col-sm-4">
            <label class="form-label small mb-0">Branch</label>
            <select id="rt-branch" class="form-select form-select-sm"><option value="">Loading…</option></select>
          </div>
          <div class="col-4 col-sm-3">
            <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
          </div>
        </form>
        <div id="rt-msg" class="form-text mt-1"></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">

      <?php if (!empty($oauthReturn)): ?>
        <!-- Step 2: pick a repo for the freshly-authorized account -->
        <p class="small text-success mb-2"><i class="bi bi-check-circle me-1"></i>GitHub authorized. Choose the repository to publish to:</p>
        <form id="gh-repo-form">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Repository</label>
            <select id="gh-repo-select" class="form-select" required><option value="">Loading your repositories…</option></select>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="gh-auto" <?= (!empty($connection['autoPublish'])) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="gh-auto">Auto-publish — open/update a PR on every checkpoint</label>
          </div>
          <button class="btn btn-dark w-100" type="submit"><i class="bi bi-check2 me-1"></i>Save connection</button>
          <div id="gh-msg" class="form-text mt-2"></div>
        </form>

      <?php else: ?>
        <?php if (!empty($oauthEnabled)): ?>
          <a href="/connections/connect/github?id=<?= $iid ?>" class="btn btn-dark w-100 mb-3">
            <i class="bi bi-github me-1"></i>Connect with GitHub
          </a>
          <div class="text-center text-body-secondary small mb-3">— or connect manually with a token —</div>
        <?php endif; ?>

        <!-- Personal access token (fallback / manual) -->
        <form id="gh-form">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Personal access token</label>
            <input type="password" id="gh-token" class="form-control" placeholder="ghp_… or github_pat_…" autocomplete="off" required>
            <div class="form-text">
              Create one at
              <a href="https://github.com/settings/tokens/new?scopes=repo&description=tiknix%20AI%20Builder" target="_blank" rel="noopener">github.com/settings/tokens</a>
              with the <code>repo</code> scope. Stored encrypted.
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Repository URL</label>
            <input id="gh-repo-url" class="form-control" placeholder="https://github.com/owner/repo" value="<?= htmlspecialchars(($pfUrl) ?? '') ?>" required>
            <div class="form-text">Paste the repo's GitHub URL (e.g. <code>https://github.com/mfrederico/run.ngn.sh</code>). It must already exist and your token needs push access.</div>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="gh-auto-pat" <?= (!empty($connection['autoPublish'])) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="gh-auto-pat">Auto-publish — open/update a PR on every checkpoint</label>
          </div>
          <button class="btn btn-primary w-100" type="submit"><i class="bi bi-plug me-1"></i>Verify &amp; connect</button>
          <div id="gh-msg-pat" class="form-text mt-2"></div>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
(function(){
  const iid  = <?= $iid ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;
  const post = (url, body) => fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
    body:new URLSearchParams(Object.assign({csrf_token:csrf}, body)).toString()
  }).then(r=>r.json());
  const notifyOpener = () => { try { if(window.opener && !window.opener.closed) window.opener.postMessage({type:'gh-connected', instanceId:iid}, location.origin); } catch(e){} };
  const done = (msg, data) => { msg.className='form-text mt-2 text-success'; msg.innerHTML='<i class="bi bi-check-circle me-1"></i>Connected to <strong>'+((data&&data.repo)||'')+'</strong>. Close this tab and click Publish.'; notifyOpener(); };

  // OAuth repo picker
  const repoForm = document.getElementById('gh-repo-form');
  if (repoForm) {
    const sel = document.getElementById('gh-repo-select');
    const preOwner = <?= json_encode($pf['owner'] ?? '') ?>, preRepo = <?= json_encode($pf['repo'] ?? '') ?>;
    const preFull = (preOwner && preRepo) ? preOwner + '/' + preRepo : '';
    fetch('/connections/repos?id='+iid, {headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(j=>{
      const repos = (j.data&&j.data.repos)||[];
      if(!repos.length){ sel.innerHTML='<option value="">No pushable repositories found</option>'; return; }
      sel.innerHTML = repos.map(r=>'<option value="'+r.full_name+'"'+(r.full_name===preFull?' selected':'')+'>'+r.full_name+(r.private?' (private)':'')+'</option>').join('');
    }).catch(()=>{ sel.innerHTML='<option value="">Failed to load repositories</option>'; });

    repoForm.addEventListener('submit', function(e){
      e.preventDefault();
      const full = sel.value; if(!full) return;
      const parts = full.split('/'); const owner = parts[0], repo = parts.slice(1).join('/');
      const btn=this.querySelector('button[type=submit]'); btn.disabled=true;
      const msg=document.getElementById('gh-msg'); msg.className='form-text mt-2 text-body-secondary'; msg.textContent='Saving…';
      post('/connections/add', { id:iid, type:'github', use_oauth:'1', owner:owner, repo:repo, auto_publish:document.getElementById('gh-auto').checked?'1':'0' }).then(j=>{
        if(j.success) done(msg, j.data);
        else { msg.className='form-text mt-2 text-danger'; msg.textContent=j.message||'Failed.'; }
      }).catch(()=>{ msg.className='form-text mt-2 text-danger'; msg.textContent='Network error.'; }).finally(()=>btn.disabled=false);
    });
  }

  // PAT form
  const patForm = document.getElementById('gh-form');
  if (patForm) patForm.addEventListener('submit', function(e){
    e.preventDefault();
    const btn=this.querySelector('button[type=submit]'); btn.disabled=true;
    const msg=document.getElementById('gh-msg-pat'); msg.className='form-text mt-2 text-body-secondary'; msg.textContent='Verifying token against the repo…';
    post('/connections/add', {
      id:iid, type:'github',
      token:document.getElementById('gh-token').value.trim(),
      repo_url:document.getElementById('gh-repo-url').value.trim(),
      auto_publish:document.getElementById('gh-auto-pat').checked?'1':'0'
    }).then(j=>{
      if(j.success) done(msg, j.data);
      else { msg.className='form-text mt-2 text-danger'; msg.textContent=j.message||'Failed.'; }
    }).catch(()=>{ msg.className='form-text mt-2 text-danger'; msg.textContent='Network error.'; }).finally(()=>btn.disabled=false);
  });

  // Disconnect
  const dc = document.getElementById('gh-disconnect');
  if(dc) dc.addEventListener('click', function(){
    if(!confirm('Disconnect this GitHub repo?')) return;
    post('/connections/disconnect', {cid:this.dataset.cid}).then(()=>location.reload());
  });

  // Custom domains ("resolves to") — branch → your domain, DNS-verified
  const rtForm = document.getElementById('rt-form');
  if (rtForm) {
    const RT_TARGET = <?= json_encode((($instance->slug) ?? '') . '.tiknix.com') ?>;
    let RT = <?= json_encode(array_values($connection['resolvesTo'] ?? [])) ?>;
    const list = document.getElementById('rt-list');
    const rtMsg = document.getElementById('rt-msg');
    const brSel = document.getElementById('rt-branch');
    const esc = s => (s==null?'':String(s)).replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const setMsg = (t, cls) => { rtMsg.className='form-text mt-1 '+(cls||''); rtMsg.textContent=t||''; };

    function renderRT(){
      if(!RT.length){ list.innerHTML='<span class="text-body-secondary">No domains yet — add one below.</span>'; return; }
      list.innerHTML = RT.map(r=>{
        const v = r.verified ? '<span class="badge text-bg-success"><i class="bi bi-check-lg"></i> DNS verified</span>'
                             : '<span class="badge text-bg-secondary">unverified</span>';
        return '<div class="d-flex align-items-center gap-2 py-1 border-bottom">'
          + '<code class="text-body">'+esc(r.domain)+'</code>'
          + '<span class="text-body-secondary small">&larr; '+esc(r.branch)+'</span>'
          + '<span class="ms-auto d-flex gap-1 align-items-center">'+v
          + ' <button class="btn btn-outline-secondary btn-sm rt-verify" data-d="'+esc(r.domain)+'">Verify DNS</button>'
          + ' <button class="btn btn-outline-danger btn-sm rt-del" data-d="'+esc(r.domain)+'" title="Remove"><i class="bi bi-x"></i></button>'
          + '</span></div>';
      }).join('');
    }
    renderRT();

    fetch('/connections/branches?id='+iid,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(j=>{
      const bs=(j.data&&j.data.branches)||[]; const def=(j.data&&j.data.default)||'main';
      brSel.innerHTML = bs.length ? bs.map(b=>'<option'+(b===def?' selected':'')+'>'+esc(b)+'</option>').join('') : '<option value="">No branches</option>';
    }).catch(()=>{ brSel.innerHTML='<option value="">Failed to load branches</option>'; });

    rtForm.addEventListener('submit', function(e){
      e.preventDefault();
      const domain=document.getElementById('rt-domain').value.trim().toLowerCase(), branch=brSel.value;
      if(!domain||!branch){ setMsg('Enter a domain and pick a branch.','text-danger'); return; }
      setMsg('Adding…','text-body-secondary');
      post('/connections/resolveadd',{id:iid,domain:domain,branch:branch}).then(j=>{
        if(j.success){ RT=j.data.resolvesTo||RT; renderRT(); document.getElementById('rt-domain').value='';
          setMsg('Added. Add a CNAME: '+domain+' → '+RT_TARGET+', then Verify DNS.','text-success'); }
        else setMsg(j.message||'Failed.','text-danger');
      }).catch(()=>setMsg('Network error.','text-danger'));
    });

    list.addEventListener('click', function(e){
      const vb=e.target.closest('.rt-verify'), db=e.target.closest('.rt-del');
      if(vb){ const d=vb.dataset.d; vb.disabled=true; setMsg('Checking DNS for '+d+'…','text-body-secondary');
        post('/connections/resolveverify',{id:iid,domain:d}).then(j=>{ vb.disabled=false;
          if(j.success){ RT=j.data.resolvesTo||RT; renderRT(); setMsg(j.message||'Verified.','text-success'); }
          else setMsg(j.message||'Not verified yet.','text-danger');
        }).catch(()=>{ vb.disabled=false; setMsg('Network error.','text-danger'); }); }
      if(db){ const d=db.dataset.d; if(!confirm('Remove '+d+'?')) return;
        post('/connections/resolveremove',{id:iid,domain:d}).then(j=>{ if(j.success){ RT=j.data.resolvesTo||RT; renderRT(); setMsg('Removed.','text-body-secondary'); } }); }
    });
  }
})();
</script>
