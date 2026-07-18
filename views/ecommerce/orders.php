<?php
/**
 * Recorded orders (confirmed payments from the storefront webhook).
 * Vars: $title, $orders (shoporder beans)
 */
$money = fn($cents, $cur) => strtoupper((string)($cur ?: 'usd')) . ' ' . number_format(((int)$cents) / 100, 2);
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-receipt fs-3"></i>
      <div>
        <h1 class="h4 fw-bold mb-0">Orders</h1>
        <div class="text-body-secondary small">Confirmed payments</div>
      </div>
    </div>
    <a href="/ecommerce/products" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-seam me-1"></i>Products</a>
  </div>

  <?php if (empty($orders)): ?>
    <div class="alert alert-light border">
      <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>No orders yet</div>
      Orders appear here once a buyer completes checkout and the payment provider's webhook confirms the payment.
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr class="small text-body-secondary"><th>When</th><th>Product</th><th>Customer</th><th>Amount</th><th>Provider</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td class="small text-body-secondary text-nowrap"><?= htmlspecialchars((string)$o->createdAt) ?></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars((string)($o->title ?: $o->sku)) ?></div>
                <?php if ($o->sku): ?><div class="small text-body-secondary"><code><?= htmlspecialchars((string)$o->sku) ?></code></div><?php endif; ?>
                <?php if ($o->unitSerial): ?><div class="small"><span class="badge bg-info-subtle text-info-emphasis border">unit <?= htmlspecialchars((string)$o->unitSerial) ?></span></div><?php endif; ?>
              </td>
              <td class="small">
                <?= htmlspecialchars((string)($o->email ?: '—')) ?>
                <?php if ($o->customerName): ?><div class="text-body-secondary"><?= htmlspecialchars((string)$o->customerName) ?></div><?php endif; ?>
              </td>
              <td class="text-nowrap"><?= htmlspecialchars($money($o->amountTotal, $o->currency)) ?></td>
              <td class="small">
                <span class="text-capitalize"><?= htmlspecialchars((string)$o->provider) ?></span>
                <span class="badge bg-<?= $o->environment === 'production' ? 'success' : 'secondary' ?>-subtle text-<?= $o->environment === 'production' ? 'success' : 'secondary' ?>-emphasis border"><?= $o->environment === 'production' ? 'Live' : 'Test' ?></span>
              </td>
              <td>
                <?php $oversold = $o->status === 'paid-oversold'; ?>
                <span class="badge bg-<?= $oversold ? 'warning' : 'success' ?>-subtle text-<?= $oversold ? 'warning' : 'success' ?>-emphasis border text-capitalize" <?= $oversold ? 'title="Paid but stock was already depleted — reconcile manually"' : '' ?>><?= htmlspecialchars((string)$o->status) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
