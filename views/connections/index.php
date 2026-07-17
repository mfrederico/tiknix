<?php
/**
 * Connections hub for an instance.
 * Vars: $instance, $connections (array), $connectors (array), $environments (array)
 *
 * OAuth "Connect" uses a GET form so the browser navigates top-level into the
 * provider handshake. Disconnect posts via fetch with CSRF.
 */
$iid = (int)$instance->id;
$envBadge = ['development' => 'secondary', 'staging' => 'info', 'production' => 'success'];
?>
<div class="container py-4" style="max-width:820px">

  <div class="d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-plug fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Connections</h1>
      <div class="text-body-secondary small">for <code><?= htmlspecialchars(($instance->slug) ?? '') ?>.tiknix</code></div>
    </div>
  </div>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-shield-lock me-1"></i>
    Tokens are held on the control plane and never stored inside your instance. Your app reaches
    a connected store through the secure broker, so a connection can be revoked here at any time.
  </div>

  <!-- Available connectors -->
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2" style="letter-spacing:.06em">Add a connection</h2>
  <div class="d-flex flex-column gap-3 mb-5">
    <?php foreach ($connectors as $c): $meta = $c['meta']; ?>
      <div class="card border">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
            <div>
              <div class="fw-semibold"><i class="bi bi-bag-check me-1"></i><?= htmlspecialchars($meta['label'] ?? $c['key']) ?></div>
              <div class="text-body-secondary small"><?= htmlspecialchars($meta['blurb'] ?? '') ?></div>
            </div>
            <?php if (!$c['configured']): ?>
              <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Not configured on this server</span>
            <?php endif; ?>
          </div>

          <?php if ($c['configured']): ?>
            <form method="get" action="/connections/connect/<?= htmlspecialchars($c['key']) ?>" class="row g-2 align-items-end mt-2">
              <input type="hidden" name="id" value="<?= $iid ?>">
              <?php if ($c['key'] === 'shopify'): ?>
                <div class="col-sm-5">
                  <label class="form-label small mb-1">Store domain</label>
                  <input type="text" name="shop" class="form-control form-control-sm" placeholder="your-store.myshopify.com" required>
                </div>
              <?php endif; ?>
              <div class="col-sm-4">
                <label class="form-label small mb-1">Environment</label>
                <select name="env" class="form-select form-select-sm">
                  <?php foreach ($environments as $e): ?>
                    <option value="<?= htmlspecialchars($e) ?>"<?= $e === 'production' ? ' selected' : '' ?>><?= ucfirst($e) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-3">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-box-arrow-up-right me-1"></i>Connect</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Existing connections -->
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2" style="letter-spacing:.06em">Connected</h2>
  <?php if (empty($connections)): ?>
    <div class="text-body-secondary small">No connections yet.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr class="small text-body-secondary">
            <th>Provider</th><th>Environment</th><th>Store</th><th>Scopes</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($connections as $row): ?>
            <tr>
              <td class="fw-semibold text-capitalize"><?= htmlspecialchars($row['type']) ?></td>
              <td><span class="badge bg-<?= $envBadge[$row['environment']] ?? 'secondary' ?>-subtle text-<?= $envBadge[$row['environment']] ?? 'secondary' ?>-emphasis border"><?= htmlspecialchars($row['environment']) ?></span></td>
              <td class="small"><?= htmlspecialchars($row['name']) ?><div class="text-body-secondary"><?= htmlspecialchars($row['eid']) ?></div></td>
              <td class="small text-body-secondary" style="max-width:200px"><?= htmlspecialchars($row['scopes'] ?: '—') ?></td>
              <td>
                <?php if ($row['revoked']): ?>
                  <span class="badge bg-danger-subtle text-danger-emphasis border">Revoked</span>
                <?php elseif ($row['lastError']): ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis border" title="<?= htmlspecialchars($row['lastError']) ?>">Error</span>
                <?php elseif ($row['enabled']): ?>
                  <span class="badge bg-success-subtle text-success-emphasis border">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary-emphasis border">Disabled</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-danger" data-disconnect="<?= (int)$row['id'] ?>"><i class="bi bi-x-lg"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- Broker key -->
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-5" style="letter-spacing:.06em">Broker key</h2>
  <div class="card border">
    <div class="card-body">
      <div class="text-body-secondary small mb-2">
        Your instance uses this key to reach its connected stores through the tiknix broker — the store token
        never leaves the control plane. Shown once; rotating replaces the old key immediately.
      </div>
      <button id="broker-mint" class="btn btn-sm btn-outline-primary"><i class="bi bi-key me-1"></i>Generate / rotate broker key</button>
      <div id="broker-out" class="mt-3 d-none">
        <label class="form-label small mb-1">Add to your instance's <code>conf/broker.ini</code> (copy now — shown once):</label>
        <pre class="bg-body-secondary border rounded p-2 small mb-0" id="broker-snippet" style="white-space:pre-wrap"></pre>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  const iid = <?= $iid ?>;
  const bmint = document.getElementById('broker-mint');
  if (bmint) bmint.addEventListener('click', function(){
    bmint.disabled = true;
    fetch('/connections/broker', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
      body:new URLSearchParams({csrf_token:csrf, id:iid}).toString()
    }).then(r=>r.json()).then(function(j){
      bmint.disabled = false;
      if (j && j.success && j.data && j.data.token) {
        document.getElementById('broker-out').classList.remove('d-none');
        document.getElementById('broker-snippet').textContent =
          '[broker]\nendpoint = "' + j.data.endpoint + '"\nkey = "' + j.data.token + '"';
      } else { alert((j && j.message) || 'Could not mint broker key'); }
    }).catch(function(){ bmint.disabled = false; alert('Could not mint broker key'); });
  });
  document.querySelectorAll('[data-disconnect]').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Disconnect this integration? Your app will lose access to that store.')) return;
      fetch('/connections/disconnect', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, cid: btn.getAttribute('data-disconnect')}).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Disconnect failed'); }
      }).catch(function(){ alert('Disconnect failed'); });
    });
  });
})();
</script>
