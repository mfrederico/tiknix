<?php
/**
 * PDP preview — a customer-style product page for one product, rendered on the
 * control plane from the instance's product JSON. The real (publishable)
 * storefront ships on the instance in Phase 4.
 * Vars: $title, $instance, $instanceUrl, $product
 */
$iid = (int)$instance->id;
$p = $product;
$img = fn($rel) => htmlspecialchars($instanceUrl . '/' . ltrim((string)$rel, '/'));
$price = '$' . number_format((float)($p['price'] ?? 0), 2);
$available = !empty($p['serialized']) ? count($p['units'] ?? []) : (int)($p['stock'] ?? 0);
?>
<div class="container py-4" style="max-width:960px">

  <div class="alert alert-warning-subtle border border-warning-subtle d-flex align-items-center justify-content-between gap-2 py-2 small mb-4">
    <div><i class="bi bi-eye me-1"></i><strong>Preview</strong> — this is how the product page will look. Cart &amp; checkout arrive with the storefront.</div>
    <a href="/ecommerce/products?id=<?= $iid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Products</a>
  </div>

  <div class="row g-4">
    <!-- images -->
    <div class="col-md-6">
      <?php $images = $p['images'] ?? []; ?>
      <?php if (!empty($images)): ?>
        <img id="pdpMain" src="<?= $img($images[0]) ?>" alt="<?= htmlspecialchars($p['title']) ?>"
             class="w-100 rounded border" style="aspect-ratio:1;object-fit:cover" onerror="this.style.opacity=.3">
        <?php if (count($images) > 1): ?>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php foreach ($images as $im): ?>
              <img src="<?= $img($im) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:6px;cursor:pointer"
                   class="border" onclick="document.getElementById('pdpMain').src=this.src" onerror="this.style.opacity=.3">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="w-100 rounded border bg-body-secondary d-flex align-items-center justify-content-center" style="aspect-ratio:1">
          <i class="bi bi-image fs-1 text-body-secondary"></i>
        </div>
      <?php endif; ?>
    </div>

    <!-- details -->
    <div class="col-md-6">
      <?php if (!empty($p['category'])): ?>
        <div class="text-uppercase text-body-secondary small fw-semibold" style="letter-spacing:.06em"><?= htmlspecialchars($p['category']) ?></div>
      <?php endif; ?>
      <h1 class="h3 fw-bold mb-1"><?= htmlspecialchars($p['title']) ?></h1>
      <div class="h4 text-primary mb-3"><?= $price ?> <span class="text-body-secondary fs-6 text-uppercase"><?= htmlspecialchars($p['currency'] ?? 'usd') ?></span></div>

      <?php if (!empty($p['description'])): ?>
        <p class="text-body-secondary" style="white-space:pre-line"><?= htmlspecialchars($p['description']) ?></p>
      <?php endif; ?>

      <div class="mb-3">
        <?php if (!empty($p['serialized'])): ?>
          <span class="badge bg-info-subtle text-info-emphasis border me-1">Unique item</span>
          <?= $available ?> available ·
          held <?= (int)($p['holdMinutes'] ?? 0) ?> min in your cart
        <?php elseif ($available > 0): ?>
          <span class="badge bg-success-subtle text-success-emphasis border">In stock</span> · <?= $available ?> available
        <?php else: ?>
          <span class="badge bg-secondary-subtle text-secondary-emphasis border">Out of stock</span>
        <?php endif; ?>
      </div>

      <button class="btn btn-primary" disabled title="Cart arrives with the storefront (Phase 4)">
        <i class="bi bi-cart-plus me-1"></i>Add to cart
      </button>
      <div class="form-text mt-2">SKU <code><?= htmlspecialchars($p['sku']) ?></code></div>

      <div class="mt-3">
        <a href="/ecommerce/productedit?id=<?= $iid ?>&sku=<?= urlencode($p['sku']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit this product</a>
      </div>
    </div>
  </div>
</div>
