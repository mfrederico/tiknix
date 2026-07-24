<?php
/**
 * Instance-side Connections (editable, admin-only). The instance's owner/admins wire
 * up external accounts here, ON the instance. Custody stays in core: OAuth connectors
 * redirect through the control plane (which holds the client secret and stores the
 * credential encrypted, tagged to this instance); api_key connectors POST the key to
 * core over the broker. The credential never lands on the instance.
 *
 * Vars: $connections[], $brokerError, $connectors[], $connectorsError, $appName, $environments[]
 */
$byConnector = [];
foreach ($connections as $c) { $byConnector[(string)($c['connector'] ?? '')][] = $c; }
$envBadge = ['development' => 'secondary', 'production' => 'success', 'staging' => 'warning'];
$connected  = strtolower(trim((string)($_GET['connected'] ?? '')));
$connectErr = strtolower(trim((string)($_GET['connect_error'] ?? '')));
$flash = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-plug fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Connections</h1>
      <div class="text-body-secondary small">wire <code><?= htmlspecialchars($appName) ?></code> to external accounts</div>
    </div>
  </div>

  <?php if ($connected !== ''): ?>
    <div class="alert alert-success py-2 small"><i class="bi bi-check-lg me-1"></i><span class="text-capitalize"><?= htmlspecialchars($connected) ?></span> connected.</div>
  <?php elseif ($connectErr !== ''): ?>
    <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Could not connect <span class="text-capitalize"><?= htmlspecialchars($connectErr) ?></span> — please try again.</div>
  <?php endif; ?>
  <?php foreach ($flash as $m): ?>
    <div class="alert alert-<?= ($m['type'] ?? '') === 'error' ? 'danger' : htmlspecialchars($m['type'] ?? 'info') ?> py-2 small"><?= htmlspecialchars($m['message'] ?? '') ?></div>
  <?php endforeach; ?>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-shield-check me-1"></i>
    Credentials are stored encrypted in the control plane and never touch this instance — it reaches them through the broker.
    What this app <em>exposes</em> is on the <a href="/integrations" style="text-decoration:underline">Integrations</a> page.
  </div>

  <?php if ($connectorsError !== ''): ?>
    <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($connectorsError) ?></div>
  <?php elseif (empty($connectors)): ?>
    <div class="alert alert-light border py-2 small">No connectors are available from the control plane.</div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($connectors as $conn):
        $key   = (string)$conn['key'];
        $auth  = (string)($conn['auth_type'] ?? 'oauth');
        $rows  = $byConnector[$key] ?? [];
        $hasLive = false; foreach ($rows as $r) { if (!empty($r['enabled']) && empty($r['revoked'])) { $hasLive = true; break; } }
      ?>
        <div class="col-md-6">
          <div class="card h-100 <?= $hasLive ? 'border-success border-opacity-50' : ((int)($conn['configured'] ?? 0) ? '' : 'opacity-75') ?>">
            <div class="card-body">
              <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle bg-secondary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px">
                  <i class="bi bi-<?= htmlspecialchars((string)($conn['icon'] ?? 'plug')) ?> text-secondary"></i>
                </div>
                <div>
                  <div class="fw-semibold text-capitalize"><?= htmlspecialchars((string)$conn['label']) ?></div>
                  <div class="text-body-secondary small"><?= htmlspecialchars((string)($conn['category'] ?? '')) ?></div>
                </div>
              </div>
              <?php if (($conn['blurb'] ?? '') !== ''): ?><div class="text-body-secondary small mt-2"><?= htmlspecialchars((string)$conn['blurb']) ?></div><?php endif; ?>

              <?php if ($rows): ?>
                <ul class="list-unstyled mt-2 mb-0 border-top pt-2">
                  <?php foreach ($rows as $r): $env = (string)($r['environment'] ?? 'production'); ?>
                    <li class="d-flex align-items-center justify-content-between gap-2 py-1 small">
                      <span>
                        <span class="badge bg-<?= $envBadge[$env] ?? 'secondary' ?>-subtle text-<?= $envBadge[$env] ?? 'secondary' ?>-emphasis border me-1"><?= $env === 'production' ? 'Live' : 'Dev' ?></span>
                        <?= htmlspecialchars((string)($r['name'] ?: '—')) ?>
                        <?php if (!empty($r['revoked'])): ?><span class="badge text-bg-danger ms-1">revoked</span>
                        <?php elseif (empty($r['enabled'])): ?><span class="badge text-bg-secondary ms-1">disabled</span>
                        <?php else: ?><span class="badge text-bg-success ms-1">connected</span><?php endif; ?>
                      </span>
                      <?php if (!empty($r['id'])): ?>
                        <button class="btn btn-sm btn-outline-danger py-0 px-1" data-disconnect="<?= (int)$r['id'] ?>" title="Disconnect"><i class="bi bi-x-lg"></i></button>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if (empty($conn['configured'])): ?>
                <div class="form-text mt-2">Not available on this server.</div>
              <?php elseif ($auth === 'api_key'): ?>
                <form data-connectkey class="row g-2 align-items-end mt-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="type" value="<?= htmlspecialchars($key) ?>">
                  <div class="col-12"><label class="form-label small mb-1"><?= $rows ? 'Connect another' : 'Connect' ?> — secret key</label>
                    <input type="password" name="key" class="form-control form-control-sm" placeholder="sk_live_… / rk_live_…" autocomplete="off" required></div>
                  <div class="col-7"><select name="env" class="form-select form-select-sm">
                    <?php foreach ($environments as $e): ?><option value="<?= htmlspecialchars($e) ?>"<?= $e === 'production' ? ' selected' : '' ?>><?= $e === 'production' ? 'Live' : ucfirst($e) ?></option><?php endforeach; ?>
                  </select></div>
                  <div class="col-5"><button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-key me-1"></i>Connect</button></div>
                </form>
              <?php else: /* oauth */ ?>
                <form method="post" action="/connections/instanceconnect" class="row g-2 align-items-end mt-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="type" value="<?= htmlspecialchars($key) ?>">
                  <?php if ($key === 'shopify'): ?>
                    <div class="col-12"><label class="form-label small mb-1">Store address</label>
                      <input type="text" name="shop" class="form-control form-control-sm" placeholder="your-store.myshopify.com" required></div>
                  <?php endif; ?>
                  <div class="col-7"><select name="env" class="form-select form-select-sm">
                    <?php foreach ($environments as $e): ?><option value="<?= htmlspecialchars($e) ?>"<?= $e === 'production' ? ' selected' : '' ?>><?= $e === 'production' ? 'Live' : ucfirst($e) ?></option><?php endforeach; ?>
                  </select></div>
                  <div class="col-5"><button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-box-arrow-up-right me-1"></i><?= $rows ? 'Connect another' : 'Connect' ?></button></div>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var csrf = <?= json_encode(function_exists('csrf_token') ? csrf_token() : '') ?>;
  document.querySelectorAll('form[data-connectkey]').forEach(function(form){
    form.addEventListener('submit', function(e){
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]'); if (btn) btn.disabled = true;
      fetch('/connections/instanceconnectkey', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams(new FormData(form)).toString()
      }).then(function(r){ return r.json(); }).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Could not connect'); if (btn) btn.disabled = false; }
      }).catch(function(){ alert('Could not connect'); if (btn) btn.disabled = false; });
    });
  });
  document.querySelectorAll('[data-disconnect]').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Disconnect this account? This app will no longer be able to use it.')) return;
      fetch('/connections/instancedisconnect', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, cid: btn.getAttribute('data-disconnect')}).toString()
      }).then(function(r){ return r.json(); }).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Could not disconnect'); }
      }).catch(function(){ alert('Could not disconnect'); });
    });
  });
})();
</script>
