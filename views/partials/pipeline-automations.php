<?php
/**
 * Shared "automations" cards — pipelines (with their exposed MCP tool / REST API /
 * durable-object paths) + live durable objects. Used by BOTH the control-plane
 * Integrations hub (views/integrations/hub.php) and the instance-side read-only
 * Integrations page (views/integrations/index.php), so the two never drift.
 *
 * Vars:
 *   $pipelines[]      — from InstanceAutomations::pipelines()
 *   $durableObjects[] — from InstanceAutomations::durableObjects() (optional)
 *   $baseUrl          — the target instance's public base URL (may be '')
 *   $canRun           — bool: show Run buttons (control plane only)
 *   $runId            — instance id to POST runs against (control plane only)
 */
$base = rtrim((string)($baseUrl ?? ''), '/');
$canRun = !empty($canRun);
$runId = (int)($runId ?? 0);
?>
<?php if (empty($pipelines)): ?>
  <div class="alert alert-light border py-2 small">No pipelines yet. Build one — a scheduled job, a REST endpoint, an MCP tool, or a stateful <em>durable object</em> — in the editor.</div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($pipelines as $p): ?>
      <?php
        $toolName = 'tiknix:pipe_' . $p['slug'];
        $mcpUrl   = $base . '/mcp/message';
        $apiUrl   = $base . '/pipeline/api/' . $p['slug'];
        $objUrl   = $base . '/pipeline/object/' . $p['slug'];
      ?>
      <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
          <div class="d-flex align-items-start gap-3">
            <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
              <i class="bi bi-<?= $p['stateful'] ? 'box' : 'diagram-2' ?> fs-5 text-primary"></i>
            </div>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?>
                <?php if ($p['stateful']): ?><span class="badge text-bg-secondary ms-1">object</span><?php endif; ?>
                <?php if ($p['expose_tool']): ?><span class="badge text-bg-info ms-1">tool</span><?php endif; ?>
                <?php if ($p['expose_api']): ?><span class="badge text-bg-info ms-1">api</span><?php endif; ?>
                <?php if ($p['cron'] !== ''): ?><span class="badge text-bg-light ms-1" title="<?= htmlspecialchars($p['cron']) ?>"><i class="bi bi-clock"></i></span><?php endif; ?>
                <?php if (!empty($p['github'])): $ghb = $p['github']; $ghBr = is_array($ghb['branches'] ?? null) ? implode(', ', $ghb['branches']) : 'any branch'; ?><span class="badge text-bg-dark ms-1" title="Fires on GitHub push (<?= htmlspecialchars($ghBr) ?>)"><i class="bi bi-github"></i> push</span><?php endif; ?>
              </div>
              <div class="text-body-secondary small"><code><?= htmlspecialchars($p['slug']) ?></code> · <?= (int)$p['steps'] ?> step<?= (int)$p['steps'] === 1 ? '' : 's' ?><?php if ($p['description'] !== ''): ?> · <?= htmlspecialchars($p['description']) ?><?php endif; ?></div>

              <?php if (!empty($p['expose_tool']) || !empty($p['expose_api']) || !empty($p['stateful'])): ?>
                <div class="mt-2 border-top pt-2 d-flex flex-column gap-2">
                  <?php if (!empty($p['stateful'])): ?>
                    <div>
                      <div class="d-flex align-items-center gap-1">
                        <span class="badge text-bg-secondary flex-shrink-0"><i class="bi bi-box"></i> object</span>
                        <code class="small text-truncate flex-grow-1" title="POST <?= htmlspecialchars($objUrl) ?>?key=&lt;id&gt;"><span class="text-body-secondary">POST</span> <?= htmlspecialchars($objUrl) ?>?key=&lt;id&gt;</code>
                        <button class="btn btn-sm btn-link p-0 text-decoration-none flex-shrink-0" type="button" data-copy="<?= htmlspecialchars($objUrl) ?>?key=" title="Copy delivery URL"><i class="bi bi-clipboard"></i></button>
                      </div>
                      <div class="form-text mt-0">Server-side (<code>Authorization: Bearer &lt;trigger_secret&gt;</code>) · <code>?key=</code> addresses one object · <code>&amp;trigger=alarm</code> to fire its alarm · body is the message JSON.</div>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($p['expose_tool'])): ?>
                    <div>
                      <div class="d-flex align-items-center gap-1">
                        <span class="badge text-bg-info flex-shrink-0">MCP tool</span>
                        <code class="small text-truncate flex-grow-1" title="<?= htmlspecialchars($toolName) ?>"><?= htmlspecialchars($toolName) ?></code>
                        <button class="btn btn-sm btn-link p-0 text-decoration-none flex-shrink-0" type="button" data-copy="<?= htmlspecialchars($toolName) ?>" title="Copy tool name"><i class="bi bi-clipboard"></i></button>
                      </div>
                      <div class="form-text mt-0">Auto-listed on <code><?= htmlspecialchars($mcpUrl) ?></code> via <code>tools/list</code>; called with <code>tools/call</code>.</div>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($p['expose_api'])): ?>
                    <div>
                      <div class="d-flex align-items-center gap-1">
                        <span class="badge text-bg-info flex-shrink-0">REST</span>
                        <code class="small text-truncate flex-grow-1" title="POST <?= htmlspecialchars($apiUrl) ?>"><span class="text-body-secondary">POST</span> <?= htmlspecialchars($apiUrl) ?></code>
                        <button class="btn btn-sm btn-link p-0 text-decoration-none flex-shrink-0" type="button" data-copy="<?= htmlspecialchars($apiUrl) ?>" title="Copy endpoint URL"><i class="bi bi-clipboard"></i></button>
                      </div>
                      <div class="form-text mt-0"><code>Authorization: Bearer pk_…</code> · body is the context JSON · <code>?async=1</code> for a poll-able run.</div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ($canRun && !$p['stateful']): ?>
                <div class="mt-2 d-flex gap-2">
                  <button class="btn btn-sm btn-outline-success" data-run-pipe="<?= htmlspecialchars($p['slug']) ?>"><i class="bi bi-play-fill"></i> Run</button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

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

<script>
(function(){
  document.querySelectorAll('[data-copy]').forEach(function(btn){
    if (btn.__copyWired) return; btn.__copyWired = true;
    btn.addEventListener('click', function(){
      var txt = btn.getAttribute('data-copy') || '';
      var done = function(){ var i = btn.querySelector('i'); if (i){ i.className = 'bi bi-check-lg'; setTimeout(function(){ i.className = 'bi bi-clipboard'; }, 1200); } };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done).catch(function(){ window.prompt('Copy:', txt); }); }
      else { window.prompt('Copy:', txt); }
    });
  });
<?php if ($canRun): ?>
  var runCsrf = '<?= function_exists("csrf_token") ? csrf_token() : "" ?>';
  var runIid = <?= $runId ?>;
  document.querySelectorAll('[data-run-pipe]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var slug = btn.getAttribute('data-run-pipe'); btn.disabled = true;
      fetch('/connections/pipelinerun', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':runCsrf,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: runCsrf, id: runIid, slug: slug}).toString()
      }).then(function(r){ return r.json(); }).then(function(j){
        btn.disabled = false;
        if (j && j.success) { btn.className = 'btn btn-sm btn-success'; btn.innerHTML = '<i class="bi bi-check-lg"></i> Queued #' + (j.data && j.data.run_id ? j.data.run_id : ''); }
        else { alert((j && j.message) || 'Run failed'); }
      }).catch(function(){ btn.disabled = false; alert('Run failed'); });
    });
  });
<?php endif; ?>
})();
</script>
