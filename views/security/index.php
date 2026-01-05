<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-shield-lock me-2"></i>Security Rules</h2>
            <p class="text-muted mb-0">Manage Claude Code sandbox security rules</p>
        </div>
        <a href="/security/create" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> New Rule
        </a>
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

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Filter by Target</label>
                    <select name="filter" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Rules</option>
                        <option value="path" <?= $filter === 'path' ? 'selected' : '' ?>>Path Rules</option>
                        <option value="command" <?= $filter === 'command' ? 'selected' : '' ?>>Command Rules</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search name, pattern, description..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-secondary me-2">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <a href="/security" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Test Tool -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-bug me-2"></i>Test Rules</h5>
        </div>
        <div class="card-body">
            <form id="testForm" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Target</label>
                    <select name="target" id="testTarget" class="form-select">
                        <option value="path">Path</option>
                        <option value="command">Command</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" id="testSubject" class="form-control" placeholder="/etc/passwd or sudo rm -rf">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Member Level</label>
                    <select name="level" id="testLevel" class="form-select">
                        <option value="1">ROOT (1)</option>
                        <option value="50">ADMIN (50)</option>
                        <option value="100" selected>MEMBER (100)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Operation</label>
                    <select name="is_write" id="testIsWrite" class="form-select">
                        <option value="0">Read</option>
                        <option value="1">Write</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-info w-100">
                        <i class="bi bi-play-fill"></i> Test
                    </button>
                </div>
            </form>
            <div id="testResult" class="mt-3" style="display: none;"></div>
        </div>
    </div>

    <!-- Path Rules -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-folder me-2"></i>Path Rules</h5>
            <span class="badge bg-secondary"><?= count($pathRules) ?> rules</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px">Active</th>
                        <th style="width: 80px">Priority</th>
                        <th>Name</th>
                        <th>Action</th>
                        <th>Pattern</th>
                        <th>Level</th>
                        <th style="width: 120px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pathRules)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">No path rules found</td></tr>
                    <?php else: ?>
                        <?php foreach ($pathRules as $rule): ?>
                            <tr class="<?= !$rule->isActive ? 'table-secondary text-muted' : '' ?>">
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input toggle-active" type="checkbox"
                                               data-id="<?= $rule->id ?>"
                                               <?= $rule->isActive ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td><?= $rule->priority ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($rule->name) ?></strong>
                                    <?php if ($rule->description): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($rule->description) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $actionBadge = match($rule->action) {
                                        'block' => 'danger',
                                        'allow' => 'success',
                                        'protect' => 'warning',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?= $actionBadge ?>"><?= ucfirst($rule->action) ?></span>
                                </td>
                                <td><code class="small"><?= htmlspecialchars($rule->pattern) ?></code></td>
                                <td>
                                    <?php if ($rule->level === null): ?>
                                        <span class="text-muted">All</span>
                                    <?php else: ?>
                                        <?= $rule->level ?>
                                        <small class="text-muted">(<?= $rule->level <= 1 ? 'ROOT' : ($rule->level <= 50 ? 'ADMIN' : 'MEMBER') ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/security/edit?id=<?= $rule->id ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="/security/delete" class="d-inline" onsubmit="return confirm('Delete this rule?');">
                                        <?php foreach ($csrf as $name => $value): ?>
                                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                                        <?php endforeach; ?>
                                        <input type="hidden" name="id" value="<?= $rule->id ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Command Rules -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-terminal me-2"></i>Command Rules</h5>
            <span class="badge bg-secondary"><?= count($commandRules) ?> rules</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px">Active</th>
                        <th style="width: 80px">Priority</th>
                        <th>Name</th>
                        <th>Action</th>
                        <th>Pattern</th>
                        <th>Level</th>
                        <th style="width: 120px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($commandRules)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">No command rules found</td></tr>
                    <?php else: ?>
                        <?php foreach ($commandRules as $rule): ?>
                            <tr class="<?= !$rule->isActive ? 'table-secondary text-muted' : '' ?>">
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input toggle-active" type="checkbox"
                                               data-id="<?= $rule->id ?>"
                                               <?= $rule->isActive ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td><?= $rule->priority ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($rule->name) ?></strong>
                                    <?php if ($rule->description): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($rule->description) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $actionBadge = match($rule->action) {
                                        'block' => 'danger',
                                        'allow' => 'success',
                                        'protect' => 'warning',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?= $actionBadge ?>"><?= ucfirst($rule->action) ?></span>
                                </td>
                                <td><code class="small"><?= htmlspecialchars($rule->pattern) ?></code></td>
                                <td>
                                    <?php if ($rule->level === null): ?>
                                        <span class="text-muted">All</span>
                                    <?php else: ?>
                                        <?= $rule->level ?>
                                        <small class="text-muted">(<?= $rule->level <= 1 ? 'ROOT' : ($rule->level <= 50 ? 'ADMIN' : 'MEMBER') ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/security/edit?id=<?= $rule->id ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="/security/delete" class="d-inline" onsubmit="return confirm('Delete this rule?');">
                                        <?php foreach ($csrf as $name => $value): ?>
                                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                                        <?php endforeach; ?>
                                        <input type="hidden" name="id" value="<?= $rule->id ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?= \app\SimpleCsrf::getToken() ?>';

// Toggle active status
document.querySelectorAll('.toggle-active').forEach(checkbox => {
    checkbox.addEventListener('change', async function() {
        const id = this.dataset.id;
        const row = this.closest('tr');

        try {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('_csrf_token', csrfToken);

            const response = await fetch('/security/toggle', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                row.classList.toggle('table-secondary', !data.is_active);
                row.classList.toggle('text-muted', !data.is_active);
            } else {
                alert('Error: ' + data.message);
                this.checked = !this.checked;
            }
        } catch (e) {
            alert('Error toggling rule');
            this.checked = !this.checked;
        }
    });
});

// Test form
document.getElementById('testForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('_csrf_token', csrfToken);

    try {
        const response = await fetch('/security/test', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        const resultDiv = document.getElementById('testResult');

        if (data.success) {
            const result = data.result;
            const alertClass = result.allowed ? 'alert-success' : 'alert-danger';
            const icon = result.allowed ? 'check-circle-fill' : 'x-circle-fill';

            let matchedHtml = '';
            if (data.matched_rules.length > 0) {
                matchedHtml = '<hr><strong>Matched Rules:</strong><ul class="mb-0">';
                data.matched_rules.forEach(r => {
                    matchedHtml += `<li><code>${r.name}</code> (${r.action}, pattern: ${r.pattern})</li>`;
                });
                matchedHtml += '</ul>';
            }

            resultDiv.innerHTML = `
                <div class="alert ${alertClass}">
                    <i class="bi bi-${icon} me-2"></i>
                    <strong>${result.allowed ? 'ALLOWED' : 'BLOCKED'}</strong>: ${result.reason}
                    ${matchedHtml}
                </div>
            `;
        } else {
            resultDiv.innerHTML = `<div class="alert alert-danger">Error: ${data.message}</div>`;
        }

        resultDiv.style.display = 'block';
    } catch (e) {
        alert('Error testing rules: ' + e.message);
    }
});
</script>
