<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-hdd-network me-2"></i>MCP Servers</h2>
        <a href="/mcpconfig/create" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Add Server
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

    <p class="text-muted mb-4">
        Configure MCP servers available to workspace tasks. System servers (tiknix, playwright) are always included.
    </p>

    <!-- System Servers -->
    <?php if (!empty($systemServers)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>System Servers</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($systemServers as $slug => $server): ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($slug) ?></code>
                            <span class="badge bg-secondary ms-1">Required</span>
                        </td>
                        <td>
                            <?php if (($server['config']['type'] ?? 'stdio') === 'http'): ?>
                                <span class="badge bg-info">HTTP</span>
                            <?php else: ?>
                                <span class="badge bg-success">STDIO</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= htmlspecialchars($server['description']) ?></td>
                        <td class="text-end">
                            <span class="text-muted small">Cannot modify</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- User Servers -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-server me-2"></i>Custom Servers</h5>
        </div>
        <?php if (empty($userServers)): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
            <p class="mb-0">No custom MCP servers configured.</p>
            <a href="/mcpconfig/create" class="btn btn-outline-primary mt-3">
                <i class="bi bi-plus-lg"></i> Add Your First Server
            </a>
        </div>
        <?php else: ?>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Configuration</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userServers as $slug => $server):
                        $config = $server['config'];
                        $type = $config['type'] ?? 'stdio';
                    ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($slug) ?></code>
                        </td>
                        <td>
                            <?php if ($type === 'http'): ?>
                                <span class="badge bg-info">HTTP</span>
                            <?php else: ?>
                                <span class="badge bg-success">STDIO</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($type === 'http'): ?>
                                <code class="text-truncate d-inline-block" style="max-width: 300px;"><?= htmlspecialchars($config['url'] ?? '') ?></code>
                            <?php else: ?>
                                <code class="text-truncate d-inline-block" style="max-width: 300px;"><?= htmlspecialchars($config['command'] ?? '') ?></code>
                                <?php if (!empty($config['args'])): ?>
                                    <br><small class="text-muted">Args: <?= htmlspecialchars(json_encode($config['args'])) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="/mcpconfig/edit?slug=<?= urlencode($slug) ?>" class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-outline-danger" title="Delete"
                                        onclick="confirmDelete('<?= htmlspecialchars($slug, ENT_QUOTES) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Config Preview -->
    <div class="card mt-4">
        <div class="card-header" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#configPreview">
            <h6 class="mb-0">
                <i class="bi bi-chevron-down me-1"></i>
                <i class="bi bi-file-code me-1"></i> Raw .mcp.json Preview
            </h6>
        </div>
        <div class="collapse" id="configPreview">
            <div class="card-body p-0">
                <pre id="configJson" class="bg-dark text-light p-3 mb-0 small" style="max-height: 400px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete MCP Server</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteServerName"></strong>?</p>
                <p class="text-muted small">This will remove the server from your .mcp.json configuration.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteForm" method="POST" action="/mcpconfig/delete" class="d-inline">
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="slug" id="deleteSlug">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(slug) {
    document.getElementById('deleteServerName').textContent = slug;
    document.getElementById('deleteSlug').value = slug;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Load config preview when section is opened
document.getElementById('configPreview').addEventListener('show.bs.collapse', function() {
    fetch('/mcpconfig/preview')
        .then(r => r.json())
        .then(data => {
            document.getElementById('configJson').textContent = JSON.stringify(data.config, null, 2);
        })
        .catch(err => {
            document.getElementById('configJson').textContent = 'Error loading config: ' + err.message;
        });
});
</script>
