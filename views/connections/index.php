<?php
/**
 * Connections hub for an instance — plain-language store connections.
 * Vars: $instance, $connections (array), $connectors (array), $environments (array)
 *
 * The wiring that lets the app reach a connected store is set up automatically on
 * connect; it is never surfaced to the user. Connect uses a GET form so the browser
 * navigates top-level into the store's sign-in. Disconnect posts via fetch with CSRF.
 */
$iid = (int)$instance->id;
$envBadge = ['development' => 'secondary', 'production' => 'success'];
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
    <i class="bi bi-shield-check me-1"></i>
    Connect a store once, and this app can read its products, orders, and customers on your
    behalf — securely. There are no keys for you to copy or manage, and you can disconnect at any time.
  </div>

  <!-- Available connections -->
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2" style="letter-spacing:.06em">Connect a store</h2>
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
              <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Unavailable right now</span>
            <?php endif; ?>
          </div>

          <?php if ($c['configured']): ?>
            <form method="get" action="/connections/connect/<?= htmlspecialchars($c['key']) ?>" class="row g-2 align-items-end mt-2">
              <input type="hidden" name="id" value="<?= $iid ?>">
              <?php if ($c['key'] === 'shopify'): ?>
                <div class="col-sm-5">
                  <label class="form-label small mb-1">Store address</label>
                  <input type="text" name="shop" class="form-control form-control-sm" placeholder="your-store.myshopify.com" required>
                </div>
              <?php endif; ?>
              <div class="col-sm-4">
                <label class="form-label small mb-1">Use for</label>
                <select name="env" class="form-select form-select-sm">
                  <?php foreach ($environments as $e): ?>
                    <option value="<?= htmlspecialchars($e) ?>"<?= $e === 'development' ? ' selected' : '' ?>><?= $e === 'production' ? 'Live site' : 'Development' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-3">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-box-arrow-up-right me-1"></i>Connect</button>
              </div>
            </form>
            <div class="form-text mt-1">Connect a separate store for development and for your live site if you like.</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Existing connections -->
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2" style="letter-spacing:.06em">Connected stores</h2>
  <?php if (empty($connections)): ?>
    <div class="text-body-secondary small">No stores connected yet.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr class="small text-body-secondary">
            <th>Service</th><th>Used for</th><th>Store</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($connections as $row): $usedFor = $row['environment'] === 'production' ? 'Live site' : 'Development'; ?>
            <tr>
              <td class="fw-semibold text-capitalize"><?= htmlspecialchars($row['type']) ?></td>
              <td><span class="badge bg-<?= $envBadge[$row['environment']] ?? 'secondary' ?>-subtle text-<?= $envBadge[$row['environment']] ?? 'secondary' ?>-emphasis border"><?= htmlspecialchars($usedFor) ?></span></td>
              <td class="small"><?= htmlspecialchars($row['name']) ?><div class="text-body-secondary"><?= htmlspecialchars($row['eid']) ?></div></td>
              <td>
                <?php if ($row['revoked']): ?>
                  <span class="badge bg-danger-subtle text-danger-emphasis border">Disconnected</span>
                <?php elseif ($row['lastError']): ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis border" title="<?= htmlspecialchars($row['lastError']) ?>">Needs attention</span>
                <?php elseif ($row['enabled']): ?>
                  <span class="badge bg-success-subtle text-success-emphasis border">Connected</span>
                <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary-emphasis border">Off</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-danger" data-disconnect="<?= (int)$row['id'] ?>" title="Disconnect this store"><i class="bi bi-x-lg"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('[data-disconnect]').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Disconnect this store? This app will no longer be able to read its data.')) return;
      fetch('/connections/disconnect', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, cid: btn.getAttribute('data-disconnect')}).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Could not disconnect'); }
      }).catch(function(){ alert('Could not disconnect'); });
    });
  });
})();
</script>
