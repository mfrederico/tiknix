<?php
/**
 * Integrations hub (control plane) — the automations an instance exposes: pipelines
 * and their MCP tool / REST API / durable-object endpoints, plus live durable objects.
 * Credentials live on the sibling /connections page; this page is what you BUILD.
 *
 * Vars: $instance (selected bean), $instances[], $pipelines[], $durableObjects[],
 *       $baseUrl (selected instance's public base URL), $iid
 */
$iid = (int)$instance->id;
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-diagram-3 fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Integrations</h1>
      <div class="text-body-secondary small">what <code><?= htmlspecialchars(($instance->slug) ?? '') ?>.tiknix</code> exposes — pipelines, tools &amp; APIs</div>
    </div>
    <a href="/sidecar/launch/pipelines" class="btn btn-sm btn-outline-primary ms-auto" target="_blank" rel="noopener"><i class="bi bi-pencil-square me-1"></i>Open editor</a>
  </div>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-info-circle me-1"></i>
    Pipelines exposed as an <strong>MCP tool</strong>, a <strong>REST API</strong>, or a <strong>durable object</strong> show their call path here.
    Connect external accounts (GitHub, Stripe, …) on the <a href="/connections?id=<?= $iid ?>" class="text-decoration-underline">Connections</a> page.
  </div>

  <?php if (!empty($instances) && count($instances) > 1): ?>
    <div class="mb-4">
      <div class="text-uppercase text-body-secondary small fw-semibold mb-2" style="letter-spacing:.06em">Instance</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($instances as $i): $active = (int)$i->id === $iid; ?>
          <a href="/integrations?id=<?= (int)$i->id ?>" class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <i class="bi bi-shop me-1"></i><?= htmlspecialchars($i->display_name ?: $i->slug) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php
    $canRun = true;
    $runId  = $iid;
    include __DIR__ . '/../partials/pipeline-automations.php';
  ?>
</div>
