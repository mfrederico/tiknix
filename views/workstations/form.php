<?php
    $isEdit = isset($runner) && $runner && $runner->id;
    $title = $isEdit ? 'Edit Workstation' : 'Add Workstation';
?>
<div class="container py-4">
    <div class="mb-4">
        <a href="/workstations" class="text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to Workstations
        </a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">
            <i class="bi bi-pc-display"></i>
            <?php if ($isEdit): ?>
                <?= htmlspecialchars($runner->name) ?>
            <?php else: ?>
                Add Workstation
            <?php endif; ?>
            <?php if ($isEdit && !$runner->isActive): ?>
                <span class="badge bg-warning text-dark">Inactive</span>
            <?php endif; ?>
            <?php if ($isEdit): ?>
                <span class="badge <?= match($runner->healthStatus) {
                    'healthy' => 'bg-success',
                    'unhealthy' => 'bg-danger',
                    default => 'bg-secondary',
                } ?> ms-2"><?= ucfirst($runner->healthStatus ?? 'unknown') ?></span>
            <?php endif; ?>
        </h1>
        <?php if ($isEdit): ?>
        <div>
            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">
                <i class="bi bi-trash"></i> Delete
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    foreach ($flash as $msg):
    ?>
        <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($msg['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="<?= $isEdit ? '/workstations/update' : '/workstations/store' ?>" id="workstationForm">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>
                        <?php if ($isEdit): ?>
                            <input type="hidden" name="id" value="<?= $runner->id ?>">
                        <?php endif; ?>

                        <!-- Name -->
                        <div class="mb-3">
                            <label for="name" class="form-label">Workstation Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required
                                   value="<?= htmlspecialchars($isEdit ? $runner->name : '') ?>"
                                   placeholder="e.g., Dev Server, Build Machine">
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2"
                                      placeholder="Optional notes about this workstation"><?= htmlspecialchars($isEdit ? ($runner->description ?? '') : '') ?></textarea>
                        </div>

                        <hr>
                        <h6 class="mb-3"><i class="bi bi-hdd-network"></i> SSH Connection</h6>

                        <!-- Host -->
                        <div class="mb-3">
                            <label for="host" class="form-label">Host Address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="host" name="host" required
                                   value="<?= htmlspecialchars($isEdit ? $runner->host : '') ?>"
                                   placeholder="e.g., 192.168.1.100 or myserver.example.com">
                        </div>

                        <div class="row">
                            <!-- SSH User -->
                            <div class="col-md-6 mb-3">
                                <label for="ssh_user" class="form-label">SSH User</label>
                                <input type="text" class="form-control" id="ssh_user" name="ssh_user"
                                       value="<?= htmlspecialchars($isEdit ? ($runner->sshUser ?? 'claudeuser') : 'claudeuser') ?>"
                                       placeholder="claudeuser">
                            </div>

                            <!-- SSH Port -->
                            <div class="col-md-6 mb-3">
                                <label for="ssh_port" class="form-label">SSH Port</label>
                                <input type="number" class="form-control" id="ssh_port" name="ssh_port"
                                       value="<?= $isEdit ? ((int)$runner->sshPort ?: 22) : 22 ?>"
                                       min="1" max="65535">
                            </div>
                        </div>

                        <!-- SSH Key -->
                        <div class="mb-3">
                            <label for="sshkey_id" class="form-label">SSH Key</label>
                            <div class="d-flex gap-2">
                                <select class="form-select" id="sshkey_id" name="sshkey_id">
                                    <option value="">No key (use default SSH agent)</option>
                                    <?php foreach ($sshkeys as $key): ?>
                                        <option value="<?= $key->id ?>"
                                                <?= ($isEdit && (int)($runner->sshkeyId ?? 0) === (int)$key->id) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($key->name) ?>
                                            (<?= htmlspecialchars($key->keyType) ?>)
                                            <?= $key->fingerprint ? ' - ' . htmlspecialchars(substr($key->fingerprint, 0, 30)) . '...' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#generateKeyModal">
                                    <i class="bi bi-key"></i> New
                                </button>
                            </div>
                            <div class="form-text">Select an SSH key for authentication, or leave empty to use the system SSH agent.</div>
                        </div>

                        <hr>
                        <h6 class="mb-3"><i class="bi bi-gear"></i> Settings</h6>

                        <div class="row">
                            <!-- Max Concurrent Jobs -->
                            <div class="col-md-6 mb-3">
                                <label for="max_concurrent_jobs" class="form-label">Max Concurrent Jobs</label>
                                <input type="number" class="form-control" id="max_concurrent_jobs" name="max_concurrent_jobs"
                                       value="<?= $isEdit ? ((int)$runner->maxConcurrentJobs ?: 2) : 2 ?>"
                                       min="1" max="20">
                                <div class="form-text">How many agents can run on this machine simultaneously</div>
                            </div>

                            <!-- Active -->
                            <div class="col-md-6 mb-3 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                           <?= (!$isEdit || $runner->isActive) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save Changes' : 'Create Workstation' ?>
                            </button>
                            <?php if ($isEdit): ?>
                            <button type="button" class="btn btn-outline-secondary" id="testSshBtn">
                                <i class="bi bi-broadcast"></i> Test Connection
                            </button>
                            <button type="button" class="btn btn-outline-info" id="diagBtn">
                                <i class="bi bi-activity"></i> Full Diagnostic
                            </button>
                            <?php endif; ?>
                        </div>

                        <div id="testResult" class="mt-3" style="display:none;"></div>
                    </form>
                </div>
            </div>

            <!-- Diagnostic Results (shown after running) -->
            <div class="card mt-4" id="diagCard" style="display:none;">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-activity"></i> Diagnostic Results</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="diagTable">
                        <thead>
                            <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <?php if ($isEdit && $runner->lastHealthCheck): ?>
            <div class="card mb-4">
                <div class="card-header"><h6 class="mb-0">Last Health Check</h6></div>
                <div class="card-body">
                    <div class="small text-muted">
                        <?= htmlspecialchars($runner->lastHealthCheck) ?>
                    </div>
                    <span class="badge <?= match($runner->healthStatus) {
                        'healthy' => 'bg-success',
                        'unhealthy' => 'bg-danger',
                        default => 'bg-secondary',
                    } ?> mt-2"><?= ucfirst($runner->healthStatus ?? 'unknown') ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- SSH Keys Card -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-key"></i> SSH Keys</h6>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#generateKeyModal">
                        <i class="bi bi-plus"></i> Generate
                    </button>
                </div>
                <div class="list-group list-group-flush" id="sshKeyList">
                    <?php if (empty($sshkeys)): ?>
                        <div class="list-group-item text-muted small text-center py-3">
                            No SSH keys yet. Generate one to get started.
                        </div>
                    <?php else: ?>
                        <?php foreach ($sshkeys as $key): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-medium small"><?= htmlspecialchars($key->name) ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;">
                                            <?= htmlspecialchars($key->keyType) ?>
                                            <?php if ($key->fingerprint): ?>
                                                &bull; <?= htmlspecialchars(substr($key->fingerprint, 0, 40)) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <button class="btn btn-sm btn-outline-secondary copy-pubkey-btn"
                                            data-pubkey="<?= htmlspecialchars($key->publicKey) ?>"
                                            title="Copy public key">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isEdit): ?>
            <!-- Agent Usage -->
            <div class="card">
                <div class="card-header"><h6 class="mb-0">Assigned Agents</h6></div>
                <div class="card-body">
                    <?php
                    $agents = \app\Bean::find('agent', 'runner_id = ?', [$runner->id]);
                    if (empty($agents)):
                    ?>
                        <p class="text-muted small mb-0">No agents assigned to this workstation yet.</p>
                    <?php else: ?>
                        <?php foreach ($agents as $a): ?>
                            <a href="/agents/edit?id=<?= $a->id ?>" class="d-block text-decoration-none small mb-1">
                                <i class="bi bi-robot"></i> <?= htmlspecialchars($a->name) ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Generate SSH Key Modal -->
<div class="modal fade" id="generateKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key"></i> Generate SSH Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="keyGenForm">
                    <div class="mb-3">
                        <label for="keyName" class="form-label">Key Name</label>
                        <input type="text" class="form-control" id="keyName" placeholder="e.g., My Dev Server Key">
                    </div>
                    <div class="mb-3">
                        <label for="keyType" class="form-label">Key Type</label>
                        <select class="form-select" id="keyType">
                            <option value="ed25519" selected>Ed25519 (recommended)</option>
                            <option value="ecdsa">ECDSA (521-bit)</option>
                            <option value="rsa">RSA (4096-bit)</option>
                        </select>
                    </div>
                </div>
                <div id="keyGenResult" style="display:none;">
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> SSH key generated!
                    </div>
                    <p class="small text-muted">Copy the public key below and add it to the remote server's <code>~/.ssh/authorized_keys</code>:</p>
                    <div class="input-group mb-2">
                        <textarea class="form-control font-monospace" id="generatedPubKey" rows="3" readonly></textarea>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('generatedPubKey').value).then(()=>{this.innerHTML='<i class=\'bi bi-check\'></i> Copied'})">
                        <i class="bi bi-clipboard"></i> Copy Public Key
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="generateKeyBtn">
                    <i class="bi bi-key"></i> Generate Key
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($isEdit): ?>
<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Workstation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong><?= htmlspecialchars($runner->name) ?></strong>?</p>
                <p class="text-muted small">This cannot be undone. Agents assigned to this workstation will need to be reassigned.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/workstations/delete" class="d-inline">
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="id" value="<?= $runner->id ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Test SSH Connection
document.getElementById('testSshBtn')?.addEventListener('click', function() {
    const btn = this;
    const resultDiv = document.getElementById('testResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';
    resultDiv.style.display = 'none';

    const form = document.getElementById('workstationForm');
    const formData = new FormData(form);

    fetch('/workstations/test?id=' + (formData.get('id') || '0'), {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        resultDiv.style.display = 'block';
        resultDiv.className = 'mt-3 alert alert-' + (data.success ? 'success' : 'danger');
        resultDiv.textContent = data.message;
    })
    .catch(() => {
        resultDiv.style.display = 'block';
        resultDiv.className = 'mt-3 alert alert-danger';
        resultDiv.textContent = 'Test request failed';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-broadcast"></i> Test Connection';
    });
});

// Full Diagnostic
document.getElementById('diagBtn')?.addEventListener('click', function() {
    const btn = this;
    const diagCard = document.getElementById('diagCard');
    const tbody = document.querySelector('#diagTable tbody');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Running...';

    const form = document.getElementById('workstationForm');
    const formData = new FormData(form);

    fetch('/workstations/diagnose?id=' + (formData.get('id') || '0'), {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        diagCard.style.display = 'block';
        tbody.innerHTML = '';
        (data.checks || []).forEach(check => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${check.name}</td>
                <td><span class="badge bg-${check.passed ? 'success' : 'danger'}">${check.passed ? 'PASS' : 'FAIL'}</span></td>
                <td class="small text-muted">${check.detail || ''}</td>
            `;
            tbody.appendChild(row);
        });
    })
    .catch(() => {
        diagCard.style.display = 'block';
        tbody.innerHTML = '<tr><td colspan="3" class="text-danger">Diagnostic request failed</td></tr>';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-activity"></i> Full Diagnostic';
    });
});

// Generate SSH Key
document.getElementById('generateKeyBtn')?.addEventListener('click', function() {
    const name = document.getElementById('keyName').value.trim();
    const keyType = document.getElementById('keyType').value;
    const btn = this;

    if (!name) {
        alert('Please enter a key name');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generating...';

    const formData = new FormData();
    formData.append('name', name);
    formData.append('key_type', keyType);

    fetch('/workstations/generatesshkey', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('keyGenForm').style.display = 'none';
            document.getElementById('keyGenResult').style.display = 'block';
            document.getElementById('generatedPubKey').value = data.key.public_key;
            btn.style.display = 'none';

            // Add to the SSH key dropdown
            const select = document.getElementById('sshkey_id');
            const option = document.createElement('option');
            option.value = data.key.id;
            option.textContent = data.key.name + ' (' + data.key.key_type + ')';
            option.selected = true;
            select.appendChild(option);
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(e => alert('Error: ' + e.message))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-key"></i> Generate Key';
    });
});

// Reset generate modal on close
document.getElementById('generateKeyModal')?.addEventListener('hidden.bs.modal', function() {
    document.getElementById('keyGenForm').style.display = 'block';
    document.getElementById('keyGenResult').style.display = 'none';
    document.getElementById('generateKeyBtn').style.display = 'block';
    document.getElementById('keyName').value = '';
});

// Copy public key buttons
document.querySelectorAll('.copy-pubkey-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        navigator.clipboard.writeText(this.dataset.pubkey).then(() => {
            this.innerHTML = '<i class="bi bi-check"></i>';
            setTimeout(() => { this.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 2000);
        });
    });
});
</script>
