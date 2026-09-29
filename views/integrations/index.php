<?php
/**
 * Instance-side Integrations (read-only). What THIS instance EXPOSES: its pipelines
 * and their MCP tool / REST API / durable-object endpoints, plus live durable objects.
 * Credentials it connects TO live on the sibling /connections page.
 *
 * Vars: $pipelines[], $durableObjects[], $appName, $baseUrl
 */
?>
<div class="container py-4" style="max-width:960px">

  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-diagram-3 fs-3"></i>
    <div>
      <h1 class="h4 fw-bold mb-0">Integrations</h1>
      <div class="text-body-secondary small">what <code><?= htmlspecialchars($appName) ?></code> exposes — pipelines, tools &amp; APIs</div>
    </div>
  </div>

  <div class="alert alert-light border py-2 small mb-4">
    <i class="bi bi-info-circle me-1"></i>
    These are the automations this instance offers. External accounts it connects to are on the
    <a href="/connections" style="text-decoration:underline">Connections</a> page; manage credentials in your main
    <a href="https://tiknix.com/auth/login/" style="text-decoration:underline">tiknix workspace</a>.
  </div>

  <?php include __DIR__ . '/../partials/connected-services.php'; ?>

  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-4" style="letter-spacing:.06em">Pipelines &amp; automations</h2>
  <?php
    $canRun = false;
    include __DIR__ . '/../partials/pipeline-automations.php';
  ?>
</div>
