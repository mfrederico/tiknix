<div class="container py-4">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h3 mb-0">Pipeline API keys</h1>
    <span class="text-muted ms-3">Per-member keys for <code>POST /pipeline/api/&lt;slug&gt;</code></span>
  </div>

  <?php if (!empty($minted)): ?>
    <div class="alert alert-success">
      <strong>New key minted</strong> — copy it now, it won't be shown again:
      <div class="input-group mt-2">
        <input type="text" class="form-control font-monospace" id="newKey" value="<?= htmlspecialchars($minted['raw']) ?>" readonly>
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('newKey').value)">Copy</button>
      </div>
    </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">Mint a key</h2>
      <form method="post" action="/pipeline/keys" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mint">
        <div class="col-md-4">
          <label class="form-label small">Member</label>
          <select name="member_id" class="form-select" required>
            <option value="">Select a member…</option>
            <?php foreach ($members as $m): ?>
              <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name']) ?> (#<?= (int)$m['id'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label small">Label</label>
          <input type="text" name="label" class="form-control" placeholder="e.g. Zapier integration" maxlength="100">
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary w-100">Mint key</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">Existing keys</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Prefix</th><th>Label</th><th>Member</th><th>Created</th><th>Last used</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($keys as $k): ?>
            <tr class="<?= $k['revoked'] ? 'text-muted' : '' ?>">
              <td><code><?= htmlspecialchars($k['prefix']) ?>…</code></td>
              <td><?= htmlspecialchars($k['label']) ?></td>
              <td>#<?= (int)$k['member_id'] ?></td>
              <td class="small"><?= htmlspecialchars($k['created_at']) ?></td>
              <td class="small"><?= htmlspecialchars($k['last_used_at'] ?: '—') ?></td>
              <td><?= $k['revoked'] ? '<span class="badge text-bg-secondary">revoked</span>' : '<span class="badge text-bg-success">active</span>' ?></td>
              <td class="text-end">
                <?php if (!$k['revoked']): ?>
                  <form method="post" action="/pipeline/keys" onsubmit="return confirm('Revoke this key?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Revoke</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$keys): ?><tr><td colspan="7" class="text-muted">No keys yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
