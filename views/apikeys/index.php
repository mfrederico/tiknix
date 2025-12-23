<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">API Keys</h1>
        <a href="/apikeys/add" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Create New Key
        </a>
    </div>

    <?php if (!empty($_SESSION['new_api_key'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <h5 class="alert-heading"><i class="bi bi-check-circle"></i> API Key Created!</h5>
            <p class="mb-2">Your new API key <strong><?= htmlspecialchars($_SESSION['new_api_key_name']) ?></strong> has been created. Copy it now - it won't be shown again!</p>
            <div class="input-group mb-2" style="max-width: 600px;">
                <input type="text" class="form-control font-monospace bg-light" id="newToken"
                       value="<?= htmlspecialchars($_SESSION['new_api_key']) ?>" readonly>
                <button class="btn btn-outline-secondary" type="button" onclick="copyToken('newToken')">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['new_api_key'], $_SESSION['new_api_key_name']); ?>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Your API Keys</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($keys)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-key" style="font-size: 3rem;"></i>
                    <p class="mt-3">You don't have any API keys yet.</p>
                    <a href="/apikeys/add" class="btn btn-primary">Create Your First Key</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Token</th>
                                <th>Scopes</th>
                                <th>Servers</th>
                                <th>Expires</th>
                                <th>Last Used</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keys as $key): ?>
                                <?php
                                $scopes = json_decode($key->scopes, true) ?: [];
                                $allowedServers = json_decode($key->allowedServers, true) ?: [];
                                $isExpired = $key->expiresAt && strtotime($key->expiresAt) < time();
                                ?>
                                <tr class="<?= (!$key->isActive || $isExpired) ? 'table-secondary' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($key->name) ?></strong>
                                        <br><small class="text-muted">Created <?= date('M j, Y', strtotime($key->createdAt)) ?></small>
                                    </td>
                                    <td>
                                        <code class="text-muted"><?= substr($key->token, 0, 12) ?>...</code>
                                        <button class="btn btn-sm btn-link p-0 ms-1" onclick="regenerateToken(<?= $key->id ?>, '<?= htmlspecialchars($key->name, ENT_QUOTES) ?>')" title="Regenerate token">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <?php if (empty($scopes)): ?>
                                            <span class="badge bg-warning">No scopes</span>
                                        <?php elseif (in_array('mcp:*', $scopes)): ?>
                                            <span class="badge bg-success">Full Access</span>
                                        <?php else: ?>
                                            <?php foreach ($scopes as $scope): ?>
                                                <span class="badge bg-info"><?= htmlspecialchars($scope) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (empty($allowedServers)): ?>
                                            <span class="text-muted">All servers</span>
                                        <?php else: ?>
                                            <?php foreach ($allowedServers as $slug): ?>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($slug) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$key->expiresAt): ?>
                                            <span class="text-muted">Never</span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Expired</span>
                                        <?php else: ?>
                                            <?= date('M j, Y', strtotime($key->expiresAt)) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($key->lastUsedAt): ?>
                                            <span title="<?= htmlspecialchars($key->lastUsedAt) ?>">
                                                <?= date('M j, Y', strtotime($key->lastUsedAt)) ?>
                                            </span>
                                            <br><small class="text-muted"><?= $key->usageCount ?? 0 ?> uses</small>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isExpired): ?>
                                            <span class="badge bg-danger">Expired</span>
                                        <?php elseif ($key->isActive): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="/apikeys/edit?id=<?= $key->id ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <a href="/apikeys/delete?id=<?= $key->id ?>"
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Delete this API key? This cannot be undone.')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Usage Instructions -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-terminal"></i> Using API Keys with Claude Code</h5>
        </div>
        <div class="card-body">
            <p>Add your API key to Claude Code settings to connect to MCP servers.</p>

            <div class="row">
                <div class="col-md-6">
                    <h6>Global Settings</h6>
                    <p class="small text-muted">Edit <code>~/.claude/settings.json</code>:</p>
                    <pre class="bg-dark text-light p-3 rounded small"><code>{
  "mcpServers": {
    "tiknix": {
      "type": "http",
      "url": "<?= htmlspecialchars(Flight::get('baseurl') ?? 'https://your-domain.com') ?>/mcp/message",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}</code></pre>
                </div>
                <div class="col-md-6">
                    <h6>Project Settings</h6>
                    <p class="small text-muted">Create <code>.mcp.json</code> in your project:</p>
                    <pre class="bg-dark text-light p-3 rounded small"><code>{
  "mcpServers": {
    "tiknix": {
      "type": "http",
      "url": "<?= htmlspecialchars(Flight::get('baseurl') ?? 'https://your-domain.com') ?>/mcp/message",
      "headers": {
        "X-MCP-Token": "YOUR_API_KEY"
      }
    }
  }
}</code></pre>
                </div>
            </div>

            <div class="alert alert-info mt-3 mb-0">
                <i class="bi bi-lightbulb"></i> <strong>Tip:</strong> Create separate API keys for different projects or purposes.
                You can restrict keys to specific MCP servers for better security.
            </div>
        </div>
    </div>
</div>

<!-- Regenerate Token Modal -->
<div class="modal fade" id="regenerateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Regenerate API Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="regenerateConfirm">
                    <p>Are you sure you want to regenerate the token for <strong id="regenerateKeyName"></strong>?</p>
                    <p class="text-warning"><i class="bi bi-exclamation-triangle"></i> The old token will stop working immediately.</p>
                </div>
                <div id="regenerateResult" style="display: none;">
                    <p class="text-success"><i class="bi bi-check-circle"></i> Token regenerated! Copy it now:</p>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" id="regeneratedToken" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToken('regeneratedToken')">
                            <i class="bi bi-clipboard"></i> Copy
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="regenerateBtn" onclick="confirmRegenerate()">
                    Regenerate Token
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentRegenerateId = null;
const regenerateModal = new bootstrap.Modal(document.getElementById('regenerateModal'));

function copyToken(inputId) {
    const input = document.getElementById(inputId);
    input.select();
    document.execCommand('copy');

    // Show feedback
    const btn = input.nextElementSibling;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-success');

    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-secondary');
    }, 2000);
}

function regenerateToken(keyId, keyName) {
    currentRegenerateId = keyId;
    document.getElementById('regenerateKeyName').textContent = keyName;
    document.getElementById('regenerateConfirm').style.display = 'block';
    document.getElementById('regenerateResult').style.display = 'none';
    document.getElementById('regenerateBtn').style.display = 'block';
    regenerateModal.show();
}

async function confirmRegenerate() {
    if (!currentRegenerateId) return;

    const btn = document.getElementById('regenerateBtn');
    btn.disabled = true;
    btn.textContent = 'Regenerating...';

    try {
        const formData = new FormData();
        formData.append('id', currentRegenerateId);

        // Get CSRF token from the page
        const csrfInputs = document.querySelectorAll('input[name^="csrf"]');
        csrfInputs.forEach(input => formData.append(input.name, input.value));

        const response = await fetch('/apikeys/regenerate', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            document.getElementById('regenerateConfirm').style.display = 'none';
            document.getElementById('regenerateResult').style.display = 'block';
            document.getElementById('regeneratedToken').value = data.token;
            btn.style.display = 'none';
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error regenerating token: ' + e.message);
    }

    btn.disabled = false;
    btn.textContent = 'Regenerate Token';
}
</script>
