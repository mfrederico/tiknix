<?php
/**
 * Recorded orders (confirmed payments) — server-side DataTable.
 * Rows come from /ecommerce/ordersdata?instance=<id>&full=1 (see Ecommerce::ordersdata).
 * Vars: $title, $instanceId, $orderCount
 */
$sid = (int)($instanceId ?? 0);
?>
<div class="container py-4" style="max-width:1040px">

  <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-receipt fs-3"></i>
      <div>
        <h1 class="h4 fw-bold mb-0">Orders</h1>
        <div class="text-body-secondary small">Confirmed payments &middot; <?= (int)($orderCount ?? 0) ?> total</div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="/ecommerce?id=<?= $sid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Hub</a>
      <a href="/ecommerce/products" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-seam me-1"></i>Products</a>
    </div>
  </div>

  <?php if ((int)($orderCount ?? 0) === 0): ?>
    <div class="alert alert-light border">
      <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>No orders yet</div>
      Orders appear here once a buyer completes checkout and the payment provider's webhook confirms the payment.
    </div>
  <?php else: ?>
    <div class="card border">
      <div class="card-body">
        <div class="table-responsive">
          <table id="ordersTable" class="dt-server table table-hover align-middle mb-0"
                 data-dt-url="/ecommerce/ordersdata?instance=<?= $sid ?>&full=1"
                 data-dt-order="0:desc"
                 data-dt-page-length="25"
                 data-dt-search-placeholder="product, email, name, serial…"
                 style="width:100%">
            <thead>
              <tr>
                <th>When</th>
                <th>Product</th>
                <th>Customer</th>
                <th>Amount</th>
                <th data-dt-nosearch>Status</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
