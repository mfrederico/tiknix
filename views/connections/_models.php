<?php
/**
 * Settings → Models: the member's own model connections (MODEL_CONNECTIONS_PLAN.md).
 *
 * Vars: $mc = ['connections' => [summary…], 'presets' => Model_Modelconnection::PRESETS,
 *              'chosen' => ?int, 'is_root' => bool]
 * Keys are never rendered — only set / unset / unreadable. Every form is its own POST with
 * CSRF; Test is JSON (it lists the endpoint's models so tier fields can be picked).
 */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tiers = ['planner' => 'Planning', 'worker' => 'Build (workers)', 'auditor' => 'Audit', 'resolver' => 'Conflict resolution', 'haiku' => 'Fast (sub-tasks)'];
$presets = $mc['presets'];
if (!$mc['is_root']) unset($presets['local']);   // localhost/LAN endpoints are root-only
$chosen = $mc['chosen'];
?>
<h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-4" style="letter-spacing:.06em" id="models">Models</h2>
<div class="card shadow-sm mb-2">
  <div class="card-body">
    <h5 class="mb-1"><i class="bi bi-cpu me-1"></i>Bring your own model</h5>
    <p class="text-muted small mb-3">
      Connect your own model endpoint and key — Anthropic, Ollama, OpenRouter, z.ai, or anything that speaks the
      Anthropic Messages API. The builds <em>you</em> start run on the one you choose below, in every project you can
      reach; teammates' runs use their own. These are yours, not this project's — the same list shows
      whichever project is selected. Keys are encrypted and never shown again.
    </p>

    <?php if (!empty($mc['problem'])): ?>
      <div class="alert alert-danger small py-2"><?= $h($mc['problem']) ?></div>
    <?php endif; ?>

    <form method="POST" action="/connections/modelchoose" class="d-flex align-items-center gap-2 flex-wrap mb-3">
      <?= csrf_field() ?>
      <label class="fw-semibold small mb-0" for="mc-choose">Build with</label>
      <select class="form-select form-select-sm w-auto" id="mc-choose" name="id">
        <option value="0" <?= $chosen === null ? 'selected' : '' ?>>The platform's Claude</option>
        <?php foreach ($mc['connections'] as $c): if ($c['protocol'] !== 'anthropic') continue; ?>
          <option value="<?= (int) $c['id'] ?>" <?= $chosen === $c['id'] ? 'selected' : '' ?>><?= $h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary" type="submit">Use</button>
      <?php if ($chosen !== null): ?><span class="badge bg-success">your builds use your own model</span><?php endif; ?>
    </form>

    <?php foreach ($mc['connections'] as $c): ?>
      <div class="border rounded p-2 mb-2" data-mc="<?= (int) $c['id'] ?>">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <strong><?= $h($c['name']) ?></strong>
          <code class="small"><?= $h($c['base_url']) ?></code>
          <?php if ($c['protocol'] === 'openai'): ?><span class="badge bg-secondary">chat only</span><?php endif; ?>
          <?php if (!empty($c['allow_pipelines'])): ?><span class="badge bg-info text-dark">pipelines may use</span><?php endif; ?>
          <?php if ($c['key_status'] === 'set'): ?><span class="badge bg-light text-dark border">key set</span>
          <?php elseif ($c['key_status'] === 'unreadable'): ?><span class="badge bg-danger">key unreadable — re-enter it</span>
          <?php elseif ($c['auth'] !== 'none'): ?><span class="badge bg-warning text-dark">no key</span><?php endif; ?>
          <?php if ($c['last_test_at'] !== ''): ?>
            <span class="badge <?= $c['last_test_ok'] ? 'bg-success' : 'bg-danger' ?>" title="<?= $h($c['last_test_msg']) ?>">
              <?= $c['last_test_ok'] ? 'test passed' : 'test failed' ?> <?= $h(substr($c['last_test_at'], 0, 16)) ?></span>
          <?php endif; ?>
          <span class="ms-auto d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-primary mc-test" data-id="<?= (int) $c['id'] ?>">Test</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#mc-edit-<?= (int) $c['id'] ?>">Edit</button>
            <form method="POST" action="/connections/modeldelete" class="d-inline" onsubmit="return confirm('Delete <?= $h($c['name']) ?>? Its key is deleted with it.')">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
            </form>
          </span>
        </div>
        <div class="small text-muted mt-1">
          <?php foreach ($tiers as $t => $label): if ($c[$t . '_model'] === '') continue; ?>
            <?= $h($label) ?>: <code><?= $h($c[$t . '_model']) ?></code>&nbsp;
          <?php endforeach; ?>
        </div>
        <div class="small mt-1 mc-test-out" id="mc-test-<?= (int) $c['id'] ?>"></div>
        <div class="collapse mt-2" id="mc-edit-<?= (int) $c['id'] ?>">
          <?php $form = $c; $formId = 'e' . (int) $c['id']; include __DIR__ . '/_model_form.php'; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <button class="btn btn-sm btn-outline-primary mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#mc-new">
      <i class="bi bi-plus-lg"></i> Add a model connection
    </button>
    <div class="collapse mt-2" id="mc-new">
      <?php $form = ['id' => 0, 'name' => '', 'preset' => 'ollama', 'protocol' => 'anthropic', 'base_url' => '', 'auth' => 'bearer', 'key_status' => 'unset'];
            foreach (array_keys($tiers) as $t) $form[$t . '_model'] = '';
            $formId = 'new'; include __DIR__ . '/_model_form.php'; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var presets = <?= json_encode($presets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var csrf = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  // A preset fills the form; every field stays editable.
  document.querySelectorAll('.mc-preset').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var p = presets[sel.value]; if (!p) return;
      var f = sel.closest('form');
      f.querySelector('[name=protocol]').value = p.protocol;
      f.querySelector('[name=base_url]').value = p.base_url;
      f.querySelector('[name=auth]').value = p.auth;
      Object.keys(p.models).forEach(function (t) { var i = f.querySelector('[name=' + t + '_model]'); if (i) i.value = p.models[t]; });
      var hint = f.querySelector('.mc-hint'); if (hint) hint.textContent = p.hint;
      var nm = f.querySelector('[name=name]'); if (nm && !nm.value) nm.value = p.label;
    });
  });
  // Test: list the endpoint's models (offered in every model field) and make one tiny call.
  document.querySelectorAll('.mc-test').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.dataset.id, out = document.getElementById('mc-test-' + id);
      out.className = 'small mt-1 text-muted'; out.textContent = 'Testing…'; btn.disabled = true;
      var body = new URLSearchParams({id: id, _csrf_token: csrf});
      fetch('/connections/modeltest', {method: 'POST', body: body, credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}})
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var r = d.data || d;
          out.className = 'small mt-1 ' + (r.ok ? 'text-success' : 'text-danger');
          out.textContent = (r.ok ? '✓ ' : '✗ ') + (r.message || d.message || 'no answer');
          var list = document.getElementById('mc-models-e' + id);
          if (list && r.models) { list.innerHTML = ''; r.models.forEach(function (m) { var o = document.createElement('option'); o.value = m; list.appendChild(o); }); }
        })
        .catch(function (e) { out.className = 'small mt-1 text-danger'; out.textContent = '✗ ' + e; })
        .finally(function () { btn.disabled = false; });
    });
  });
})();
</script>
