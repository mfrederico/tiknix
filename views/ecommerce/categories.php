<?php
/**
 * Catalog (category) list for the tiknix.com store.
 * Vars: $title, $categories (array of {slug,title,products[]})
 */
?>
<div class="container py-4" style="max-width:820px">

  <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-collection fs-3"></i>
      <div>
        <h1 class="h4 fw-bold mb-0">Catalogs</h1>
        <div class="text-body-secondary small">Grouped product collections</div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="/ecommerce/products" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-seam me-1"></i>Products</a>
      <a href="/ecommerce/categoryedit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New catalog</a>
    </div>
  </div>

  <?php if (empty($categories)): ?>
    <div class="alert alert-light border">
      <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>No catalogs yet</div>
      Create a catalog, tick the products to include, and it's saved as <code>public/categories/&lt;slug&gt;.json</code> and shown at <code>/categories/&lt;slug&gt;/</code>.
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr class="small text-body-secondary"><th>Catalog</th><th>Products</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($categories as $c): $slug = $c['slug']; ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($c['title']) ?></div>
                <div class="small text-body-secondary"><code><?= htmlspecialchars($slug) ?></code></div>
              </td>
              <td><?= (int)count($c['products'] ?? []) ?> product<?= count($c['products'] ?? []) === 1 ? '' : 's' ?></td>
              <td class="text-end text-nowrap">
                <a href="/shop/catalog/<?= urlencode($slug) ?>/" target="_blank" class="btn btn-sm btn-outline-primary" title="View live page"><i class="bi bi-eye"></i></a>
                <a href="/ecommerce/categoryedit?slug=<?= urlencode($slug) ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                <button class="btn btn-sm btn-outline-danger" data-delete="<?= htmlspecialchars($slug) ?>" title="Delete"><i class="bi bi-trash"></i></button>
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
      const slug = btn.getAttribute('data-delete');
      if (!confirm('Delete catalog "' + slug + '"? (Products are not affected.)')) return;
      fetch('/ecommerce/categorydelete', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: csrf, slug: slug}).toString()
      }).then(r=>r.json()).then(function(j){
        if (j && j.success) { location.reload(); } else { alert((j && j.message) || 'Could not delete'); }
      }).catch(function(){ alert('Could not delete'); });
    });
  });
})();
</script>
