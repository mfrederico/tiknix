<?php
/**
 * Instance-side Connections (read-only). What THIS instance is connected TO —
 * external accounts (GitHub, Stripe, …), metadata fetched from the control plane
 * via the broker; the credential never leaves core. What the instance EXPOSES
 * (pipelines, tools, APIs) lives on the sibling /integrations page.
 *
 * Vars: $connections[], $brokerError, $appName
 */
$badge = ['production' => 'success', 'staging' => 'warning', 'development' => 'secondary'];
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-plug fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Connections</h1>
      <div class="text-body-secondary small">what <code><?= htmlspecialchars($appName) ?></code> is connected to</div>
    </div>
  </div>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-shield-check me-1"></i>
    Read-only. Credentials never leave the control plane — manage them in your main
    <a href="https://tiknix.com/auth/login/" style="text-decoration:underline">tiknix workspace</a>.
    What this instance <em>exposes</em> is on the <a href="/integrations" style="text-decoration:underline">Integrations</a> page.
  </div>

  <?php if ($brokerError !== ''): ?>
    <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($brokerError) ?></div>
  <?php elseif (empty($connections)): ?>
    <div class="alert alert-light border py-2 small">No connections yet. Connect a store or payment account to this instance from the control plane.</div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($connections as $c): $live = !empty($c['enabled']) && empty($c['revoked']); ?>
        <div class="col-md-6">
          <div class="card h-100 <?= $live ? 'border-success border-opacity-50' : 'opacity-75' ?>">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between">
                <div class="fw-semibold text-capitalize"><?= htmlspecialchars($c['connector']) ?></div>
                <span class="badge text-bg-<?= htmlspecialchars($badge[$c['environment']] ?? 'secondary') ?>"><?= htmlspecialchars($c['environment']) ?></span>
              </div>
              <div class="text-body-secondary small mt-1"><?= htmlspecialchars($c['name'] ?: '—') ?></div>
              <div class="mt-2">
                <?php if (!empty($c['revoked'])): ?><span class="badge text-bg-danger">revoked</span>
                <?php elseif ($live): ?><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> connected</span>
                <?php else: ?><span class="badge text-bg-secondary">disabled</span><?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
