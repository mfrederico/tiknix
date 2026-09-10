<?php
/**
 * Billing — what this account holds and what it would cost.
 *
 * Phase 2 of BILLING_PLAN.md. Nothing on this page charges or blocks anything; it exists
 * so the counting rule can be checked against real accounts before it can hurt anyone.
 * The notice saying so is not decoration — a page showing "$499/month" with no explanation
 * is indistinguishable from a bill, and this account has not agreed to anything.
 *
 * Vars: $error (string); when $error is '' also $snapshot, $freeCap, $proCap, $proPrice,
 *       $projects, $portalUrl, $tenantSlug.
 */
?>
<div class="container py-4" style="max-width: 62rem;">

  <div class="d-flex align-items-baseline justify-content-between flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Billing</h1>
    <span class="badge text-bg-secondary">Preview — nothing is being charged</span>
  </div>

<?php if ($error !== ''): ?>

  <div class="alert alert-danger" role="alert">
    <?= htmlspecialchars($error) ?>
  </div>

<?php else:
    $count     = (int) $snapshot['count'];
    $cap       = (int) $snapshot['cap'];
    $tier      = (string) $snapshot['tier'];
    $needsPaid = (bool) $snapshot['needs_paid'];
    $over      = (bool) $snapshot['over'];
    $owned     = array_values(array_filter($projects, fn($p) => ($p['via'] ?? '') === 'owned'));
    $shared    = array_values(array_filter($projects, fn($p) => ($p['via'] ?? '') === 'shared'));
    $grandfathered = ($tier === 'legacy');
?>

  <div class="alert alert-info d-flex gap-2" role="alert">
    <i class="bi bi-info-circle mt-1"></i>
    <div>
      <strong>This is a preview.</strong> Project limits are not being enforced and no card
      is on file. We are showing you the numbers first so anything that looks wrong can be
      fixed before it counts.
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase" style="letter-spacing:.06em;">Projects</div>
          <div class="display-6 mb-0"><?= $count ?><span class="fs-5 text-muted"> / <?= $cap ?></span></div>
          <div class="small text-muted">counted against this account</div>
        </div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase" style="letter-spacing:.06em;">Plan</div>
          <div class="fs-4 mb-0 text-capitalize"><?= htmlspecialchars($tier) ?></div>
          <div class="small text-muted">
            <?= $grandfathered
                  ? 'early account, kept at no charge'
                  : ($tier === 'pro' ? 'up to ' . (int) $proCap . ' projects' : (int) $freeCap . ' project included') ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase" style="letter-spacing:.06em;">Monthly</div>
<?php if ($grandfathered): ?>
          <div class="fs-4 mb-0">$0.00</div>
          <div class="small text-muted">
            $<?= number_format($proPrice, 2) ?> &minus; $<?= number_format($proPrice, 2) ?> discount
          </div>
<?php else: ?>
          <div class="fs-4 mb-0">$<?= number_format($needsPaid ? $proPrice : 0, 2) ?></div>
          <div class="small text-muted"><?= $needsPaid ? 'if this were live today' : 'free tier' ?></div>
<?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<?php if ($grandfathered): ?>
  <div class="alert alert-success d-flex gap-2" role="alert">
    <i class="bi bi-award mt-1"></i>
    <div>
      <strong>You were here early.</strong> Your <?= $count ?> project<?= $count === 1 ? '' : 's' ?>
      stay at no charge. You will see the full $<?= number_format($proPrice, 2) ?> and a matching
      discount on your account, so it is always clear what is being covered.
    </div>
  </div>
<?php elseif ($over): ?>
  <div class="alert alert-warning d-flex gap-2" role="alert">
    <i class="bi bi-exclamation-triangle mt-1"></i>
    <div>
      This account holds <?= $count ?> projects and the <?= htmlspecialchars($tier) ?> plan
      covers <?= $cap ?>. <strong>Nothing has changed and nothing is being charged.</strong>
      If this number looks wrong, tell us before it starts counting — the breakdown below
      shows exactly where each one came from.
    </div>
  </div>
<?php endif; ?>

<?php if ($projects): ?>
  <h2 class="h5 mt-4 mb-2">What is being counted</h2>
  <p class="text-muted small mb-3">
    Projects shared into a team you own count once, against you — the people you invite do
    not each need their own plan.
  </p>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr class="text-muted small text-uppercase">
          <th scope="col">Project</th>
          <th scope="col">Counted because</th>
        </tr>
      </thead>
      <tbody>
<?php foreach (array_merge($owned, $shared) as $p): ?>
        <tr>
          <td>
            <?= htmlspecialchars((string) ($p['display_name'] ?: $p['slug'])) ?>
            <div class="small text-muted font-monospace"><?= htmlspecialchars((string) $p['slug']) ?></div>
          </td>
          <td>
<?php if (($p['via'] ?? '') === 'owned'): ?>
            <span class="badge text-bg-primary">You own it</span>
<?php else: ?>
            <span class="badge text-bg-secondary">Shared</span>
            <?php if (!empty($p['team_name'])): ?>
              <span class="small text-muted">via <?= htmlspecialchars((string) $p['team_name']) ?></span>
            <?php endif; ?>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

  <h2 class="h5 mt-4 mb-2">Payment</h2>
<?php if ($portalUrl !== ''): ?>
  <p class="text-muted small">Invoices and payment details are handled by ClickSimple, which
    is the company behind Tiknix — that is the name you would see on a card statement.</p>
  <a class="btn btn-outline-primary" href="<?= htmlspecialchars($portalUrl) ?>" target="_blank" rel="noopener">
    Open the billing portal <i class="bi bi-box-arrow-up-right ms-1"></i>
  </a>
<?php else: ?>
  <p class="text-muted">
    There is no payment method on this account and nothing to pay. When plans go live you
    will be asked before anything is charged.
  </p>
<?php endif; ?>

<?php endif; ?>
</div>
