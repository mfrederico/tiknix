<?php
/**
 * Ecommerce hub — instance-scoped storefront tools. Each feature card surfaces
 * the connection it depends on, tying connections to the feature that uses them.
 * Vars: $title, $instances (id-keyed beans), $selected (bean|null), $stripe (['connected','connections'[]]|null)
 */
$sid = $selected ? (int)$selected->id : 0;
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-bag fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Ecommerce</h1>
      <div class="text-body-secondary small">Sell products and memberships from your instances, paid with Stripe.</div>
    </div>
  </div>

  <?php if (empty($instances)): ?>
    <div class="alert alert-light border">
      <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>No stores yet</div>
      Create an instance in <a href="/aibuilder">AI Builder</a> first — each instance is a store you can add products and payments to.
    </div>
  <?php else: ?>

    <?php // --- store picker --- ?>
    <div class="mb-4">
      <div class="text-uppercase text-body-secondary small fw-semibold mb-2" style="letter-spacing:.06em">Store</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($instances as $i): $active = (int)$i->id === $sid; ?>
          <a href="/ecommerce?id=<?= (int)$i->id ?>"
             class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <i class="bi bi-shop me-1"></i><?= htmlspecialchars($i->display_name ?: $i->slug) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card border mb-4">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-credit-card-2-front text-primary"></i>
          <div class="fw-semibold">Checkout payment source</div>
        </div>
        <div class="text-body-secondary small mb-2">Which store's connected provider (Stripe, PayPal, …) the storefront uses at checkout.</div>
        <form id="paySourceForm" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <div class="col-sm-5">
            <label class="form-label small mb-1">Payments from</label>
            <select name="instance" class="form-select form-select-sm">
              <option value="0">— none —</option>
              <?php foreach ($instances as $i): ?>
                <option value="<?= (int)$i->id ?>"<?= (int)$i->id === (int)($paymentSource['instance'] ?? 0) ? ' selected' : '' ?>><?= htmlspecialchars($i->display_name ?: $i->slug) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label small mb-1">Environment</label>
            <select name="env" class="form-select form-select-sm">
              <option value="development"<?= ($paymentSource['env'] ?? '') === 'development' ? ' selected' : '' ?>>Development</option>
              <option value="production"<?= ($paymentSource['env'] ?? 'production') === 'production' ? ' selected' : '' ?>>Live site</option>
            </select>
          </div>
          <div class="col-sm-3">
            <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
          </div>
          <div class="col-12"><div class="form-text">Connect a provider under <a href="/connections?id=<?= (int)($paymentSource['instance'] ?: $sid) ?>">Connections</a> for that store (must be an active secret/restricted key for the same environment).</div></div>
        </form>
      </div>
    </div>
    <script>
    (function(){
      var f = document.getElementById('paySourceForm'); if (!f) return;
      var csrf = <?= json_encode(csrf_token()) ?>;
      f.addEventListener('submit', function(ev){
        ev.preventDefault();
        var b = f.querySelector('button[type=submit]'); if (b) b.disabled = true;
        fetch('/ecommerce/paymentsource', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'}, body:new URLSearchParams(new FormData(f)).toString()})
          .then(function(r){return r.json();}).then(function(j){ if(b)b.disabled=false; alert((j&&j.message)||'Saved'); })
          .catch(function(){ if(b)b.disabled=false; alert('Could not save'); });
      });
    })();
    </script>

    <?php $connUrl = '/connections?id=' . $sid; ?>
    <div class="row g-3">

      <?php // --- Payments (tied to Stripe) --- ?>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 <?= !empty($stripe['connected']) ? 'border-success border-opacity-50' : '' ?>">
          <div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start gap-3">
              <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
                <i class="bi bi-credit-card fs-5 text-primary"></i>
              </div>
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div class="fw-semibold">Payments</div>
                  <?php if (!empty($stripe['connected'])): ?>
                    <span class="badge bg-success-subtle text-success-emphasis border">Stripe connected</span>
                  <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border">Not connected</span>
                  <?php endif; ?>
                </div>
                <div class="text-body-secondary small mt-1">Take payments and sell subscriptions with Stripe.</div>
              </div>
            </div>

            <?php if (!empty($stripe['connections'])): ?>
              <ul class="list-unstyled small mt-3 mb-0 border-top pt-2">
                <?php foreach ($stripe['connections'] as $cn): $env = $cn['environment']; ?>
                  <li class="py-1">
                    <span class="badge bg-<?= $env === 'production' ? 'success' : 'secondary' ?>-subtle text-<?= $env === 'production' ? 'success' : 'secondary' ?>-emphasis border me-1"><?= $env === 'production' ? 'Live site' : 'Development' ?></span>
                    <?= htmlspecialchars($cn['name']) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <div class="mt-auto pt-3">
              <a href="<?= htmlspecialchars($connUrl) ?>" class="btn btn-sm <?= !empty($stripe['connected']) ? 'btn-outline-primary' : 'btn-primary' ?>">
                <i class="bi bi-<?= !empty($stripe['connected']) ? 'gear' : 'plug' ?> me-1"></i><?= !empty($stripe['connected']) ? 'Manage connection' : 'Connect Stripe' ?>
              </a>
            </div>
          </div>
        </div>
      </div>

      <?php // --- Products & Inventory --- ?>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100">
          <div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start gap-3">
              <div class="rounded-circle bg-info-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
                <i class="bi bi-box-seam fs-5 text-info"></i>
              </div>
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div class="fw-semibold">Products &amp; Inventory</div>
                  <span class="badge bg-info-subtle text-info-emphasis border"><?= (int)($productCount ?? 0) ?> product<?= (int)($productCount ?? 0) === 1 ? '' : 's' ?></span>
                </div>
                <div class="text-body-secondary small mt-1">Add products, set inventory, and configure serialized units with timed holds.</div>
              </div>
            </div>
            <div class="mt-auto pt-3">
              <a href="/ecommerce/products" class="btn btn-sm btn-primary"><i class="bi bi-box-seam me-1"></i>Manage products</a>
            </div>
          </div>
        </div>
      </div>

      <?php // --- Storefront --- ?>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100">
          <div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start gap-3">
              <div class="rounded-circle bg-warning-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
                <i class="bi bi-grid fs-5 text-warning"></i>
              </div>
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div class="fw-semibold">Storefront</div>
                  <span class="badge bg-secondary-subtle text-secondary-emphasis border">Coming soon</span>
                </div>
                <div class="text-body-secondary small mt-1">Listing (PLP) and detail (PDP) pages assembled from your product JSON, with relative image paths so they travel when you publish.</div>
              </div>
            </div>
            <div class="mt-auto pt-3">
              <div class="form-text"><i class="bi bi-github me-1"></i>Published to your repo from Connections → Deploy.</div>
            </div>
          </div>
        </div>
      </div>

    </div>
  <?php endif; ?>
</div>
