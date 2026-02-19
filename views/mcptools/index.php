<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-tools me-2"></i>MCP Tools</h2>
        <a href="/mcptools/create" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Create Tool
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

    <div class="alert alert-warning">
        <i class="bi bi-shield-exclamation me-1"></i>
        <strong>Root Access Required:</strong> MCP tools have code execution capability. Only ROOT level users can manage tools.
    </div>

    <p class="text-muted mb-4">
        Manage MCP tools in <code>mcptools/</code>. Tools are auto-discovered and available to Claude workers via the MCP server.
    </p>

    <?php if (empty($tools)): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
            <p class="mb-0">No MCP tools found.</p>
            <a href="/mcptools/create" class="btn btn-outline-primary mt-3">
                <i class="bi bi-plus-lg"></i> Create Your First Tool
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>File</th>
                        <th>Parameters</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tools as $tool):
                        $isSystem = in_array($tool['file'], ['BaseTool.php', 'ToolLoader.php']);
                        $paramCount = count($tool['inputSchema']['properties'] ?? []);
                        $requiredCount = count($tool['inputSchema']['required'] ?? []);
                    ?>
                    <tr>
                        <td>
                            <code>tiknix_<?= htmlspecialchars($tool['name']) ?></code>
                            <?php if ($isSystem): ?>
                                <span class="badge bg-secondary ms-1">System</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small" style="max-width: 300px;">
                            <?= htmlspecialchars(substr($tool['description'], 0, 100)) ?>
                            <?= strlen($tool['description']) > 100 ? '...' : '' ?>
                        </td>
                        <td>
                            <code class="small"><?= htmlspecialchars($tool['file']) ?></code>
                            <?php if ($tool['modTime']): ?>
                                <br><small class="text-muted"><?= date('M j, g:ia', $tool['modTime']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($paramCount > 0): ?>
                                <span class="badge bg-info"><?= $paramCount ?> params</span>
                                <?php if ($requiredCount > 0): ?>
                                    <span class="badge bg-warning"><?= $requiredCount ?> required</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if (!$isSystem): ?>
                            <div class="btn-group btn-group-sm">
                                <a href="/mcptools/edit?name=<?= urlencode($tool['name']) ?>" class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-outline-danger" title="Delete"
                                        onclick="confirmDelete('<?= htmlspecialchars($tool['name'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <?php else: ?>
                                <span class="text-muted small">Protected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tool Schema Reference -->
    <div class="card mt-4">
        <div class="card-header" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#schemaRef">
            <h6 class="mb-0">
                <i class="bi bi-chevron-down me-1"></i>
                <i class="bi bi-book me-1"></i> Tool Structure Reference
            </h6>
        </div>
        <div class="collapse" id="schemaRef">
            <div class="card-body">
                <p class="text-muted">Every tool must extend <code>BaseTool</code> and define these static properties:</p>
                <table class="table table-sm">
                    <tr>
                        <td><code>$name</code></td>
                        <td>Tool identifier (snake_case, e.g., <code>validate_php</code>)</td>
                    </tr>
                    <tr>
                        <td><code>$description</code></td>
                        <td>Human-readable description shown to Claude</td>
                    </tr>
                    <tr>
                        <td><code>$inputSchema</code></td>
                        <td>JSON Schema defining parameters</td>
                    </tr>
                    <tr>
                        <td><code>execute(array $args)</code></td>
                        <td>Method that implements the tool logic, returns string</td>
                    </tr>
                </table>
                <p class="small text-muted mb-0">
                    Tools are auto-discovered from <code>mcptools/*Tool.php</code> and <code>mcptools/workbench/*Tool.php</code>.
                    Tool names are prefixed with <code>tiknix_</code> when exposed via MCP.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete MCP Tool</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteToolName"></strong>?</p>
                <p class="text-muted small">The file will be renamed with a .deleted suffix for recovery.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteForm" method="POST" action="/mcptools/delete" class="d-inline">
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="name" id="deleteName">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(name) {
    document.getElementById('deleteToolName').textContent = name;
    document.getElementById('deleteName').value = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
