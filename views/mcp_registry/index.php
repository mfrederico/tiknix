<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">MCP Server Registry</h1>
        <a href="/mcpRegistry/add" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Server
        </a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Search and Filter -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="input-group">
                <input type="text" class="form-control" id="serverSearch" placeholder="Search servers...">
                <button class="btn btn-outline-secondary" type="button" id="clearSearch">Clear</button>
            </div>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="statusFilter">
                <option value="">All Status</option>
                <option value="active" <?= ($statusFilter ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($statusFilter ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="deprecated" <?= ($statusFilter ?? '') === 'deprecated' ? 'selected' : '' ?>>Deprecated</option>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="authFilter">
                <option value="">All Auth Types</option>
                <option value="none">None</option>
                <option value="basic">Basic</option>
                <option value="bearer">Bearer</option>
                <option value="apikey">API Key</option>
            </select>
        </div>
        <div class="col-md-2">
            <a href="/mcpRegistry/api" target="_blank" class="btn btn-outline-info">View API</a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th>Name</th>
                    <th>Endpoint</th>
                    <th>Version</th>
                    <th>Auth</th>
                    <th>Tools</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($servers)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No MCP servers registered yet. <a href="/mcpRegistry/add">Add your first server</a>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($servers as $server): ?>
                        <?php $tools = json_decode($server->tools, true) ?: []; ?>
                        <tr data-status="<?= htmlspecialchars($server->status) ?>" data-auth="<?= htmlspecialchars($server->authType) ?>">
                            <td>
                                <?php if ($server->featured): ?>
                                    <i class="fas fa-star text-warning" title="Featured"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($server->name) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($server->slug) ?></small>
                                <?php if (!empty($server->author)): ?>
                                    <br><small class="text-muted">by <?= htmlspecialchars($server->author) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="small text-break" style="max-width: 250px; display: inline-block;"><?= htmlspecialchars($server->endpointUrl) ?></code>
                            </td>
                            <td><code><?= htmlspecialchars($server->version) ?></code></td>
                            <td>
                                <?php
                                $authClass = $server->authType === 'none' ? 'success' : 'info';
                                ?>
                                <span class="badge bg-<?= $authClass ?>">
                                    <?= htmlspecialchars($server->authType) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= count($tools) ?> tools</span>
                            </td>
                            <td>
                                <?php
                                $statusClass = match($server->status) {
                                    'active' => 'success',
                                    'inactive' => 'secondary',
                                    'deprecated' => 'warning',
                                    default => 'secondary'
                                };
                                ?>
                                <span class="badge bg-<?= $statusClass ?>">
                                    <?= htmlspecialchars($server->status) ?>
                                </span>
                            </td>
                            <td>
                                <a href="/mcpRegistry/edit?id=<?= $server->id ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <a href="/mcpRegistry?delete=<?= $server->id ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete this MCP server?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        <h4>About the MCP Registry</h4>
        <p class="text-muted">
            The MCP Server Registry tracks Model Context Protocol servers that integrate with Tiknix.
            Registered servers can be discovered via the <code>list_mcp_servers</code> MCP tool or the
            <a href="/mcpRegistry/api">public JSON API</a>.
        </p>
        <h5>Auth Types</h5>
        <ul class="list-group mb-3">
            <li class="list-group-item"><span class="badge bg-success">none</span> No authentication required</li>
            <li class="list-group-item"><span class="badge bg-info">basic</span> HTTP Basic Auth (username:password)</li>
            <li class="list-group-item"><span class="badge bg-info">bearer</span> Bearer token authentication</li>
            <li class="list-group-item"><span class="badge bg-info">apikey</span> API key authentication</li>
        </ul>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('serverSearch');
    const statusFilter = document.getElementById('statusFilter');
    const authFilter = document.getElementById('authFilter');
    const clearButton = document.getElementById('clearSearch');
    const rows = document.querySelectorAll('tbody tr[data-status]');

    function filterTable() {
        const search = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        const auth = authFilter.value;

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const rowStatus = row.dataset.status;
            const rowAuth = row.dataset.auth;

            let show = true;
            if (search && !text.includes(search)) show = false;
            if (status && rowStatus !== status) show = false;
            if (auth && rowAuth !== auth) show = false;

            row.style.display = show ? '' : 'none';
        });
    }

    searchInput.addEventListener('input', filterTable);
    statusFilter.addEventListener('change', filterTable);
    authFilter.addEventListener('change', filterTable);

    clearButton.addEventListener('click', function() {
        searchInput.value = '';
        statusFilter.value = '';
        authFilter.value = '';
        filterTable();
    });
});
</script>
