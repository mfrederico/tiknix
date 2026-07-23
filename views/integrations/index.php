<?php
/**
 * Instance-side Integrations (read-only). Shows what THIS instance is wired to:
 * connections fetched from the control plane via the broker (metadata only), plus
 * the pipelines and durable objects that live here locally.
 *
 * Vars: $connections[], $brokerError, $pipelines[], $durableObjects[], $appName
 */
$badge = ['production' => 'success', 'staging' => 'warning', 'development' => 'secondary'];
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-plug fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Integrations</h1>
      <div class="text-body-secondary small">what <code><?= htmlspecialchars($appName) ?></code> is wired to</div>
    </div>
  </div>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-shield-check me-1"></i>
    Credentials and connections can be managed in your main
    <a href="https://tiknix.com/auth/login/" style="text-decoration:underline">tiknix workspace</a>.
  </div>

  <!-- ============ Connections (via the broker) ============ -->
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2" style="letter-spacing:.06em">Connections</h2>
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

  <!-- ============ Pipelines (local) ============ -->
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-5" style="letter-spacing:.06em">Pipelines &amp; automations</h2>
  <?php if (empty($pipelines)): ?>
    <div class="alert alert-light border py-2 small">No pipelines in this instance yet.</div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($pipelines as $p): ?>
        <div class="col-md-6">
          <div class="card h-100"><div class="card-body">
            <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?>
              <?php if ($p['stateful']): ?><span class="badge text-bg-secondary ms-1">object</span><?php endif; ?>
              <?php if ($p['expose_tool']): ?><span class="badge text-bg-info ms-1">tool</span><?php endif; ?>
              <?php if ($p['expose_api']): ?><span class="badge text-bg-info ms-1">api</span><?php endif; ?>
              <?php if ($p['cron'] !== ''): ?><span class="badge text-bg-light ms-1" title="<?= htmlspecialchars($p['cron']) ?>"><i class="bi bi-clock"></i></span><?php endif; ?>
            </div>
            <div class="text-body-secondary small"><code><?= htmlspecialchars($p['slug']) ?></code> · <?= (int)$p['steps'] ?> step<?= (int)$p['steps'] === 1 ? '' : 's' ?></div>
            <?php if ($p['description'] !== ''): ?><div class="text-body-secondary small mt-1"><?= htmlspecialchars($p['description']) ?></div><?php endif; ?>
          </div></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ============ Durable objects (local) ============ -->
  <?php if (!empty($durableObjects)): ?>
    <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-5" style="letter-spacing:.06em">Durable objects <span class="badge text-bg-secondary ms-1"><?= count($durableObjects) ?></span></h2>
    <div class="row g-3">
      <?php foreach ($durableObjects as $o): ?>
        <div class="col-md-6">
          <div class="card h-100"><div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div class="fw-semibold text-truncate"><code><?= htmlspecialchars($o['type']) ?></code> : <code><?= htmlspecialchars($o['key']) ?></code></div>
              <?php if ($o['wake_at'] > 0): ?><span class="badge text-bg-light flex-shrink-0" title="next alarm"><i class="bi bi-alarm"></i> <?= htmlspecialchars(date('H:i', $o['wake_at'])) ?></span><?php endif; ?>
            </div>
            <pre class="small bg-body-tertiary rounded p-2 mt-2 mb-1" style="max-height:8rem;overflow:auto"><?= htmlspecialchars(json_encode($o['state'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <div class="text-body-secondary small">updated <?= htmlspecialchars(date('Y-m-d H:i', $o['updated_at'])) ?></div>
          </div></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
