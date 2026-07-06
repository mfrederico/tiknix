<?php
/**
 * GitHub connection setup — opened in a new tab from the AI Builder "Publish" flow.
 * Vars: $instance (Instance bean), $connection (array|null)
 */
$iid = (int)$instance->id;
?>
<div class="container py-4" style="max-width:640px">
  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-github fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Connect GitHub</h1>
      <div class="text-body-secondary small">for <code><?= htmlspecialchars($instance->slug) ?>.tiknix</code></div>
    </div>
  </div>

  <?php if (!empty($isDefault)): ?>
    <div class="alert alert-warning py-2 small">
      <i class="bi bi-shield-lock me-1"></i>This is the <strong>tiknix core (default)</strong> instance —
      publishing opens a pull request into <strong>main</strong>
      (<code><?= htmlspecialchars(($prefill['owner'] ?? '') . '/' . ($prefill['repo'] ?? '')) ?></code>).
      The token needs push access to that repository.
    </div>
  <?php endif; ?>

  <?php if ($connection): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center py-2">
      <span><i class="bi bi-check-circle me-1"></i>Connected to <strong><?= htmlspecialchars($connection['repo']) ?></strong></span>
      <button id="gh-disconnect" class="btn btn-sm btn-outline-danger" data-cid="<?= (int)$connection['id'] ?>">Disconnect</button>
    </div>
    <p class="text-body-secondary small">Re-enter details below to replace the stored token, or close this tab and click <strong>Publish</strong>.</p>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form id="gh-form">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Personal access token</label>
          <input type="password" id="gh-token" class="form-control" placeholder="ghp_… or github_pat_…" autocomplete="off" required>
          <div class="form-text">
            Create one at
            <a href="https://github.com/settings/tokens/new?scopes=repo&description=tiknix%20AI%20Builder" target="_blank" rel="noopener">github.com/settings/tokens</a>
            with the <code>repo</code> scope (fine-grained tokens need <em>Contents</em> + <em>Pull requests: Read &amp; write</em>). Stored encrypted; never shown again.
          </div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col">
            <label class="form-label small fw-semibold">Owner</label>
            <input id="gh-owner" class="form-control" placeholder="jadams" value="<?= htmlspecialchars($prefill['owner'] ?? '') ?>" required>
          </div>
          <div class="col">
            <label class="form-label small fw-semibold">Repository</label>
            <input id="gh-repo" class="form-control" placeholder="my-app" value="<?= htmlspecialchars($prefill['repo'] ?? '') ?>" required>
          </div>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="gh-auto" <?= ($connection && !empty($connection['autoPublish'])) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="gh-auto">
            Auto-publish — open/update a PR on every checkpoint
          </label>
        </div>
        <button class="btn btn-dark w-100" type="submit"><i class="bi bi-plug me-1"></i>Verify &amp; connect</button>
        <div id="gh-msg" class="form-text mt-2"></div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const iid = <?= $iid ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;
  const msg = document.getElementById('gh-msg');
  const post = (url, body) => fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
    body:new URLSearchParams(Object.assign({csrf_token:csrf}, body)).toString()
  }).then(r=>r.json());

  document.getElementById('gh-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn=this.querySelector('button[type=submit]'); btn.disabled=true;
    msg.className='form-text mt-2 text-body-secondary'; msg.textContent='Verifying token against the repo…';
    post('/connections/add', {
      id:iid, type:'github',
      token:document.getElementById('gh-token').value.trim(),
      owner:document.getElementById('gh-owner').value.trim(),
      repo:document.getElementById('gh-repo').value.trim(),
      auto_publish:document.getElementById('gh-auto').checked ? '1' : '0'
    }).then(j=>{
      if(j.success){
        msg.className='form-text mt-2 text-success';
        msg.innerHTML='<i class="bi bi-check-circle me-1"></i>Connected to <strong>'+(j.data.repo||'')+'</strong>. You can close this tab and click <strong>Publish</strong>.';
        try { if(window.opener && !window.opener.closed) window.opener.postMessage({type:'gh-connected', instanceId:iid}, location.origin); } catch(e){}
      } else {
        msg.className='form-text mt-2 text-danger'; msg.textContent=j.message||'Failed to connect.';
      }
    }).catch(()=>{ msg.className='form-text mt-2 text-danger'; msg.textContent='Network error.'; })
      .finally(()=>btn.disabled=false);
  });

  const dc = document.getElementById('gh-disconnect');
  if(dc) dc.addEventListener('click', function(){
    if(!confirm('Disconnect this GitHub repo?')) return;
    post('/connections/disconnect', {cid:this.dataset.cid}).then(j=>{ location.reload(); });
  });
})();
</script>
