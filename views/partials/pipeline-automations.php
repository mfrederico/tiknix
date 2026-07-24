<?php
/**
 * Shared "automations" cards — pipelines + a per-pipeline "Endpoints" modal showing
 * its exposed MCP tool / REST API / durable-object paths (kept OUT of the card so long
 * URLs can't overflow), plus live durable objects. Used by BOTH the control-plane
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
$base   = rtrim((string)($baseUrl ?? ''), '/');
$canRun = !empty($canRun);
$runId  = (int)($runId ?? 0);
// A self-service test key minted here only works when the endpoint host IS this
// workspace's host (keys live in each app's own DB). Offer it only then.
$epHost  = $base !== '' ? (string) parse_url($base, PHP_URL_HOST) : '';
$curHost = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$canMintKey = $base === '' || ($epHost !== '' && strcasecmp($epHost, $curHost) === 0);
?>
<?php if (empty($pipelines)): ?>
  <div class="alert alert-light border py-2 small">No pipelines yet. Build one — a scheduled job, a REST endpoint, an MCP tool, or a stateful <em>durable object</em> — in the editor.</div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($pipelines as $p): ?>
      <?php
        $exposes  = !empty($p['expose_tool']) || !empty($p['expose_api']) || !empty($p['stateful']);
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
              <div class="fw-semibold text-truncate"><?= htmlspecialchars($p['name']) ?>
                <?php if ($p['stateful']): ?><span class="badge text-bg-secondary ms-1">object</span><?php endif; ?>
                <?php if ($p['expose_tool']): ?><span class="badge text-bg-info ms-1">tool</span><?php endif; ?>
                <?php if ($p['expose_api']): ?><span class="badge text-bg-info ms-1">api</span><?php endif; ?>
                <?php if ($p['cron'] !== ''): ?><span class="badge text-bg-light ms-1" title="<?= htmlspecialchars($p['cron']) ?>"><i class="bi bi-clock"></i></span><?php endif; ?>
                <?php if (!empty($p['github'])): $ghb = $p['github']; $ghBr = is_array($ghb['branches'] ?? null) ? implode(', ', $ghb['branches']) : 'any branch'; ?><span class="badge text-bg-dark ms-1" title="Fires on GitHub push (<?= htmlspecialchars($ghBr) ?>)"><i class="bi bi-github"></i> push</span><?php endif; ?>
              </div>
              <div class="text-body-secondary small text-truncate"><code><?= htmlspecialchars($p['slug']) ?></code> · <?= (int)$p['steps'] ?> step<?= (int)$p['steps'] === 1 ? '' : 's' ?></div>
              <?php if ($p['description'] !== ''): ?><div class="text-body-secondary small mt-1" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word"><?= htmlspecialchars($p['description']) ?></div><?php endif; ?>

              <div class="mt-2 d-flex flex-wrap gap-2">
                <?php if ($exposes): ?>
                  <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#pipeEpModal"
                    data-ep-name="<?= htmlspecialchars($p['name']) ?>"
                    data-ep-tool="<?= htmlspecialchars($toolName) ?>"
                    data-ep-mcp="<?= htmlspecialchars($mcpUrl) ?>"
                    data-ep-api="<?= htmlspecialchars($apiUrl) ?>"
                    data-ep-obj="<?= htmlspecialchars($objUrl) ?>"
                    data-ep-has-tool="<?= !empty($p['expose_tool']) ? 1 : 0 ?>"
                    data-ep-has-api="<?= !empty($p['expose_api']) ? 1 : 0 ?>"
                    data-ep-has-obj="<?= !empty($p['stateful']) ? 1 : 0 ?>">
                    <i class="bi bi-diagram-3 me-1"></i>Endpoints
                  </button>
                <?php endif; ?>
                <?php if ($canRun && !$p['stateful']): ?>
                  <button class="btn btn-sm btn-outline-success" data-run-pipe="<?= htmlspecialchars($p['slug']) ?>"><i class="bi bi-play-fill"></i> Run</button>
                <?php endif; ?>
              </div>
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

<!-- Endpoints modal (populated from the clicked card's data-ep-* attributes) -->
<div class="modal fade" id="pipeEpModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i><span id="ep-title">Endpoints</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="ep-body"></div>
    </div>
  </div>
</div>

<script>
(function(){
  var CSRF = '<?= function_exists("csrf_token") ? csrf_token() : "" ?>';
  var CAN_MINT = <?= $canMintKey ? 'true' : 'false' ?>;
  function escHtml(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

  // ---- copy helper (delegated so it works on modal-injected buttons) ----
  function wireCopy(root){
    root.addEventListener('click', function(e){
      var btn = e.target.closest('[data-copy]'); if (!btn) return;
      var txt = btn.getAttribute('data-copy') || '';
      var done = function(){ var i = btn.querySelector('i'); if (i){ var p = i.className; i.className = 'bi bi-check-lg'; setTimeout(function(){ i.className = p; }, 1200); } };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done).catch(function(){ window.prompt('Copy:', txt); }); }
      else { window.prompt('Copy:', txt); }
    });
  }

  function urlRow(badge, badgeClass, method, value, copyValue, hint){
    return '<div class="mb-3">'
      + '<div class="d-flex align-items-center gap-2 mb-1"><span class="badge ' + badgeClass + '">' + escHtml(badge) + '</span>'
      + '<button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-copy="' + escHtml(copyValue) + '"><i class="bi bi-clipboard"></i> Copy</button></div>'
      + '<div class="border rounded bg-body-tertiary p-2"><code class="d-block text-break">'
      + (method ? '<span class="text-body-secondary">' + escHtml(method) + '</span> ' : '') + escHtml(value) + '</code></div>'
      + '<div class="form-text">' + hint + '</div></div>';
  }

  var modalEl = document.getElementById('pipeEpModal');
  if (modalEl){
    var body = document.getElementById('ep-body');
    wireCopy(body);
    modalEl.addEventListener('show.bs.modal', function(ev){
      var b = ev.relatedTarget; if (!b) return;
      var d = function(k){ return b.getAttribute('data-ep-' + k) || ''; };
      document.getElementById('ep-title').textContent = d('name') + ' — endpoints';
      var html = '';
      if (d('has-obj') === '1'){
        html += urlRow('Durable object', 'text-bg-secondary', 'POST', d('obj') + '?key=<id>', d('obj') + '?key=',
          'Server-side (<code>Authorization: Bearer &lt;trigger_secret&gt;</code>). <code>?key=</code> addresses one object · <code>&amp;trigger=alarm</code> fires its alarm · body is the message JSON.');
      }
      if (d('has-tool') === '1'){
        html += urlRow('MCP tool', 'text-bg-info', '', d('tool'), d('tool'),
          'Auto-listed on <code>' + escHtml(d('mcp')) + '</code> via <code>tools/list</code>; invoked with <code>tools/call</code> once connected with the instance MCP key.');
      }
      if (d('has-api') === '1'){
        html += urlRow('REST API', 'text-bg-info', 'POST', d('api'), d('api'),
          '<code>Authorization: Bearer pk_…</code> · body is the context JSON · <code>?async=1</code> returns a <code>run_id</code> you poll at <code>GET /pipeline/status/&lt;run_id&gt;</code>.');
        html += '<div class="border-top pt-3">'
          + '<div class="fw-semibold small mb-1"><i class="bi bi-key me-1"></i>Test key</div>';
        if (CAN_MINT){
          html += '<button type="button" class="btn btn-sm btn-outline-success" id="ep-genkey"><i class="bi bi-key me-1"></i>Generate a test key</button>'
            + '<div id="ep-keyout" class="mt-2" style="display:none"><div class="input-group input-group-sm">'
            + '<input type="text" class="form-control font-monospace" id="ep-keyval" readonly>'
            + '<button type="button" class="btn btn-outline-secondary" id="ep-keycopy"><i class="bi bi-clipboard"></i></button></div>'
            + '<div class="form-text text-warning-emphasis">Shown once — copy it now. It authenticates as you on this workspace.</div></div>';
        } else {
          html += '<div class="form-text mb-0">Mint a <code>pk_</code> key from this instance\'s own <code>/integrations</code> page (keys live in each app\'s database).</div>';
        }
        html += '</div>';
      }
      body.innerHTML = html;

      var gen = document.getElementById('ep-genkey');
      if (gen){
        gen.addEventListener('click', function(){
          gen.disabled = true; gen.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Minting…';
          fetch('/pipeline/mykey', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},
            body: new URLSearchParams({csrf_token: CSRF, label: 'integrations test key'}).toString()
          }).then(function(r){ return r.json(); }).then(function(j){
            if (j && j.success && j.data && j.data.key){
              document.getElementById('ep-keyval').value = j.data.key;
              document.getElementById('ep-keyout').style.display = '';
              gen.style.display = 'none';
              var kc = document.getElementById('ep-keycopy');
              kc.addEventListener('click', function(){ var v = document.getElementById('ep-keyval'); v.select(); if (navigator.clipboard){ navigator.clipboard.writeText(v.value); } var i = kc.querySelector('i'); i.className='bi bi-check-lg'; setTimeout(function(){ i.className='bi bi-clipboard'; },1200); });
            } else { gen.disabled = false; gen.innerHTML = '<i class="bi bi-key me-1"></i>Generate a test key'; alert((j && j.message) || 'Could not mint a key'); }
          }).catch(function(){ gen.disabled = false; gen.innerHTML = '<i class="bi bi-key me-1"></i>Generate a test key'; alert('Could not mint a key'); });
        });
      }
    });
  }

<?php if ($canRun): ?>
  document.querySelectorAll('[data-run-pipe]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var slug = btn.getAttribute('data-run-pipe'); btn.disabled = true;
      fetch('/connections/pipelinerun', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},
        body: new URLSearchParams({csrf_token: CSRF, id: <?= $runId ?>, slug: slug}).toString()
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
