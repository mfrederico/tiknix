<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-lightning me-2"></i>Claude Hooks</h2>
        <div class="btn-group">
            <a href="/hooks/create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Create Hook
            </a>
            <a href="/hooks/config" class="btn btn-outline-secondary">
                <i class="bi bi-gear"></i> Configuration
            </a>
        </div>
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
        <strong>Root Access Required:</strong> Hooks execute code during Claude operations. Only ROOT level users can manage hooks.
    </div>

    <div class="row">
        <!-- Hook Files -->
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-file-code me-2"></i>Hook Scripts</h5>
                </div>
                <?php if (empty($files)): ?>
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                    <p class="mb-0">No hook scripts found.</p>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($files as $file): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= htmlspecialchars($file['name']) ?></strong>
                            <br><small class="text-muted"><?= date('M j, g:ia', $file['modTime']) ?> - <?= number_format($file['size']) ?> bytes</small>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <a href="/hooks/edit?name=<?= urlencode($file['name']) ?>" class="btn btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button type="button" class="btn btn-outline-danger" title="Delete"
                                    onclick="confirmDelete('<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Active Configuration -->
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-sliders me-2"></i>Active Configuration</h5>
                    <a href="/hooks/config" class="btn btn-sm btn-outline-primary">Edit</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($hooks)): ?>
                    <div class="p-3 text-muted text-center">No hooks configured</div>
                    <?php else: ?>
                    <div class="accordion accordion-flush" id="hookConfig">
                        <?php foreach ($hooks as $event => $matchers): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#event-<?= $event ?>">
                                    <span class="badge bg-<?= $event === 'PreToolUse' ? 'warning' : ($event === 'PostToolUse' ? 'success' : 'info') ?> me-2">
                                        <?= htmlspecialchars($event) ?>
                                    </span>
                                    <span class="text-muted small"><?= count($matchers) ?> matcher(s)</span>
                                </button>
                            </h2>
                            <div id="event-<?= $event ?>" class="accordion-collapse collapse" data-bs-parent="#hookConfig">
                                <div class="accordion-body small">
                                    <?php foreach ($matchers as $m): ?>
                                    <div class="mb-2 p-2 bg-light rounded">
                                        <strong>Matcher:</strong> <code><?= htmlspecialchars($m['matcher'] ?: '*') ?></code>
                                        <?php foreach ($m['hooks'] ?? [] as $h): ?>
                                        <div class="ms-3 mt-1">
                                            <code class="text-truncate d-inline-block" style="max-width: 300px;">
                                                <?= htmlspecialchars($h['command'] ?? '') ?>
                                            </code>
                                            <?php if (!empty($h['timeout'])): ?>
                                                <span class="text-muted">(<?= $h['timeout'] ?>s timeout)</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Reference -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-book me-1"></i> Hook Events</h6>
                </div>
                <div class="card-body small">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td><span class="badge bg-warning">PreToolUse</span></td>
                            <td>Before a tool executes. Can block with exit(2).</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-success">PostToolUse</span></td>
                            <td>After a tool executes. For logging/notifications.</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-info">Stop</span></td>
                            <td>When Claude session ends. For cleanup/reporting.</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Hook</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteHookName"></strong>?</p>
                <p class="text-muted small">The file will be renamed with a .deleted suffix for recovery.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/hooks/delete" class="d-inline">
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
    document.getElementById('deleteHookName').textContent = name;
    document.getElementById('deleteName').value = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
