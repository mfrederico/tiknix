<?php
/**
 * Subscribers — the current-state subscription mirror (server-side DataTable).
 * Rows come from /ecommerce/subscribersdata?instance=<id> (see Ecommerce::subscribersdata).
 * Vars: $title, $instanceId, $activeCount, $total
 */
$sid = (int)($instanceId ?? 0);
?>
<div class="container py-4" style="max-width:1040px">

  <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-arrow-repeat fs-3"></i>
      <div>
        <h1 class="h4 fw-bold mb-0">Subscribers</h1>
        <div class="text-body-secondary small"><?= (int)($activeCount ?? 0) ?> active &middot; <?= (int)($total ?? 0) ?> total</div>
      </div>
    </div>
    <a href="/ecommerce?id=<?= $sid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Hub</a>
  </div>

  <?php if ((int)($total ?? 0) === 0): ?>
    <div class="alert alert-light border">
      <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>No subscribers yet</div>
      When a buyer checks out a <strong>subscription</strong> product, they appear here. Status tracks live with your Stripe account (active, past-due, canceled) as renewals and cancellations happen.
    </div>
  <?php else: ?>
    <div class="card border">
      <div class="card-body">
        <div class="table-responsive">
          <table id="subscribersTable" class="dt-server table table-hover align-middle mb-0"
                 data-dt-url="/ecommerce/subscribersdata?instance=<?= $sid ?>"
                 data-dt-order="0:desc"
                 data-dt-page-length="25"
                 data-dt-search-placeholder="subscriber, product…"
                 style="width:100%">
            <thead>
              <tr>
                <th>Started</th>
                <th>Subscriber</th>
                <th>Product</th>
                <th data-dt-nosearch>Status</th>
                <th>Renews</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
