<?php
/**
 * PLP preview — a customer-style product grid rendered on the control plane from
 * the instance's active products. The real (publishable) storefront ships on the
 * instance in Phase 4.
 * Vars: $title, $instance, $instanceUrl, $products (active only)
 */
$iid = (int)$instance->id;
$img = fn($rel) => htmlspecialchars($instanceUrl . '/' . ltrim((string)$rel, '/'));
?>
<div class="container py-4" style="max-width:960px">

  <div class="alert alert-warning-subtle border border-warning-subtle d-flex align-items-center justify-content-between gap-2 py-2 small mb-4">
    <div><i class="bi bi-eye me-1"></i><strong>Storefront preview</strong> — how shoppers will browse <code><?= htmlspecialchars($instance->slug) ?>.tiknix</code>.</div>
    <a href="/ecommerce/products?id=<?= $iid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Products</a>
  </div>

  <?php if (empty($products)): ?>
    <div class="alert alert-light border">No active products to show. Add a product (and mark it Active) to see it here.</div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($products as $p): $imgs = $p['images'] ?? []; ?>
        <div class="col-6 col-md-4">
          <a href="/ecommerce/preview?id=<?= $iid ?>&sku=<?= urlencode($p['sku']) ?>" class="text-decoration-none text-body">
            <div class="card h-100 border">
              <?php if (!empty($imgs)): ?>
                <img src="<?= $img($imgs[0]) ?>" alt="<?= htmlspecialchars($p['title']) ?>" class="card-img-top" style="aspect-ratio:1;object-fit:cover" onerror="this.style.opacity=.3">
              <?php else: ?>
                <div class="card-img-top bg-body-secondary d-flex align-items-center justify-content-center" style="aspect-ratio:1"><i class="bi bi-image fs-2 text-body-secondary"></i></div>
              <?php endif; ?>
              <div class="card-body p-2">
                <?php if (!empty($p['category'])): ?><div class="text-body-secondary" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em"><?= htmlspecialchars($p['category']) ?></div><?php endif; ?>
                <div class="fw-semibold small text-truncate"><?= htmlspecialchars($p['title']) ?></div>
                <div class="text-primary small">$<?= number_format((float)($p['price'] ?? 0), 2) ?>
                  <?php if (!empty($p['serialized'])): ?><span class="badge bg-info-subtle text-info-emphasis border ms-1" style="font-size:.6rem">Unique</span><?php endif; ?>
                </div>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
