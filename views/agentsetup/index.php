<div class="container-fluid py-4">
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

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-robot me-2"></i>Agent Setup</h2>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'servers' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#servers" type="button">
                <i class="bi bi-hdd-network me-1"></i> MCP Servers
                <span class="badge bg-secondary ms-1"><?= count($systemServers) + count($userServers) ?></span>
            </button>
        </li>
        <?php if ($isRoot): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'tools' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tools" type="button">
                <i class="bi bi-tools me-1"></i> MCP Tools
                <span class="badge bg-secondary ms-1"><?= count($tools) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'hooks' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#hooks" type="button">
                <i class="bi bi-lightning me-1"></i> Hooks
                <span class="badge bg-secondary ms-1"><?= count($hookFiles) ?></span>
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- MCP Servers Tab -->
        <div class="tab-pane fade <?= $activeTab === 'servers' ? 'show active' : '' ?>" id="servers" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <!-- System Servers -->
                    <?php if (!empty($systemServers)): ?>
                    <div class="card mb-4">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-shield-check me-1"></i> System Servers</h6></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Name</th><th>Type</th><th>Description</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($systemServers as $slug => $server): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($slug) ?></code> <span class="badge bg-secondary">Required</span></td>
                                        <td><span class="badge bg-<?= ($server['config']['type'] ?? 'stdio') === 'http' ? 'info' : 'success' ?>"><?= strtoupper($server['config']['type'] ?? 'stdio') ?></span></td>
                                        <td class="text-muted small"><?= htmlspecialchars($server['description']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Custom Servers -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-server me-1"></i> Custom Servers</h6>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addServerModal">
                                <i class="bi bi-plus-lg"></i> Add Server
                            </button>
                        </div>
                        <?php if (empty($userServers)): ?>
                        <div class="card-body text-center text-muted py-4">No custom servers configured.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Name</th><th>Type</th><th>Configuration</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userServers as $slug => $server): $cfg = $server['config']; ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($slug) ?></code></td>
                                        <td><span class="badge bg-<?= ($cfg['type'] ?? 'stdio') === 'http' ? 'info' : 'success' ?>"><?= strtoupper($cfg['type'] ?? 'stdio') ?></span></td>
                                        <td class="small font-monospace text-truncate" style="max-width:300px;">
                                            <?= htmlspecialchars(($cfg['type'] ?? 'stdio') === 'http' ? ($cfg['url'] ?? '') : ($cfg['command'] ?? '')) ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" onclick="editServer('<?= htmlspecialchars($slug, ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($cfg), ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteServer('<?= htmlspecialchars($slug, ENT_QUOTES) ?>')"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-1"></i> About MCP Servers</h6></div>
                        <div class="card-body small">
                            <p>MCP (Model Context Protocol) servers extend Claude's capabilities.</p>
                            <p><strong>STDIO:</strong> Local process communicating via stdin/stdout</p>
                            <p class="mb-0"><strong>HTTP:</strong> Remote server via HTTP requests</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isRoot): ?>
        <!-- MCP Tools Tab -->
        <div class="tab-pane fade <?= $activeTab === 'tools' ? 'show active' : '' ?>" id="tools" role="tabpanel">
            <div class="row">
                <div class="col-lg-9">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-tools me-1"></i> MCP Tools</h6>
                            <a href="/mcptools/create" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Create Tool</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr><th>Name</th><th>Description</th><th>File</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tools as $tool): ?>
                                    <tr>
                                        <td><code class="small">tiknix_<?= htmlspecialchars($tool['name']) ?></code></td>
                                        <td class="small text-muted" style="max-width:300px;"><?= htmlspecialchars(substr($tool['description'], 0, 60)) ?><?= strlen($tool['description']) > 60 ? '...' : '' ?></td>
                                        <td class="small"><code><?= htmlspecialchars($tool['file']) ?></code></td>
                                        <td class="text-end">
                                            <a href="/mcptools/edit?name=<?= urlencode($tool['name']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3">
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i> Quick Reference</h6></div>
                        <div class="card-body small">
                            <p>Tools extend Claude's capabilities. Each tool needs:</p>
                            <ul class="mb-0">
                                <li><code>$name</code> - identifier</li>
                                <li><code>$description</code> - for Claude</li>
                                <li><code>$inputSchema</code> - params</li>
                                <li><code>execute()</code> - logic</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hooks Tab -->
        <div class="tab-pane fade <?= $activeTab === 'hooks' ? 'show active' : '' ?>" id="hooks" role="tabpanel">
            <div class="row">
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-file-code me-1"></i> Hook Scripts</h6>
                            <a href="/hooks/create" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Create Hook</a>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($hookFiles as $file): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($file['name']) ?></strong>
                                    <br><small class="text-muted"><?= date('M j, g:ia', $file['modTime']) ?> - <?= number_format($file['size']) ?> bytes</small>
                                </div>
                                <a href="/hooks/edit?name=<?= urlencode($file['name']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-sliders me-1"></i> Active Configuration</h6>
                            <a href="/hooks/config" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($hookConfig)): ?>
                            <div class="p-3 text-muted text-center">No hooks configured</div>
                            <?php else: ?>
                            <div class="accordion accordion-flush" id="hookConfigAcc">
                                <?php foreach ($hookConfig as $event => $matchers): ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#cfg-<?= $event ?>">
                                            <span class="badge bg-<?= $event === 'PreToolUse' ? 'warning' : ($event === 'PostToolUse' ? 'success' : 'info') ?> me-2"><?= $event ?></span>
                                            <small class="text-muted"><?= count($matchers) ?> matcher(s)</small>
                                        </button>
                                    </h2>
                                    <div id="cfg-<?= $event ?>" class="accordion-collapse collapse">
                                        <div class="accordion-body small py-2">
                                            <?php foreach ($matchers as $m): ?>
                                            <div class="mb-1"><strong>Matcher:</strong> <code><?= htmlspecialchars($m['matcher'] ?: '*') ?></code></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-book me-1"></i> Hook Events</h6></div>
                        <div class="card-body small">
                            <p><span class="badge bg-warning">PreToolUse</span> Before tool executes. Can block with exit(2).</p>
                            <p><span class="badge bg-success">PostToolUse</span> After tool executes. For logging.</p>
                            <p class="mb-0"><span class="badge bg-info">Stop</span> When session ends. For cleanup.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Server Modal -->
<div class="modal fade" id="addServerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/agent-setup/store-server">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <div class="modal-header">
                    <h5 class="modal-title">Add MCP Server</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="slug" required pattern="[a-z0-9][a-z0-9-]*" placeholder="my-server">
                        <div class="form-text">Lowercase alphanumeric with dashes</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" id="addServerType" onchange="toggleServerFields('add')">
                            <option value="stdio">STDIO (Local Process)</option>
                            <option value="http">HTTP (Remote Server)</option>
                        </select>
                    </div>
                    <div id="addStdioFields">
                        <div class="mb-3">
                            <label class="form-label">Command</label>
                            <input type="text" class="form-control font-monospace" name="command" placeholder="npx">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Arguments (JSON array)</label>
                            <textarea class="form-control font-monospace" name="args" rows="2" placeholder='["-y", "@modelcontextprotocol/server"]'></textarea>
                        </div>
                    </div>
                    <div id="addHttpFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">URL</label>
                            <input type="url" class="form-control font-monospace" name="url" placeholder="https://api.example.com/mcp">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Headers (JSON object)</label>
                            <textarea class="form-control font-monospace" name="headers" rows="2" placeholder='{"Authorization": "Bearer token"}'></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Server</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Server Modal -->
<div class="modal fade" id="editServerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/agent-setup/update-server">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <input type="hidden" name="slug" id="editServerSlug">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Server: <span id="editServerName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" id="editServerType" onchange="toggleServerFields('edit')">
                            <option value="stdio">STDIO</option>
                            <option value="http">HTTP</option>
                        </select>
                    </div>
                    <div id="editStdioFields">
                        <div class="mb-3">
                            <label class="form-label">Command</label>
                            <input type="text" class="form-control font-monospace" name="command" id="editServerCommand">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Arguments (JSON)</label>
                            <textarea class="form-control font-monospace" name="args" id="editServerArgs" rows="2"></textarea>
                        </div>
                    </div>
                    <div id="editHttpFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">URL</label>
                            <input type="url" class="form-control font-monospace" name="url" id="editServerUrl">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Headers (JSON)</label>
                            <textarea class="form-control font-monospace" name="headers" id="editServerHeaders" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Server Modal -->
<div class="modal fade" id="deleteServerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Server</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete server <strong id="deleteServerName"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/agent-setup/delete-server" class="d-inline">
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="slug" id="deleteServerSlug">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleServerFields(prefix) {
    const type = document.getElementById(prefix + 'ServerType').value;
    document.getElementById(prefix + 'StdioFields').classList.toggle('d-none', type !== 'stdio');
    document.getElementById(prefix + 'HttpFields').classList.toggle('d-none', type !== 'http');
}

function editServer(slug, config) {
    document.getElementById('editServerSlug').value = slug;
    document.getElementById('editServerName').textContent = slug;
    document.getElementById('editServerType').value = config.type || 'stdio';
    document.getElementById('editServerCommand').value = config.command || '';
    document.getElementById('editServerArgs').value = config.args ? JSON.stringify(config.args, null, 2) : '';
    document.getElementById('editServerUrl').value = config.url || '';
    document.getElementById('editServerHeaders').value = config.headers ? JSON.stringify(config.headers, null, 2) : '';
    toggleServerFields('edit');
    new bootstrap.Modal(document.getElementById('editServerModal')).show();
}

function deleteServer(slug) {
    document.getElementById('deleteServerSlug').value = slug;
    document.getElementById('deleteServerName').textContent = slug;
    new bootstrap.Modal(document.getElementById('deleteServerModal')).show();
}

// Persist active tab in URL
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('shown.bs.tab', e => {
        const tabId = e.target.getAttribute('data-bs-target').replace('#', '');
        history.replaceState(null, '', '?tab=' + tabId);
    });
});
</script>
