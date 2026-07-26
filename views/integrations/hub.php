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

  <?php
  /* NO INSTANCE SWITCHER — this page shows the automations of the project selected in
     /projects. A second switcher here is how Run could fire a pipeline in an instance
     you were not working on, so name the project and point at the one place to change it. */
  ?>
  <div class="d-flex align-items-center gap-2 mb-4 small">
    <span class="text-body-secondary">Automations for</span>
    <span class="badge bg-primary-subtle text-primary-emphasis">
      <?= htmlspecialchars($instance->display_name ?: $instance->slug) ?>
    </span>
    <a href="/projects" class="text-decoration-none">Change project</a>
  </div>

  <?php include __DIR__ . '/../partials/connected-services.php'; ?>

  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-4" style="letter-spacing:.06em">Pipelines &amp; automations</h2>
  <?php
    $canRun = true;
    $runId  = $iid;
    include __DIR__ . '/../partials/pipeline-automations.php';
  ?>
</div>
