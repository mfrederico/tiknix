<?php
/**
 * Connections hub for an instance — one place for every connection, grouped by
 * category (Deploy / Payments / Stores / …). Each card shows connect-vs-connected
 * state inline so nothing is hidden behind a separate screen.
 *
 * Vars: $instance, $cards (array), $environments (array), $categoryOrder (array)
 *   card: key, label, blurb, category, icon, color, auth_type, connect_kind
 *         ('api_key'|'shopify'|'oauth'), configured, features[],
 *         manage_url|null, connections[] (id, environment, name, eid, url, enabled, revoked, lastError)
 */
$iid = (int)$instance->id;
$envBadge = ['development' => 'secondary', 'production' => 'success'];
// A project shared with you (a team) shows here read-only: connecting, renaming and
// removing are its owner's. Said up front — the page used to bounce to Projects instead.
if (empty($canManage)): ?>
  <div class="container pt-3" style="max-width:1100px">
    <div class="alert alert-warning py-2 small mb-0">
      <i class="bi bi-people me-1"></i>
      <strong><?= htmlspecialchars($instance->name ?: $instance->slug) ?></strong> is shared with you. You can see its connections;
      connecting, renaming and disconnecting are for its owner<?= !empty($ownerEmail) ? ' (' . htmlspecialchars($ownerEmail) . ')' : '' ?>.
    </div>
  </div>
<?php endif;

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
      <div class="text-body-secondary small">what <code><?= htmlspecialchars(($instance->slug) ?? '') ?>.tiknix</code> is connected to</div>
    </div>
  </div>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-shield-check me-1"></i>
    External accounts this instance connects to — GitHub, Stripe, stores &amp; more.
    Keys never leave the platform, and you can disconnect any of them at any time.
    The automations it <em>exposes</em> (pipelines, tools &amp; APIs) live on the
    <a href="/integrations?id=<?= $iid ?>" class="text-decoration-underline">Integrations</a> page.
  </div>
  <?php if (!empty($listError)): ?>
    <div class="alert alert-danger py-2 small mb-4">
      <i class="bi bi-exclamation-triangle me-1"></i>
      Could not ask this project what it is connected to, so the cards below show no connections — that is not the same as none.
      <code><?= htmlspecialchars($listError) ?></code>
    </div>
  <?php else: ?>
    <div class="alert alert-light border py-2 small mb-4">
      <i class="bi bi-box-arrow-up-right me-1"></i>
      This project keeps its connections in its own app. Connect and disconnect here; webhook secrets and keys are on
      <a href="/projects/open?to=<?= rawurlencode('/connections') ?>" target="_blank" rel="noopener" class="text-decoration-underline">its own Connections page</a>.
    </div>
  <?php endif; ?>

  <?php
  /* NO INSTANCE SWITCHER, and no project name repeated here either. This page shows the
     connections of the project selected in /projects, and the shell's topbar chip names
     that project on every page — saying it twice invites the two from drifting, which is
     the failure this whole mechanism exists to prevent. */
  ?>

  <?php
  /* This site's own sign-up gate (Turnstile). Install-local, so on the control plane it
     manages THIS site's registration, not the selected project's — kept at the top and
     under its own "Security" heading so it does not read as a per-project connector. */
  include \Flight::view()->getTemplate('connections/_turnstile');
  ?>

  <?php
  /* Models: the MEMBER's own model endpoints + keys (MODEL_CONNECTIONS_PLAN.md). Like the
     Security card, not a per-project connector: the same list shows whichever project is
     selected, because a model connection belongs to the person, not the project. */
  include __DIR__ . '/_models.php';
  ?>

  <div class="alert alert-light border py-2 small mt-3 mb-0">
    <i class="bi bi-rocket-takeoff me-1"></i>Its domains and exports to your own servers are on
    <a href="/deploy" class="text-decoration-underline">Deploy</a>.
  </div>

  <?php if (!empty($connectorErrors)): ?>
    <div class="alert alert-warning mt-3">
      <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Some connector manifests were not loaded</div>
      <ul class="mb-0 small">
        <?php foreach ($connectorErrors as $ce): ?><li><?= htmlspecialchars($ce) ?></li><?php endforeach; ?>
      </ul>
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
                          <?php if (!empty($cn['specOps'])): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border ms-1" title="Imported from this API's OpenAPI/Swagger description — pipelines can call these by name."><?= (int)$cn['specOps'] ?> endpoints</span>
                          <?php endif; ?>
                          <?php if (!empty($cn['keyHint'])): ?>
                            <span class="text-body-secondary">· key <code>…<?= htmlspecialchars($cn['keyHint']) ?></code></span>
                          <?php endif; ?>
                          <?php if (!empty($cn['revoked'])): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis border ms-1">Disconnected</span>
                          <?php elseif (!empty($cn['lastError'])): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border ms-1" title="<?= htmlspecialchars($cn['lastError']) ?>">Needs attention</span>
                          <?php endif; ?>
                        </div>
                        <button class="btn btn-sm btn-outline-danger py-0 px-1" data-disconnect="<?= (int)$cn['id'] ?>" title="Disconnect"><i class="bi bi-x-lg"></i></button>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php // --- connect action, per connect_kind --- ?>
              <?php if (!$card['configured']): ?>
                <div class="form-text mt-2">Not available on this server yet.</div>

              <?php elseif ($card['connect_kind'] === 'api_key'): ?>
                <form data-connectkey action="/connections/connectkey" method="post" class="row g-2 align-items-end mt-3">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $iid ?>">
                  <input type="hidden" name="type" value="<?= htmlspecialchars($card['key']) ?>">
                  <?php
                    // Driven by the connector's meta(), not hardcoded. This form used to
                    // show Stripe's "sk_live_…" placeholder for EVERY api_key connector,
                    // so the Monday and Telegram cards told you to paste a Stripe key.
                    // A connector now says what it wants.
                    $cm     = $card['meta'] ?? [];
                    $keyLbl = $cm['key_label']       ?? ($card['label'] . ' secret key');
                    $keyPh  = $cm['key_placeholder'] ?? '';
                    $keyReq = $cm['key_required']    ?? true;
                  ?>
                  <?php foreach (($cm['fields'] ?? []) as $f): ?>
                    <div class="col-12">
                      <label class="form-label small mb-1"><?= htmlspecialchars($f['label'] ?? $f['name']) ?></label>
                      <?php if (($f['type'] ?? 'text') === 'select'): ?>
                        <select name="<?= htmlspecialchars($f['name']) ?>" class="form-select form-select-sm">
                          <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
                            <option value="<?= htmlspecialchars((string)$ov) ?>"<?= (string)$ov === (string)($f['default'] ?? '') ? ' selected' : '' ?>><?= htmlspecialchars((string)$ol) ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php else: ?>
                        <input type="<?= htmlspecialchars($f['type'] ?? 'text') ?>" name="<?= htmlspecialchars($f['name']) ?>"
                               class="form-control form-control-sm"
                               placeholder="<?= htmlspecialchars($f['placeholder'] ?? '') ?>"
                               value="<?= htmlspecialchars((string)($f['default'] ?? '')) ?>"
                               <?= !empty($f['required']) ? 'required' : '' ?>>
                      <?php endif; ?>
                      <?php if (!empty($f['help'])): ?>
                        <div class="form-text small"><?= htmlspecialchars($f['help']) ?></div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                  <div class="col-12">
                    <label class="form-label small mb-1"><?= $connected ? 'Connect another' : 'Connect' ?> — <?= htmlspecialchars($keyLbl) ?></label>
                    <input type="password" name="key" class="form-control form-control-sm" placeholder="<?= htmlspecialchars($keyPh) ?>" autocomplete="off" <?= $keyReq ? 'required' : '' ?>>
                    <?php if (!empty($cm['key_hint'])): ?>
                      <div class="form-text small"><?= htmlspecialchars($cm['key_hint']) ?></div>
                    <?php endif; ?>
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
                <?php /* POST, not GET. The custom-app fields below carry an API SECRET, and a
                         GET form puts it in the query string — the browser history, the access
                         log, and the Referer sent to the next page. */ ?>
                <form method="post" action="/connections/connect/<?= htmlspecialchars($card['key']) ?>" class="row g-2 align-items-end mt-3">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $iid ?>">
                  <?php if ($card['connect_kind'] === 'shopify'): ?>
                    <div class="col-12">
                      <label class="form-label small mb-1">Store address</label>
                      <input type="text" name="shop" class="form-control form-control-sm" placeholder="your-store.myshopify.com" required>
                    </div>
                    <div class="col-12">
                      <details>
                        <summary class="small text-secondary" style="cursor:pointer">Use this store's own custom app</summary>
                        <div class="row g-2 mt-1">
                          <div class="col-12">
                            <p class="small text-secondary mb-2">
                              Leave blank to connect through the tiknix app, which is what most stores use.
                              Fill these in to authorise against the merchant's own Shopify custom app instead —
                              their scopes and their billing. Both fields are required together.
                            </p>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label small mb-1">API key</label>
                            <input type="text" name="app_key" class="form-control form-control-sm" autocomplete="off" placeholder="from their custom app">
                          </div>
                          <div class="col-md-6">
                            <label class="form-label small mb-1">API secret key</label>
                            <input type="password" name="app_secret" class="form-control form-control-sm" autocomplete="new-password" placeholder="stored encrypted">
                          </div>
                          <div class="col-12">
                            <label class="form-label small mb-1">Scopes <span class="text-secondary">(optional)</span></label>
                            <input type="text" name="app_scopes" class="form-control form-control-sm" autocomplete="off"
                                   placeholder="read_products,read_orders">
                            <div class="form-text small">
                              Blank uses this server's default. A custom app has its own scope set — asking for
                              scopes it was not configured with makes Shopify reject the whole authorisation.
                            </div>
                          </div>
                        </div>
                      </details>
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
  // Which project these actions act on — shared by every handler below.
  const iid = <?= (int) ($instance->id ?? 0) ?>;

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
        else { tkAlert((j && j.message) || 'Could not connect', {type: 'error'}); if (btn) btn.disabled = false; }
      }).catch(function(){ tkAlert('Could not connect', {type: 'error'}); if (btn) btn.disabled = false; });
    });
  });
  document.querySelectorAll('[data-disconnect]').forEach(function(btn){
    btn.addEventListener('click', async function(){
      if (!await tkConfirm('Disconnect this connection? This app will no longer be able to use it.', {okText: 'Disconnect', danger: true})) return;
      fetch('/connections/disconnect', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, id: iid, cid: btn.getAttribute('data-disconnect')}).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); }
        else { tkAlert((j && j.message) || 'Could not disconnect', {type: 'error'}); }
      }).catch(function(){ tkAlert('Could not disconnect', {type: 'error'}); });
    });
  });
})();
</script>
