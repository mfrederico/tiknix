<?php
/**
 * Catalog (category) create/edit — a title plus a checkbox pick of products.
 * POSTs the picked product slugs to /ecommerce/categorysave, which writes
 * public/categories/<slug>.json. Vars: $title, $category (array|null), $products
 */
$c = $category ?? [];
$isEdit = !empty($c['slug']);
$picked = array_flip($c['products'] ?? []);
$v = fn($k, $d = '') => htmlspecialchars((string)($c[$k] ?? $d));
$imgUrl = fn($rel) => '/shop/product/' . ltrim((string)$rel, '/');
?>
<div class="container py-4" style="max-width:760px">

  <div class="d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-collection fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0"><?= htmlspecialchars($title) ?></h1>
      <div class="text-body-secondary small">Pick the products in this catalog</div>
    </div>
  </div>

  <form id="catForm" class="card border">
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label small mb-1">Slug</label>
          <input type="text" name="slug" class="form-control form-control-sm" value="<?= $v('slug') ?>" placeholder="new-arrivals" <?= $isEdit ? 'readonly' : 'required' ?>>
          <div class="form-text">Used as the file name and URL (/categories/&lt;slug&gt;/).</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label small mb-1">Title</label>
          <input type="text" name="title" class="form-control form-control-sm" value="<?= $v('title') ?>" placeholder="New Arrivals">
        </div>
      </div>

      <div class="form-label small mb-1">Products</div>
      <?php if (empty($products)): ?>
        <div class="text-body-secondary small">No products yet — <a href="/ecommerce/productedit">add one</a> first.</div>
      <?php else: ?>
        <div class="border rounded" style="max-height:360px;overflow:auto">
          <?php foreach ($products as $p): $sku = $p['sku']; $img = $p['images'][0] ?? ''; ?>
            <label class="d-flex align-items-center gap-2 px-2 py-2 border-bottom" style="cursor:pointer">
              <input class="form-check-input mt-0" type="checkbox" name="products[]" value="<?= htmlspecialchars($sku) ?>" <?= isset($picked[$sku]) ? 'checked' : '' ?>>
              <?php if ($img): ?><img src="<?= htmlspecialchars($imgUrl($img)) ?>" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:4px" onerror="this.style.visibility='hidden'"><?php else: ?><span class="bg-body-secondary rounded d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px"><i class="bi bi-image text-body-secondary small"></i></span><?php endif; ?>
              <span class="flex-grow-1">
                <span class="fw-semibold"><?= htmlspecialchars($p['title']) ?></span>
                <span class="text-body-secondary small">· <code><?= htmlspecialchars($sku) ?></code></span>
              </span>
              <span class="text-body-secondary small">$<?= number_format((float)($p['price'] ?? 0), 2) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="card-footer d-flex justify-content-between">
      <a href="/ecommerce/categories" class="btn btn-sm btn-outline-secondary">Cancel</a>
      <div class="d-flex gap-2">
        <?php if ($isEdit): ?><a href="/shop/catalog/<?= urlencode($c['slug']) ?>/" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>View live</a><?php endif; ?>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Save catalog</button>
      </div>
    </div>
  </form>
</div>

<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  document.getElementById('catForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    const form = ev.target;
    const btn = form.querySelector('button[type=submit]');
    if (btn) btn.disabled = true;
    fetch('/ecommerce/categorysave', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
      body: new URLSearchParams(new FormData(form)).toString()
    }).then(r=>r.json()).then(function(j){
      if (j && j.success) { location.href = '/ecommerce/categoryedit?slug=' + encodeURIComponent(j.data.slug); }
      else { alert((j && j.message) || 'Could not save'); if (btn) btn.disabled = false; }
    }).catch(function(){ alert('Could not save'); if (btn) btn.disabled = false; });
  });
})();
</script>
