<?php
/**
 * Product create/edit form. Fields POST to /ecommerce/productsave (JSON). Images
 * upload separately to /ecommerce/productimage and are only available once the
 * product exists (a new product is saved first, then you add images).
 * Vars: $title, $instance, $instanceUrl, $product (array|null)
 */
$iid = (int)$instance->id;
$p = $product ?? [];
$isEdit = !empty($p['sku']);
$v = fn($k, $d = '') => htmlspecialchars((string)($p[$k] ?? $d));
$serialized = !empty($p['serialized']);
$units = '';
foreach (($p['units'] ?? []) as $u) { $units .= ($u['serial'] ?? '') . "\n"; }
?>
<div class="container py-4" style="max-width:760px">

  <div class="d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-box-seam fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0"><?= htmlspecialchars($title) ?></h1>
      <div class="text-body-secondary small">for <code><?= htmlspecialchars($instance->slug) ?>.tiknix</code></div>
    </div>
  </div>

  <form id="productForm" class="card border">
    <div class="card-body">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $iid ?>">

      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label small mb-1">SKU</label>
          <input type="text" name="sku" class="form-control form-control-sm" value="<?= $v('sku') ?>" placeholder="watch-001" <?= $isEdit ? 'readonly' : 'required' ?>>
          <div class="form-text">Letters, numbers, dashes. Used as the file name.</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label small mb-1">Category</label>
          <input type="text" name="category" class="form-control form-control-sm" value="<?= $v('category') ?>" placeholder="Watches">
        </div>
        <div class="col-12">
          <label class="form-label small mb-1">Title</label>
          <input type="text" name="title" class="form-control form-control-sm" value="<?= $v('title') ?>" required>
        </div>
        <div class="col-12">
          <label class="form-label small mb-1">Description</label>
          <textarea name="description" class="form-control form-control-sm" rows="3"><?= $v('description') ?></textarea>
        </div>
        <div class="col-sm-4">
          <label class="form-label small mb-1">Price</label>
          <input type="number" step="0.01" min="0" name="price" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($p['price'] ?? '')) ?>">
        </div>
        <div class="col-sm-2">
          <label class="form-label small mb-1">Currency</label>
          <input type="text" name="currency" class="form-control form-control-sm" value="<?= $v('currency', 'usd') ?>" maxlength="3">
        </div>
        <div class="col-sm-6">
          <label class="form-label small mb-1">Stripe price ID <span class="text-body-secondary">(optional)</span></label>
          <input type="text" name="stripe_price_id" class="form-control form-control-sm" value="<?= $v('stripePriceId') ?>" placeholder="price_…">
        </div>
      </div>

      <hr class="my-3">

      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" role="switch" id="serialized" name="serialized" value="1" <?= $serialized ? 'checked' : '' ?>>
        <label class="form-check-label" for="serialized">Serialized — each item is a unique unit (watches, handbags)</label>
      </div>

      <div id="stockRow" class="row g-3 <?= $serialized ? 'd-none' : '' ?>">
        <div class="col-sm-4">
          <label class="form-label small mb-1">Stock</label>
          <input type="number" min="0" name="stock" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($p['stock'] ?? 0)) ?>">
        </div>
      </div>

      <div id="serialRows" class="row g-3 <?= $serialized ? '' : 'd-none' ?>">
        <div class="col-sm-8">
          <label class="form-label small mb-1">Serial numbers</label>
          <textarea name="units" class="form-control form-control-sm" rows="4" placeholder="One serial per line"><?= htmlspecialchars(trim($units)) ?></textarea>
          <div class="form-text">Each line becomes a unique unit that can be held in a cart.</div>
        </div>
        <div class="col-sm-4">
          <label class="form-label small mb-1">Hold time (minutes)</label>
          <input type="number" min="0" name="hold_minutes" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($p['holdMinutes'] ?? 10)) ?>">
          <div class="form-text">How long a unit is reserved in a cart.</div>
        </div>
      </div>

      <hr class="my-3">

      <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= (!$isEdit || !empty($p['active'])) ? 'checked' : '' ?>>
        <label class="form-check-label" for="active">Active — visible in the storefront</label>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
      <a href="/ecommerce/products?id=<?= $iid ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
      <div class="d-flex gap-2">
        <?php if ($isEdit): ?><a href="/ecommerce/preview?id=<?= $iid ?>&sku=<?= $v('sku') ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>Preview</a><?php endif; ?>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Save product</button>
      </div>
    </div>
  </form>

  <?php if ($isEdit): ?>
    <div class="card border mt-3">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-images me-1"></i>Images</div>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php foreach (($p['images'] ?? []) as $img): ?>
            <img src="<?= htmlspecialchars($instanceUrl . '/' . $img) ?>" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:6px" onerror="this.style.opacity=.3">
          <?php endforeach; ?>
          <?php if (empty($p['images'])): ?><div class="text-body-secondary small">No images yet.</div><?php endif; ?>
        </div>
        <form id="imageForm" class="d-flex align-items-center gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $iid ?>">
          <input type="hidden" name="sku" value="<?= $v('sku') ?>">
          <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control form-control-sm" required>
          <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload me-1"></i>Upload</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="text-body-secondary small mt-2"><i class="bi bi-info-circle me-1"></i>Save the product first, then you can add images.</div>
  <?php endif; ?>
</div>

<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  const iid = <?= $iid ?>;
  const ser = document.getElementById('serialized');
  const stockRow = document.getElementById('stockRow');
  const serialRows = document.getElementById('serialRows');
  ser.addEventListener('change', function(){
    stockRow.classList.toggle('d-none', ser.checked);
    serialRows.classList.toggle('d-none', !ser.checked);
  });

  document.getElementById('productForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    const form = ev.target;
    const btn = form.querySelector('button[type=submit]');
    if (btn) btn.disabled = true;
    fetch('/ecommerce/productsave', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
      body: new URLSearchParams(new FormData(form)).toString()
    }).then(r=>r.json()).then(function(j){
      if (j && j.success) { location.href = '/ecommerce/productedit?id=' + iid + '&sku=' + encodeURIComponent(j.data.sku); }
      else { alert((j && j.message) || 'Could not save'); if (btn) btn.disabled = false; }
    }).catch(function(){ alert('Could not save'); if (btn) btn.disabled = false; });
  });

  const imgForm = document.getElementById('imageForm');
  if (imgForm) imgForm.addEventListener('submit', function(ev){
    ev.preventDefault();
    const btn = imgForm.querySelector('button[type=submit]');
    if (btn) btn.disabled = true;
    fetch('/ecommerce/productimage', {
      method: 'POST',
      headers: {'X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
      body: new FormData(imgForm)
    }).then(r=>r.json()).then(function(j){
      if (j && j.success) { location.reload(); }
      else { alert((j && j.message) || 'Could not upload'); if (btn) btn.disabled = false; }
    }).catch(function(){ alert('Could not upload'); if (btn) btn.disabled = false; });
  });
})();
</script>
