<?php
/**
 * "Connected services" — the read-only integrations catalog: which external services
 * this instance is wired to, as SERVICE + STATUS only (never the account/store name or
 * any identifier), so non-admin members can see what's available to build with without
 * learning which specific account is connected. Managing them lives on /connections.
 *
 * Vars:
 *   $services[]   — [{connector, connected(bool), revoked(bool)}], one per connector
 *   $brokerError  — string (optional): why the list couldn't be fetched
 */
$services    = $services ?? [];
$brokerError = (string) ($brokerError ?? '');
?>
<h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-2" style="letter-spacing:.06em">Connected services</h2>
<?php if ($brokerError !== ''): ?>
  <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($brokerError) ?></div>
<?php elseif (empty($services)): ?>
  <div class="alert alert-light border py-2 small">No external services connected yet. An admin can wire one up on the <a href="/connections" class="text-decoration-underline">Connections</a> page.</div>
<?php else: ?>
  <div class="d-flex flex-wrap gap-2 mb-2">
    <?php foreach ($services as $s):
      $live = !empty($s['connected']);
      $revoked = !empty($s['revoked']) && !$live;
    ?>
      <span class="badge rounded-pill border d-inline-flex align-items-center gap-1 px-3 py-2
        <?= $live ? 'bg-success-subtle text-success-emphasis border-success-subtle'
             : ($revoked ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
                         : 'bg-secondary-subtle text-secondary-emphasis') ?>">
        <i class="bi bi-<?= $live ? 'check-circle-fill' : ($revoked ? 'x-circle' : 'dash-circle') ?>"></i>
        <span class="text-capitalize fw-semibold"><?= htmlspecialchars($s['connector']) ?></span>
        <span class="opacity-75">· <?= $live ? 'connected' : ($revoked ? 'revoked' : 'disabled') ?></span>
      </span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
