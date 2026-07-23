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

// Pipelines that a GitHub push would fire (trigger.github) — surfaced on the GitHub
// deploy card so setting up the webhook has a visible payoff, and badged in the list.
$ghPipes = [];
foreach ($pipelines as $p) { if (!empty($p['github'])) $ghPipes[] = $p; }
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-plug fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Integrations</h1>
      <div class="text-body-secondary small">for <code><?= htmlspecialchars(($instance->slug) ?? '') ?>.tiknix</code></div>
    </div>
  </div>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-shield-check me-1"></i>
    One place for everything this instance is wired to — connectors, pipelines, and durable objects.
    Keys never leave the platform, and you can disconnect any of them at any time.
  </div>

  <?php if (!empty($instances) && count($instances) > 1): ?>
    <div class="mb-4">
      <div class="text-uppercase text-body-secondary small fw-semibold mb-2" style="letter-spacing:.06em">Instance</div>
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
                      <?php if (($card['category'] ?? '') === 'Payments' && empty($cn['revoked'])): ?>
                        <form data-whsec class="d-flex align-items-center gap-1 mt-1" style="max-width:480px">
                          <?= csrf_field() ?>
                          <input type="hidden" name="cid" value="<?= (int)$cn['id'] ?>">
                          <input type="password" name="secret" class="form-control form-control-sm" placeholder="<?= !empty($cn['webhookSet']) ? 'Webhook secret set ✓ — paste to replace' : 'Webhook signing secret (whsec_…)' ?>" autocomplete="off">
                          <button class="btn btn-sm btn-outline-secondary text-nowrap" type="submit">Save</button>
                          <?php if (!empty($cn['webhookSet'])): ?><button class="btn btn-sm btn-outline-danger" type="button" data-whsec-clear="<?= (int)$cn['id'] ?>" title="Remove secret"><i class="bi bi-x-lg"></i></button><?php endif; ?>
                        </form>
                        <?php
                          $whHost = (string)($_SERVER['HTTP_HOST'] ?? 'tiknix.com');
                          // Public webhook endpoints must be https for the provider to call them;
                          // only a local host stays http.
                          $whScheme = preg_match('/^(localhost|127\.0\.0\.1)(:|$)/', $whHost) ? 'http' : 'https';
                          $whUrl  = $whScheme . '://' . $whHost . '/shop/webhook/' . rawurlencode((string)$card['key']);
                        ?>
                        <div class="form-text ms-1">
                          <?php if (!empty($cn['webhookSet']) && !empty($cn['webhookHint'])): ?>
                            <span class="text-success-emphasis">Set ✓ ending <code>…<?= htmlspecialchars($cn['webhookHint']) ?></code>.</span>
                          <?php endif; ?>
                          Verifies incoming <?= $env === 'production' ? 'live' : 'test' ?> webhooks for this connection.
                        </div>
                        <div class="form-text ms-1 d-flex align-items-center gap-1">
                          <span class="text-nowrap">Endpoint:</span>
                          <code class="text-body-secondary text-truncate" style="max-width:340px"><?= htmlspecialchars($whUrl) ?></code>
                          <button class="btn btn-sm btn-link p-0 text-decoration-none" type="button" data-copy="<?= htmlspecialchars($whUrl) ?>" title="Copy endpoint URL"><i class="bi bi-clipboard"></i></button>
                        </div>
                      <?php endif; ?>

                      <?php if (($card['category'] ?? '') === 'Social' && empty($cn['revoked'])): ?>
                        <form data-social-publish class="d-flex align-items-center gap-1 mt-1 flex-wrap" style="max-width:520px">
                          <?= csrf_field() ?>
                          <input type="hidden" name="cid" value="<?= (int)$cn['id'] ?>">
                          <span class="input-group-text py-1 px-2 small">/social/</span>
                          <input type="text" name="slug" class="form-control form-control-sm" style="max-width:170px" placeholder="page-name" autocomplete="off">
                          <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-globe2 me-1"></i>Publish showcase</button>
                          <a class="small text-nowrap" data-social-url target="_blank" rel="noopener" style="display:none"></a>
                        </form>
                        <div class="form-text ms-1">Publish this account's reels &amp; photos to a public page.</div>
                      <?php endif; ?>

                      <?php // --- GitHub push→deploy webhook --- ?>
                      <?php if (($card['key'] ?? '') === 'github' && empty($cn['revoked'])): ?>
                        <div class="mt-2 pt-2 border-top" style="max-width:520px">
                          <div class="d-flex align-items-center gap-2 flex-wrap">
                            <button class="btn btn-sm btn-outline-<?= !empty($cn['webhookSet']) ? 'secondary' : 'dark' ?>" data-github-webhook>
                              <i class="bi bi-<?= !empty($cn['webhookSet']) ? 'arrow-repeat' : 'broadcast-pin' ?> me-1"></i><?= !empty($cn['webhookSet']) ? 'Re-provision deploy webhook' : 'Set up deploy webhook' ?>
                            </button>
                            <?php if (!empty($cn['webhookSet'])): ?>
                              <span class="badge bg-success-subtle text-success-emphasis border">Active<?php if (!empty($cn['webhookHint'])): ?> · ending <code>…<?= htmlspecialchars($cn['webhookHint']) ?></code><?php endif; ?></span>
                            <?php endif; ?>
                          </div>
                          <div class="form-text ms-1">
                            <?php if (empty($ghPipes)): ?>
                              A push to this repo will call <code>/webhook/github</code> — but no pipeline listens for it yet. Add a <code>trigger.github</code> to a pipeline in the editor to deploy on push.
                            <?php else: ?>
                              A push fires <?= count($ghPipes) ?> pipeline<?= count($ghPipes) === 1 ? '' : 's' ?>:
                              <?php $ghSlugs = array_map(fn($gp) => '<code>' . htmlspecialchars($gp['slug']) . '</code>', $ghPipes); ?>
                              <?= implode(', ', $ghSlugs) ?>.
                              Needs a GitHub token with <code>admin:repo_hook</code>.
                            <?php endif; ?>
                          </div>
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

  <!-- ===================== Pipelines ===================== -->
  <div class="d-flex align-items-center justify-content-between mt-5 mb-2">
    <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-0" style="letter-spacing:.06em">Pipelines &amp; automations</h2>
    <a href="/sidecar/launch/pipelines" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"><i class="bi bi-pencil-square me-1"></i>Open editor</a>
  </div>
  <?php if (empty($pipelines)): ?>
    <div class="alert alert-light border py-2 small">No pipelines yet. Build one — a scheduled job, a REST endpoint, or a stateful <em>durable object</em> — in the editor.</div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($pipelines as $p): ?>
        <div class="col-md-6">
          <div class="card h-100"><div class="card-body">
            <div class="d-flex align-items-start gap-3">
              <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
                <i class="bi bi-<?= $p['stateful'] ? 'box' : 'diagram-2' ?> fs-5 text-primary"></i>
              </div>
              <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?>
                  <?php if ($p['stateful']): ?><span class="badge text-bg-secondary ms-1">object</span><?php endif; ?>
                  <?php if ($p['expose_tool']): ?><span class="badge text-bg-info ms-1">tool</span><?php endif; ?>
                  <?php if ($p['expose_api']): ?><span class="badge text-bg-info ms-1">api</span><?php endif; ?>
                  <?php if ($p['cron'] !== ''): ?><span class="badge text-bg-light ms-1" title="<?= htmlspecialchars($p['cron']) ?>"><i class="bi bi-clock"></i></span><?php endif; ?>
                  <?php if (!empty($p['github'])): $ghb = $p['github']; $ghBr = is_array($ghb['branches'] ?? null) ? implode(', ', $ghb['branches']) : 'any branch'; ?><span class="badge text-bg-dark ms-1" title="Fires on GitHub push (<?= htmlspecialchars($ghBr) ?>)"><i class="bi bi-github"></i> push</span><?php endif; ?>
                </div>
                <div class="text-body-secondary small"><code><?= htmlspecialchars($p['slug']) ?></code> · <?= (int)$p['steps'] ?> step<?= (int)$p['steps'] === 1 ? '' : 's' ?><?php if ($p['description'] !== ''): ?> · <?= htmlspecialchars($p['description']) ?><?php endif; ?></div>
                <div class="mt-2 d-flex gap-2">
                  <?php if (!$p['stateful']): ?>
                    <button class="btn btn-sm btn-outline-success" data-run-pipe="<?= htmlspecialchars($p['slug']) ?>"><i class="bi bi-play-fill"></i> Run</button>
                  <?php else: ?>
                    <span class="text-body-secondary small align-self-center"><i class="bi bi-box"></i> durable object — send messages in the editor</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ===================== Durable objects ===================== -->
  <?php if (!empty($durableObjects)): ?>
    <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-5" style="letter-spacing:.06em">Durable objects <span class="badge text-bg-secondary ms-1"><?= count($durableObjects) ?></span></h2>
    <div class="row g-3">
      <?php foreach ($durableObjects as $o): ?>
        <div class="col-md-6">
          <div class="card h-100"><div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div class="fw-semibold text-truncate"><code><?= htmlspecialchars($o['type']) ?></code> : <code><?= htmlspecialchars($o['key']) ?></code></div>
              <?php if ($o['wake_at'] > 0): ?><span class="badge text-bg-light flex-shrink-0" title="next alarm"><i class="bi bi-alarm"></i> <?= htmlspecialchars(date('H:i', $o['wake_at'])) ?></span><?php endif; ?>
            </div>
            <pre class="small bg-body-tertiary rounded p-2 mt-2 mb-1" style="max-height:8rem;overflow:auto"><?= htmlspecialchars(json_encode($o['state'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <div class="text-body-secondary small">updated <?= htmlspecialchars(date('Y-m-d H:i', $o['updated_at'])) ?></div>
          </div></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var csrf = '<?= function_exists("csrf_token") ? csrf_token() : "" ?>';
  var iid  = <?= (int)($instance->id ?? 0) ?>;
  document.querySelectorAll('[data-run-pipe]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var slug = btn.getAttribute('data-run-pipe');
      btn.disabled = true;
      fetch('/connections/pipelinerun', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, id: iid, slug: slug}).toString()
      }).then(function(r){ return r.json(); }).then(function(j){
        btn.disabled = false;
        if (j && j.success) { btn.className = 'btn btn-sm btn-success'; btn.innerHTML = '<i class="bi bi-check-lg"></i> Queued #' + (j.data && j.data.run_id ? j.data.run_id : ''); }
        else { alert((j && j.message) || 'Run failed'); }
      }).catch(function(){ btn.disabled = false; alert('Run failed'); });
    });
  });
})();
</script>

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
  document.querySelectorAll('form[data-social-publish]').forEach(function(form){
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      const btn = form.querySelector('button[type=submit]'); if (btn) btn.disabled = true;
      fetch('/connections/publishfeed', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams(new FormData(form)).toString()
      }).then(r=>r.json()).then(function(j){
        if (btn) btn.disabled = false;
        if (j && j.success) {
          var a = form.querySelector('[data-social-url]');
          if (a && j.data && j.data.url) { a.href = j.data.url; a.textContent = j.data.url; a.style.display = ''; }
          alert((j.message || 'Published') + (j.data && typeof j.data.items === 'number' ? ' — ' + j.data.items + ' item(s).' : ''));
        } else { alert((j && j.message) || 'Could not publish'); }
      }).catch(function(){ if (btn) btn.disabled = false; alert('Could not publish'); });
    });
  });
  document.querySelectorAll('[data-github-webhook]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var iid = <?= (int)($instance->id ?? 0) ?>;
      var orig = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Provisioning…';
      fetch('/connections/githubwebhook', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, id: iid}).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Could not set up the webhook'); btn.disabled = false; btn.innerHTML = orig; }
      }).catch(function(){ alert('Could not set up the webhook'); btn.disabled = false; btn.innerHTML = orig; });
    });
  });
  document.querySelectorAll('[data-copy]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var txt = btn.getAttribute('data-copy') || '';
      var done = function(){ var i = btn.querySelector('i'); if (i){ i.className = 'bi bi-check-lg'; setTimeout(function(){ i.className = 'bi bi-clipboard'; }, 1200); } };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done).catch(function(){ window.prompt('Copy endpoint URL:', txt); }); }
      else { window.prompt('Copy endpoint URL:', txt); }
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
