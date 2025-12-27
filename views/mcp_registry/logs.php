<div class="inspector-layout">
    <!-- Sidebar -->
    <aside class="inspector-sidebar">
        <h5 class="mb-3">
            <i class="bi bi-journal-text"></i> MCP Proxy Logs
        </h5>

        <!-- Filters -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Filters</div>
            <form method="get" action="/mcp/registry/logs">
                <select name="method" class="form-select form-select-sm mb-2" onchange="this.form.submit()">
                    <option value="">All Methods</option>
                    <?php foreach ($methods ?? [] as $method): ?>
                    <option value="<?= htmlspecialchars($method) ?>" <?= ($filters['method'] ?? '') === $method ? 'selected' : '' ?>>
                        <?= htmlspecialchars($method) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="has_error" class="form-select form-select-sm mb-2" onchange="this.form.submit()">
                    <option value="">All Results</option>
                    <option value="0" <?= ($filters['has_error'] ?? '') === '0' ? 'selected' : '' ?>>Success Only</option>
                    <option value="1" <?= ($filters['has_error'] ?? '') === '1' ? 'selected' : '' ?>>Errors Only</option>
                </select>
                <input type="text" name="member_id" class="form-control form-control-sm"
                       placeholder="Member ID" value="<?= htmlspecialchars($filters['member_id'] ?? '') ?>"
                       onchange="this.form.submit()">
            </form>
        </div>

        <!-- Quick Actions -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Actions</div>
            <a href="/mcp/registry" class="btn btn-outline-secondary btn-sm w-100 mb-2">
                <i class="bi bi-arrow-left"></i> Back to Registry
            </a>
            <button type="button" class="btn btn-outline-danger btn-sm w-100" id="clearLogsBtn">
                <i class="bi bi-trash"></i> Clear Old Logs
            </button>
        </div>

        <!-- Legend -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Legend</div>
            <div class="d-flex align-items-center mb-1">
                <span class="badge bg-success me-2">2xx</span>
                <small>Success</small>
            </div>
            <div class="d-flex align-items-center mb-1">
                <span class="badge bg-warning me-2">4xx</span>
                <small>Client Error</small>
            </div>
            <div class="d-flex align-items-center">
                <span class="badge bg-danger me-2">5xx</span>
                <small>Server Error</small>
            </div>
        </div>

        <!-- Stats -->
        <div class="mt-auto pt-3">
            <div class="status-indicator">
                <span class="status-dot connected"></span>
                <span class="small"><?= $total ?? 0 ?> log entries</span>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="inspector-main">
        <div class="inspector-content">
            <?php if (empty($logs)): ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <h3 class="empty-state-title">No Logs Found</h3>
                    <p class="empty-state-text">
                        <?php if (!empty($filters['method']) || !empty($filters['has_error']) || !empty($filters['member_id'])): ?>
                            No logs match your current filters
                        <?php else: ?>
                            MCP proxy requests will appear here as they are made
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($filters['method']) || !empty($filters['has_error']) || !empty($filters['member_id'])): ?>
                    <a href="/mcp/registry/logs" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> Clear Filters
                    </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Logs Table -->
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th width="140">Time</th>
                                <th width="100">Method</th>
                                <th width="80">Status</th>
                                <th width="70">Duration</th>
                                <th>Request Preview</th>
                                <th width="60">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $httpCode = $log->httpCode ?? 200;
                                $hasError = !empty($log->error) || $httpCode >= 400;
                                $statusClass = $httpCode >= 500 ? 'danger' : ($httpCode >= 400 ? 'warning' : 'success');
                                $duration = $log->duration ?? 0;
                                ?>
                                <tr class="<?= $hasError ? 'table-danger' : '' ?>">
                                    <td>
                                        <small class="text-muted"><?= date('M j H:i:s', strtotime($log->createdAt)) ?></small>
                                    </td>
                                    <td>
                                        <code class="small"><?= htmlspecialchars($log->method ?? 'unknown') ?></code>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $statusClass ?>"><?= $httpCode ?></span>
                                    </td>
                                    <td>
                                        <small class="<?= $duration > 1000 ? 'text-warning' : 'text-muted' ?>">
                                            <?= $duration < 1000 ? $duration . 'ms' : round($duration / 1000, 2) . 's' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="text-truncate d-block" style="max-width: 300px;">
                                            <?php
                                            $reqBody = $log->requestBody ?? '';
                                            $preview = strlen($reqBody) > 80 ? substr($reqBody, 0, 80) . '...' : $reqBody;
                                            echo htmlspecialchars($preview);
                                            ?>
                                        </small>
                                        <?php if (!empty($log->error)): ?>
                                            <small class="text-danger d-block">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                <?= htmlspecialchars(strlen($log->error) > 60 ? substr($log->error, 0, 60) . '...' : $log->error) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info view-log-btn"
                                                data-log-id="<?= $log->id ?>" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Log pagination">
                    <ul class="pagination pagination-sm justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?><?= !empty($filters['method']) ? '&method=' . urlencode($filters['method']) : '' ?><?= !empty($filters['has_error']) ? '&has_error=' . urlencode($filters['has_error']) : '' ?><?= !empty($filters['member_id']) ? '&member_id=' . urlencode($filters['member_id']) : '' ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php if ($startPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=1<?= !empty($filters['method']) ? '&method=' . urlencode($filters['method']) : '' ?><?= !empty($filters['has_error']) ? '&has_error=' . urlencode($filters['has_error']) : '' ?><?= !empty($filters['member_id']) ? '&member_id=' . urlencode($filters['member_id']) : '' ?>">1</a>
                        </li>
                        <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?><?= !empty($filters['method']) ? '&method=' . urlencode($filters['method']) : '' ?><?= !empty($filters['has_error']) ? '&has_error=' . urlencode($filters['has_error']) : '' ?><?= !empty($filters['member_id']) ? '&member_id=' . urlencode($filters['member_id']) : '' ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $totalPages ?><?= !empty($filters['method']) ? '&method=' . urlencode($filters['method']) : '' ?><?= !empty($filters['has_error']) ? '&has_error=' . urlencode($filters['has_error']) : '' ?><?= !empty($filters['member_id']) ? '&member_id=' . urlencode($filters['member_id']) : '' ?>"><?= $totalPages ?></a>
                        </li>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?><?= !empty($filters['method']) ? '&method=' . urlencode($filters['method']) : '' ?><?= !empty($filters['has_error']) ? '&has_error=' . urlencode($filters['has_error']) : '' ?><?= !empty($filters['member_id']) ? '&member_id=' . urlencode($filters['member_id']) : '' ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <p class="text-center text-muted small">
                    Showing page <?= $page ?> of <?= $totalPages ?> (<?= $total ?> total entries)
                </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Log Detail Modal -->
<div class="modal fade" id="logDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-journal-code"></i> Log Entry Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label text-muted small">Method</label>
                        <div id="logMethod" class="fw-bold"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-muted small">HTTP Code</label>
                        <div id="logHttpCode"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-muted small">Duration</label>
                        <div id="logDuration"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-muted small">Member ID</label>
                        <div id="logMemberId"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-muted small">Timestamp</label>
                        <div id="logTimestamp"></div>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">IP Address</label>
                        <div id="logIpAddress"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">User Agent</label>
                        <div id="logUserAgent" class="text-truncate small"></div>
                    </div>
                </div>

                <div id="logError" class="alert alert-danger d-none mb-3">
                    <i class="bi bi-exclamation-triangle"></i> <span id="logErrorText"></span>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label text-muted small d-flex justify-content-between">
                            <span>Request Body</span>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="copyJson('logRequestBody')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </label>
                        <pre id="logRequestBody" class="bg-black p-2 rounded small" style="max-height: 300px; overflow: auto;"></pre>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small d-flex justify-content-between">
                            <span>Response Body</span>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="copyJson('logResponseBody')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </label>
                        <pre id="logResponseBody" class="bg-black p-2 rounded small" style="max-height: 300px; overflow: auto;"></pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Clear Logs Modal -->
<div class="modal fade" id="clearLogsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-trash"></i> Clear Old Logs</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Delete logs older than:</p>
                <select id="clearLogsDays" class="form-select">
                    <option value="1">1 day</option>
                    <option value="3">3 days</option>
                    <option value="7" selected>7 days</option>
                    <option value="14">14 days</option>
                    <option value="30">30 days</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmClearLogs">
                    <i class="bi bi-trash"></i> Delete Logs
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const logDetailModal = new bootstrap.Modal(document.getElementById('logDetailModal'));
    const clearLogsModal = new bootstrap.Modal(document.getElementById('clearLogsModal'));

    // View log details
    document.querySelectorAll('.view-log-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const logId = this.dataset.logId;

            try {
                const response = await fetch('/mcp/registry/logDetail?id=' + logId);
                const data = await response.json();

                if (data.success) {
                    const log = data.log;

                    document.getElementById('logMethod').textContent = log.method || 'unknown';

                    const httpCode = log.httpCode || 200;
                    const statusClass = httpCode >= 500 ? 'danger' : (httpCode >= 400 ? 'warning' : 'success');
                    document.getElementById('logHttpCode').innerHTML = '<span class="badge bg-' + statusClass + '">' + httpCode + '</span>';

                    const duration = log.duration || 0;
                    document.getElementById('logDuration').textContent = duration < 1000 ? duration + 'ms' : (duration / 1000).toFixed(2) + 's';

                    document.getElementById('logMemberId').textContent = log.memberId || '0 (unauthenticated)';
                    document.getElementById('logTimestamp').textContent = log.createdAt;
                    document.getElementById('logIpAddress').textContent = log.ipAddress || '-';
                    document.getElementById('logUserAgent').textContent = log.userAgent || '-';

                    // Error
                    if (log.error) {
                        document.getElementById('logError').classList.remove('d-none');
                        document.getElementById('logErrorText').textContent = log.error;
                    } else {
                        document.getElementById('logError').classList.add('d-none');
                    }

                    // Format JSON bodies
                    document.getElementById('logRequestBody').textContent = formatJson(log.requestBody);
                    document.getElementById('logResponseBody').textContent = formatJson(log.responseBody);

                    logDetailModal.show();
                } else {
                    alert('Error loading log: ' + (data.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Error loading log: ' + e.message);
            }
        });
    });

    // Clear logs button
    document.getElementById('clearLogsBtn').addEventListener('click', function() {
        clearLogsModal.show();
    });

    // Confirm clear logs
    document.getElementById('confirmClearLogs').addEventListener('click', async function() {
        const days = document.getElementById('clearLogsDays').value;
        const btn = this;
        const originalHtml = btn.innerHTML;

        btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Deleting...';
        btn.disabled = true;

        try {
            const response = await fetch('/mcp/registry/clearLogs?days=' + days);
            const data = await response.json();

            if (data.success) {
                clearLogsModal.hide();
                alert(data.message || 'Logs cleared successfully');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to clear logs'));
            }
        } catch (e) {
            alert('Error: ' + e.message);
        }

        btn.innerHTML = originalHtml;
        btn.disabled = false;
    });

    function formatJson(str) {
        if (!str) return '';
        try {
            return JSON.stringify(JSON.parse(str), null, 2);
        } catch (e) {
            return str;
        }
    }
});

function copyJson(elementId) {
    const text = document.getElementById(elementId).textContent;
    navigator.clipboard.writeText(text).then(() => {
        // Brief visual feedback
        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i>';
        setTimeout(() => { btn.innerHTML = originalHtml; }, 1000);
    });
}
</script>

<style>
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
#logRequestBody, #logResponseBody {
    font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Fira Mono', 'Droid Sans Mono', 'Source Code Pro', monospace;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
