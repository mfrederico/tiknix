<?php
/**
 * Connections hub for an instance — one place for every connection, grouped by
 * category (Deploy / Payments / Stores / …). Each card shows connect-vs-connected
 * state inline so nothing is hidden behind a separate screen.
 *
 * Vars: $instance, $cards (array), $environments (array), $categoryOrder (array)
 *   card: key, label, blurb, category, icon, color, auth_type, connect_kind
 *         ('github'|'api_key'|'shopify'|'oauth'), configured, features[],
 *         manage_url|null, connections[] (id, environment, name, eid, url, enabled, revoked, lastError)
 */
$iid = (int)$instance->id;
$envBadge = ['development' => 'secondary', 'production' => 'success'];

// Group cards by category, honouring $categoryOrder then any leftovers.
$byCat = [];
foreach ($cards as $card) { $byCat[$card['category'] ?? 'Other'][] = $card; }
$cats = [];
foreach ($categoryOrder as $c) { if (!empty($byCat[$c])) $cats[] = $c; }
foreach (array_keys($byCat) as $c) { if (!in_array($c, $cats, true)) $cats[] = $c; }

/** A card is "connected" when it has at least one live (enabled, not revoked) connection. */
$isConnected = function (array $card): bool {
    foreach ($card['connections'] as $cn) { if (!empty($cn['enabled']) && empty($cn['revoked'])) return true; }
    return false;
};
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-plug fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Connections</h1>
      <div class="text-body-secondary small">for <code><?= htmlspecialchars(($instance->slug) ?? '') ?>.tiknix</code></div>
    </div>
  </div>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-shield-check me-1"></i>
    One place to connect everything this instance uses — deploy targets, payments, and stores.
    Keys never leave the platform, and you can disconnect any of them at any time.
  </div>

  <?php if (!empty($instances) && count($instances) > 1): ?>
    <div class="mb-4">
      <div class="text-uppercase text-body-secondary small fw-semibold mb-2" style="letter-spacing:.06em">Store</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($instances as $i): $active = (int)$i->id === $iid; ?>
          <a href="/connections?id=<?= (int)$i->id ?>" class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <i class="bi bi-shop me-1"></i><?= htmlspecialchars($i->display_name ?: $i->slug) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($cats as $cat): ?>
    <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-4" style="letter-spacing:.06em"><?= htmlspecialchars($cat) ?></h2>
    <div class="row g-3">
      <?php foreach ($byCat[$cat] as $card): $meta = $card; $connected = $isConnected($card); ?>
        <div class="col-md-6">
          <div class="card h-100 <?= $connected ? 'border-success border-opacity-50' : ($card['configured'] ? '' : 'opacity-75') ?>">
            <div class="card-body">
              <div class="d-flex align-items-start gap-3">
                <div class="rounded-circle bg-<?= htmlspecialchars($card['color']) ?>-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
                  <i class="bi bi-<?= htmlspecialchars($card['icon']) ?> fs-5 text-<?= htmlspecialchars($card['color']) ?>"></i>
                </div>
                <div class="flex-grow-1">
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fw-semibold">
                      <?= htmlspecialchars($card['label']) ?>
                      <?php if ($connected): ?><i class="bi bi-check-circle-fill text-success ms-1 small"></i><?php endif; ?>
                    </div>
                    <?php if ($connected): ?>
                      <span class="badge bg-success-subtle text-success-emphasis border">Connected</span>
                    <?php elseif (!$card['configured']): ?>
                      <span class="badge bg-warning-subtle text-warning-emphasis border">Unavailable</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-body-secondary small mt-1"><?= htmlspecialchars($card['blurb']) ?></div>
                  <?php if (!empty($card['features'])): ?>
                    <div class="mt-2 d-flex flex-wrap gap-1">
                      <?php foreach (array_slice($card['features'], 0, 3) as $feat): ?>
                        <span class="badge bg-body-secondary text-body-secondary border" style="font-size:.68rem"><?= htmlspecialchars($feat) ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <?php // --- connected instances --- ?>
              <?php if (!empty($card['connections'])): ?>
                <ul class="list-unstyled mt-3 mb-0 border-top pt-2">
                  <?php foreach ($card['connections'] as $cn): $env = $cn['environment']; ?>
                    <li class="py-1">
                      <div class="d-flex align-items-center justify-content-between gap-2">
                        <div class="small">
                          <span class="badge bg-<?= $envBadge[$env] ?? 'secondary' ?>-subtle text-<?= $envBadge[$env] ?? 'secondary' ?>-emphasis border me-1"><?= $env === 'production' ? 'Live site' : 'Development' ?></span>
                          <?= htmlspecialchars($cn['name'] ?? $cn['eid'] ?? '') ?>
                          <?php if (!empty($cn['revoked'])): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis border ms-1">Disconnected</span>
                          <?php elseif (!empty($cn['lastError'])): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border ms-1" title="<?= htmlspecialchars($cn['lastError']) ?>">Needs attention</span>
                          <?php endif; ?>
                        </div>
                        <button class="btn btn-sm btn-outline-danger py-0 px-1" data-disconnect="<?= (int)$cn['id'] ?>" title="Disconnect"><i class="bi bi-x-lg"></i></button>
                      </div>
                      <?php if (($card['category'] ?? '') === 'Payments' && empty($cn['revoked'])): ?>
                        <form data-whsec class="d-flex align-items-center gap-1 mt-1" style="max-width:480px">
                          <?= csrf_field() ?>
                          <input type="hidden" name="cid" value="<?= (int)$cn['id'] ?>">
                          <input type="password" name="secret" class="form-control form-control-sm" placeholder="<?= !empty($cn['webhookSet']) ? 'Webhook secret set ✓ — paste to replace' : 'Webhook signing secret (whsec_…)' ?>" autocomplete="off">
                          <button class="btn btn-sm btn-outline-secondary text-nowrap" type="submit">Save</button>
                          <?php if (!empty($cn['webhookSet'])): ?><button class="btn btn-sm btn-outline-danger" type="button" data-whsec-clear="<?= (int)$cn['id'] ?>" title="Remove secret"><i class="bi bi-x-lg"></i></button><?php endif; ?>
                        </form>
                        <div class="form-text ms-1">
                          <?php if (!empty($cn['webhookSet']) && !empty($cn['webhookHint'])): ?>
                            <span class="text-success-emphasis">Set ✓ ending <code>…<?= htmlspecialchars($cn['webhookHint']) ?></code>.</span>
                          <?php endif; ?>
                          Verifies incoming <?= $env === 'production' ? 'live' : 'test' ?> webhooks for this connection.
                        </div>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php // --- connect action, per connect_kind --- ?>
              <?php if ($card['connect_kind'] === 'github'): ?>
                <a href="<?= htmlspecialchars($card['manage_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-<?= htmlspecialchars($card['color']) ?> mt-3">
                  <i class="bi bi-<?= $connected ? 'gear' : 'box-arrow-up-right' ?> me-1"></i><?= $connected ? 'Manage' : 'Set up' ?>
                </a>

              <?php elseif (!$card['configured']): ?>
                <div class="form-text mt-2">Not available on this server yet.</div>

              <?php elseif ($card['connect_kind'] === 'api_key'): ?>
                <form data-connectkey action="/connections/connectkey" method="post" class="row g-2 align-items-end mt-3">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $iid ?>">
                  <input type="hidden" name="type" value="<?= htmlspecialchars($card['key']) ?>">
                  <div class="col-12">
                    <label class="form-label small mb-1"><?= $connected ? 'Connect another' : 'Connect' ?> — <?= htmlspecialchars($card['label']) ?> secret key</label>
                    <input type="password" name="key" class="form-control form-control-sm" placeholder="sk_live_… or rk_live_…" autocomplete="off" required>
                  </div>
                  <div class="col-7">
                    <select name="env" class="form-select form-select-sm">
                      <?php foreach ($environments as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>"<?= $e === 'development' ? ' selected' : '' ?>><?= $e === 'production' ? 'Live site' : 'Development' ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-5">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-key me-1"></i>Connect</button>
                  </div>
                  <div class="col-12"><div class="form-text">Stripe Dashboard → Developers → API keys. A restricted key with write access to Checkout, Customers, Products, Prices and Subscriptions is recommended.</div></div>
                </form>

              <?php else: // oauth / shopify ?>
                <form method="get" action="/connections/connect/<?= htmlspecialchars($card['key']) ?>" class="row g-2 align-items-end mt-3">
                  <input type="hidden" name="id" value="<?= $iid ?>">
                  <?php if ($card['connect_kind'] === 'shopify'): ?>
                    <div class="col-12">
                      <label class="form-label small mb-1">Store address</label>
                      <input type="text" name="shop" class="form-control form-control-sm" placeholder="your-store.myshopify.com" required>
                    </div>
                  <?php endif; ?>
                  <div class="col-7">
                    <select name="env" class="form-select form-select-sm">
                      <?php foreach ($environments as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>"<?= $e === 'development' ? ' selected' : '' ?>><?= $e === 'production' ? 'Live site' : 'Development' ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-5">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-box-arrow-up-right me-1"></i><?= $connected ? 'Connect another' : 'Connect' ?></button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('form[data-connectkey]').forEach(function(form){
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      const btn = form.querySelector('button[type=submit]');
      if (btn) btn.disabled = true;
      fetch(form.action, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams(new FormData(form)).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Could not connect'); if (btn) btn.disabled = false; }
      }).catch(function(){ alert('Could not connect'); if (btn) btn.disabled = false; });
    });
  });
  document.querySelectorAll('[data-disconnect]').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Disconnect this connection? This app will no longer be able to use it.')) return;
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
  document.querySelectorAll('form[data-whsec]').forEach(function(form){
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      const btn = form.querySelector('button[type=submit]'); if (btn) btn.disabled = true;
      fetch('/connections/webhooksecret', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams(new FormData(form)).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Could not save'); if (btn) btn.disabled = false; }
      }).catch(function(){ alert('Could not save'); if (btn) btn.disabled = false; });
    });
  });
  document.querySelectorAll('[data-whsec-clear]').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Remove this webhook secret?')) return;
      fetch('/connections/webhooksecret', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, cid: btn.getAttribute('data-whsec-clear'), clear: '1'}).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Could not clear'); }
      }).catch(function(){ alert('Could not clear'); });
    });
  });
})();
</script>
