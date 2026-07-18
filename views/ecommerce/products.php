<?php
/**
 * Product list for the tiknix.com store.
 * Vars: $title, $products (array of product arrays)
 */
$fmt = fn($p) => '$' . number_format((float)($p['price'] ?? 0), 2);
$imgUrl = fn($rel) => '/products/' . ltrim((string)$rel, '/');
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-box-seam fs-3"></i>
      <div>
        <h1 class="h4 fw-bold mb-0">Products</h1>
        <div class="text-body-secondary small">Your store catalog</div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="/ecommerce" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Store</a>
      <?php if (!empty($products)): ?><a href="/products/" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>View storefront</a><?php endif; ?>
      <a href="/ecommerce/productedit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add product</a>
    </div>
  </div>

  <?php if (empty($products)): ?>
    <div class="alert alert-light border">
      <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>No products yet</div>
      Add your first product — it's saved as JSON in the store catalog and shows at <code>/products/</code>.
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr class="small text-body-secondary">
            <th style="width:56px"></th><th>Product</th><th>Price</th><th>Inventory</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): $sku = $p['sku']; $img = $p['images'][0] ?? ''; ?>
            <tr>
              <td>
                <?php if ($img): ?>
                  <img src="<?= htmlspecialchars($imgUrl($img)) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px" onerror="this.style.visibility='hidden'">
                <?php else: ?>
                  <div class="bg-body-secondary rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px"><i class="bi bi-image text-body-secondary"></i></div>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($p['title']) ?></div>
                <div class="small text-body-secondary"><code><?= htmlspecialchars($sku) ?></code><?php if (!empty($p['category'])): ?> · <?= htmlspecialchars($p['category']) ?><?php endif; ?></div>
              </td>
              <td><?= $fmt($p) ?></td>
              <td class="small">
                <?php if (!empty($p['serialized'])): ?>
                  <span class="badge bg-info-subtle text-info-emphasis border">Serialized</span>
                  <?= (int)count($p['units'] ?? []) ?> unit<?= count($p['units'] ?? []) === 1 ? '' : 's' ?>
                  <div class="text-body-secondary">Hold <?= (int)($p['holdMinutes'] ?? 0) ?> min</div>
                <?php else: ?>
                  <?= (int)($p['stock'] ?? 0) ?> in stock
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($p['active'])): ?>
                  <span class="badge bg-success-subtle text-success-emphasis border">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary-emphasis border">Hidden</span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a href="/products/<?= urlencode($sku) ?>/" target="_blank" class="btn btn-sm btn-outline-primary" title="View live page"><i class="bi bi-eye"></i></a>
                <a href="/ecommerce/productedit?sku=<?= urlencode($sku) ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                <button class="btn btn-sm btn-outline-danger" data-delete="<?= htmlspecialchars($sku) ?>" title="Delete"><i class="bi bi-trash"></i></button>
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
  document.querySelectorAll('[data-delete]').forEach(function(btn){
    btn.addEventListener('click', function(){
      const sku = btn.getAttribute('data-delete');
      if (!confirm('Delete product "' + sku + '"? This removes its JSON and images.')) return;
      fetch('/ecommerce/productdelete', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, sku: sku}).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); }
        else { alert((j && j.message) || 'Could not delete'); }
      }).catch(function(){ alert('Could not delete'); });
    });
  });
})();
</script>
