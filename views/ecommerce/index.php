<?php
/**
 * Ecommerce hub — landing surface for the feature-flagged storefront tools.
 * Vars: $title
 */
?>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1"><i class="bi bi-bag me-2"></i>Ecommerce</h3>
      <div class="text-secondary">Sell products and memberships from your instances, paid with Stripe.</div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6 col-lg-4">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-credit-card me-2"></i>Payments</h5>
          <p class="card-text text-secondary small">Connect a Stripe account to take payments and sell subscriptions. Connect it per instance from that instance's Connections page.</p>
          <a href="/aibuilder" class="btn btn-sm btn-outline-primary">Open AI Builder</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-4">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-box-seam me-2"></i>Products &amp; Inventory</h5>
          <p class="card-text text-secondary small">Add products, set inventory, and configure serialized units with timed holds. Published as JSON alongside your storefront.</p>
          <span class="badge bg-secondary-subtle text-secondary-emphasis border">Coming soon</span>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-4">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-grid me-2"></i>Storefront</h5>
          <p class="card-text text-secondary small">Product listing (PLP) and detail (PDP) pages assembled from your product JSON, with relative image paths so they travel when you publish.</p>
          <span class="badge bg-secondary-subtle text-secondary-emphasis border">Coming soon</span>
        </div>
      </div>
    </div>
  </div>
</div>
