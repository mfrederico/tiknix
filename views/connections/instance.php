<?php
/**
 * Instance-side Connections (editable, admin-only). The instance's owner/admins wire
 * up external accounts here, ON the instance.
 *
 * Custody follows the APP. Connecting through the shared tiknix app redirects via the
 * control plane, which holds that client secret and never lets it near a project.
 * Connecting with the merchant's OWN app is the other way round: it is their credential,
 * this install may hold it, so the whole dance runs here and the secret never travels to
 * core at all. api_key connectors POST the key to core over the broker.
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
    Credentials are stored encrypted. A store connected through the shared tiknix app is held in the control plane and reached over the broker; one connected with the merchant's own app is held right here, sealed with this install's key.
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

              <?php
                /* "Not available on this server" is the end of the story only for a
                   connector that can ONLY use a server-wide app. One that accepts a
                   per-connection app is still connectable here — bring your own — and on
                   a project that is the NORMAL case, because conf/<key>.ini is scrubbed
                   empty at provision by design. Reading isConfigured() as "unavailable"
                   hid the Shopify card completely, which is exactly the situation a
                   merchant's own app exists to serve. */
                $__byoOnly = empty($conn['configured']) && !empty($conn['custom_ok']) && $auth !== 'api_key';
              ?>
              <?php if (empty($conn['configured']) && ($auth === 'api_key' || empty($conn['custom_ok']))): ?>
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
                    <?php
                      /* The callback the merchant must register in their custom app. Shown
                         rather than described: it is this project's own domain, it differs
                         per project, and a mismatch is rejected by Shopify with an error
                         that does not say which URL it expected. */
                      $__scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
                      $__cb = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/connections/callback/shopify';
                    ?>
                    <div class="col-12">
                      <details<?= $__byoOnly ? ' open' : '' ?>>
                        <summary class="small text-secondary" style="cursor:pointer">
                          <?= $__byoOnly ? "This store's own custom app (required here)" : "Use this store's own custom app" ?>
                        </summary>
                        <div class="row g-2 mt-1">
                          <div class="col-12">
                            <p class="small text-secondary mb-2">
                              <?= $__byoOnly ? 'This project holds no shared Shopify app — that is deliberate, so a customer\'s project can never hold tiknix\'s secret. Connect with the merchant\'s own custom app instead.' : 'Leave blank to connect through the shared tiknix app. Fill these in to authorise' ?>
                              against the merchant's own Shopify custom app — their scopes, their billing. This project
                              runs the sign-in itself, so the credentials stay here and never reach the control plane.<br>
                              Their app must list this exact callback URL: <code><?= htmlspecialchars($__cb) ?></code>
                            </p>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label small mb-1">API key</label>
                            <input type="text" name="app_key" class="form-control form-control-sm" autocomplete="off" placeholder="from their custom app"<?= $__byoOnly ? ' required' : '' ?>>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label small mb-1">API secret key</label>
                            <input type="password" name="app_secret" class="form-control form-control-sm" autocomplete="new-password" placeholder="stored encrypted"<?= $__byoOnly ? ' required' : '' ?>>
                          </div>
                          <div class="col-12">
                            <label class="form-label small mb-1">Scopes <span class="text-secondary">(optional)</span></label>
                            <input type="text" name="app_scopes" class="form-control form-control-sm" autocomplete="off" placeholder="read_products,read_orders">
                            <div class="form-text small">Blank uses the default. Asking for scopes their app was not configured with makes Shopify reject the whole authorisation.</div>
                          </div>
                        </div>
                      </details>
                    </div>
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
