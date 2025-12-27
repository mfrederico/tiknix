<div class="inspector-layout">
    <!-- Sidebar -->
    <aside class="inspector-sidebar">
        <h5 class="mb-3">
            <i class="bi bi-hdd-network"></i> MCP Registry
        </h5>

        <!-- Search -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Search</div>
            <input type="text" class="form-control form-control-sm" id="serverSearch" placeholder="Search servers...">
        </div>

        <!-- Filters -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Filters</div>
            <select class="form-select form-select-sm mb-2" id="statusFilter">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="deprecated">Deprecated</option>
            </select>
            <select class="form-select form-select-sm" id="authFilter">
                <option value="">All Auth Types</option>
                <option value="none">None</option>
                <option value="basic">Basic</option>
                <option value="bearer">Bearer</option>
                <option value="apikey">API Key</option>
            </select>
        </div>

        <!-- Quick Actions -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Actions</div>
            <?php if ($isLoggedIn ?? false): ?>
            <a href="/mcp/registry/add" class="btn btn-connect btn-sm w-100 mb-2">
                <i class="bi bi-plus-lg"></i> Add Server
            </a>
            <a href="/apikeys" class="btn btn-outline-secondary btn-sm w-100 mb-2">
                <i class="bi bi-key"></i> API Keys
            </a>
            <?php endif; ?>
            <a href="/mcp/registry/api" target="_blank" class="btn btn-outline-secondary btn-sm w-100">
                <i class="bi bi-code-slash"></i> View API
            </a>
        </div>

        <!-- Collapsible Sections -->
        <div class="sidebar-collapsible">
            <div class="sidebar-collapsible-header" data-bs-toggle="collapse" data-bs-target="#installSection">
                <i class="bi bi-chevron-down"></i> Installation Guide
            </div>
            <div class="collapse" id="installSection">
                <div class="sidebar-collapsible-body">
                    <p class="small text-muted mb-2">Add to <code>~/.claude/settings.json</code>:</p>
                    <pre class="small mb-0"><code>{
  "mcpServers": {
    "name": {
      "type": "http",
      "url": "..."
    }
  }
}</code></pre>
                </div>
            </div>
        </div>

        <div class="sidebar-collapsible">
            <div class="sidebar-collapsible-header" data-bs-toggle="collapse" data-bs-target="#authSection">
                <i class="bi bi-chevron-down"></i> Auth Types
            </div>
            <div class="collapse" id="authSection">
                <div class="sidebar-collapsible-body">
                    <div class="d-flex align-items-center mb-1">
                        <span class="badge bg-success me-2">none</span>
                        <small>No auth required</small>
                    </div>
                    <div class="d-flex align-items-center mb-1">
                        <span class="badge bg-info me-2">bearer</span>
                        <small>Bearer token</small>
                    </div>
                    <div class="d-flex align-items-center mb-1">
                        <span class="badge bg-info me-2">apikey</span>
                        <small>API key header</small>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="badge bg-info me-2">basic</span>
                        <small>HTTP Basic</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status -->
        <div class="mt-auto pt-3">
            <div class="status-indicator">
                <span class="status-dot connected"></span>
                <span class="small"><?= count($servers ?? []) ?> servers registered</span>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="inspector-main">
        <div class="inspector-content">
            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($servers)): ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-hdd-network"></i>
                    </div>
                    <h3 class="empty-state-title">No MCP Servers Registered</h3>
                    <p class="empty-state-text">
                        Connect your first MCP server to start inspecting
                    </p>
                    <?php if ($isLoggedIn ?? false): ?>
                    <a href="/mcp/registry/add" class="btn btn-connect">
                        <i class="bi bi-plus-lg"></i> Add Your First Server
                    </a>
                    <?php else: ?>
                    <p class="small text-muted">
                        <a href="/auth/login">Login</a> to add servers
                    </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Server Grid -->
                <div class="row" id="serverGrid">
                    <?php foreach ($servers as $server): ?>
                        <?php $tools = json_decode($server->tools, true) ?: []; ?>
                        <div class="col-lg-6 col-xl-4 server-item"
                             data-status="<?= htmlspecialchars($server->status) ?>"
                             data-auth="<?= htmlspecialchars($server->authType) ?>"
                             data-name="<?= htmlspecialchars(strtolower($server->name . ' ' . $server->slug)) ?>">
                            <div class="server-card">
                                <div class="server-card-header">
                                    <div>
                                        <h6 class="server-card-title">
                                            <?php if ($server->featured): ?>
                                                <i class="bi bi-star-fill text-warning me-1"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($server->name) ?>
                                        </h6>
                                        <div class="server-card-meta">
                                            <span><?= htmlspecialchars($server->slug) ?></span>
                                            <?php if (!empty($server->author)): ?>
                                                <span class="ms-2">by <?= htmlspecialchars($server->author) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <?php
                                        $statusClass = match($server->status) {
                                            'active' => 'success',
                                            'inactive' => 'secondary',
                                            'deprecated' => 'warning',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge bg-<?= $statusClass ?>"><?= htmlspecialchars($server->status) ?></span>
                                    </div>
                                </div>

                                <div class="server-card-endpoint">
                                    <?= htmlspecialchars($server->endpointUrl) ?>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <?php
                                        $authClass = $server->authType === 'none' ? 'success' : 'info';
                                        ?>
                                        <span class="badge bg-<?= $authClass ?>"><?= htmlspecialchars($server->authType) ?></span>
                                        <span class="badge bg-secondary"><?= count($tools) ?> tools</span>
                                        <code class="ms-1 small">v<?= htmlspecialchars($server->version) ?></code>
                                    </div>
                                </div>

                                <?php if (!empty($tools)): ?>
                                <div class="server-card-tools">
                                    <?php foreach (array_slice($tools, 0, 4) as $tool): ?>
                                        <span class="tool-tag">
                                            <i class="bi bi-gear"></i>
                                            <?= htmlspecialchars(is_array($tool) ? ($tool['name'] ?? 'tool') : $tool) ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if (count($tools) > 4): ?>
                                        <span class="tool-tag">+<?= count($tools) - 4 ?> more</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($isLoggedIn ?? false): ?>
                                <div class="server-card-actions">
                                    <button class="btn btn-sm btn-outline-primary fetch-tools-btn"
                                            data-server-id="<?= $server->id ?>"
                                            data-endpoint="<?= htmlspecialchars($server->endpointUrl) ?>">
                                        <i class="bi bi-arrow-repeat"></i> Fetch Tools
                                    </button>
                                    <a href="/mcp/registry/edit?id=<?= $server->id ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <a href="/mcp/registry?delete=<?= $server->id ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Delete this MCP server?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bottom Panels -->
        <div class="inspector-panels">
            <div class="inspector-panel">
                <div class="inspector-panel-header">
                    <span>Recent Activity</span>
                    <button class="btn btn-link btn-sm p-0 text-info">Clear</button>
                </div>
                <div class="inspector-panel-body" id="activityLog">
                    No activity yet
                </div>
            </div>
            <div class="inspector-panel">
                <div class="inspector-panel-header">
                    <span>Server Notifications</span>
                    <button class="btn btn-link btn-sm p-0 text-info">Clear</button>
                </div>
                <div class="inspector-panel-body" id="notifications">
                    No notifications yet
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('serverSearch');
    const statusFilter = document.getElementById('statusFilter');
    const authFilter = document.getElementById('authFilter');
    const serverItems = document.querySelectorAll('.server-item');
    const activityLog = document.getElementById('activityLog');

    function filterServers() {
        const search = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        const auth = authFilter.value;

        serverItems.forEach(item => {
            const name = item.dataset.name;
            const itemStatus = item.dataset.status;
            const itemAuth = item.dataset.auth;

            let show = true;
            if (search && !name.includes(search)) show = false;
            if (status && itemStatus !== status) show = false;
            if (auth && itemAuth !== auth) show = false;

            item.style.display = show ? '' : 'none';
        });
    }

    if (searchInput) searchInput.addEventListener('input', filterServers);
    if (statusFilter) statusFilter.addEventListener('change', filterServers);
    if (authFilter) authFilter.addEventListener('change', filterServers);

    // Fetch tools functionality
    document.querySelectorAll('.fetch-tools-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const serverId = this.dataset.serverId;
            const endpoint = this.dataset.endpoint;
            const originalHtml = this.innerHTML;

            this.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Fetching...';
            this.disabled = true;

            try {
                const response = await fetch('/mcp/registry/fetchTools?id=' + serverId);
                const data = await response.json();

                if (data.success) {
                    logActivity('Fetched ' + (data.toolCount || 0) + ' tools from ' + endpoint);
                    location.reload();
                } else {
                    logActivity('Error: ' + (data.error || 'Failed to fetch tools'));
                }
            } catch (e) {
                logActivity('Error: ' + e.message);
            }

            this.innerHTML = originalHtml;
            this.disabled = false;
        });
    });

    function logActivity(message) {
        const time = new Date().toLocaleTimeString();
        if (activityLog.innerHTML === 'No activity yet') {
            activityLog.innerHTML = '';
            activityLog.style.fontStyle = 'normal';
        }
        activityLog.innerHTML = '<div class="mb-1"><small class="text-muted">[' + time + ']</small> ' + message + '</div>' + activityLog.innerHTML;
    }
});
</script>

<style>
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
