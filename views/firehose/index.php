<?php
/**
 * Error Firehose feed — admin view of errors captured from AI Builder instances.
 * Newest first, 'new' pinned to the top. Read-only except resolve/ignore.
 */
$badge = function (string $s): string {
    $map = [
        'new' => 'warning', 'triaged' => 'info', 'building' => 'primary',
        'reopened' => 'danger', 'deferred' => 'secondary', 'resolved' => 'success',
        'ignored' => 'secondary', 'unmatched' => 'dark',
    ];
    $cls = $map[$s] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . htmlspecialchars(ucfirst($s)) . '</span>';
};
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0"><i class="bi bi-fire text-danger"></i> Error Firehose</h1>
            <p class="text-secondary mb-0">Uncaught errors captured live from your instances. New signatures auto-triage into fix tasks.</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-warning fs-6"><?= (int)($counts['new'] ?? 0) ?> new</span>
            <span class="badge bg-secondary fs-6"><?= (int)($counts['open'] ?? 0) ?> open</span>
            <span class="badge bg-success fs-6"><?= (int)($counts['resolved'] ?? 0) ?> resolved</span>
        </div>
    </div>

    <?php if (empty($errors)): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle"></i> No errors captured yet. When an instance throws, it shows up here.</div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Instance</th>
                            <th>Error</th>
                            <th class="text-center">Hits</th>
                            <th>Last seen</th>
                            <th>Fix</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $row): $e = $row['e']; $t = $row['task']; ?>
                            <tr class="<?= $e->status === 'new' ? 'table-warning' : '' ?>" data-id="<?= (int)$e->id ?>">
                                <td><?= $badge((string)$e->status) ?></td>
                                <td><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars((string)$e->instanceTag) ?></span></td>
                                <td style="max-width:520px;">
                                    <div class="fw-medium text-truncate" title="<?= htmlspecialchars((string)$e->message) ?>"><?= htmlspecialchars((string)$e->message) ?></div>
                                    <div class="small text-muted">
                                        <code><?= htmlspecialchars((string)$e->file) ?>:<?= (int)$e->line ?></code>
                                        <?php if (!empty($e->klass)): ?> · <span title="exception class"><?= htmlspecialchars((string)$e->klass) ?></span><?php endif; ?>
                                        <?php if (!empty($e->url)): ?> · <span class="text-nowrap"><?= htmlspecialchars((string)$e->httpMethod) ?> <?= htmlspecialchars((string)$e->url) ?></span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?= (int)$e->hitCount ?></span></td>
                                <td class="small text-nowrap"><?= htmlspecialchars((string)$e->lastSeenAt) ?></td>
                                <td>
                                    <?php if ($t): ?>
                                        <a href="/workbench/view?id=<?= (int)$t->id ?>" class="text-decoration-none">
                                            <i class="bi bi-wrench-adjustable"></i> task #<?= (int)$t->id ?>
                                            <span class="badge bg-<?= in_array($t->status, ['merged','completed','done']) ? 'success' : 'secondary' ?> ms-1"><?= htmlspecialchars((string)$t->status) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if (!in_array($e->status, ['resolved','ignored'], true)): ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="fhResolve(<?= (int)$e->id ?>,'resolved',this)"><i class="bi bi-check2"></i> Resolve</button>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="fhResolve(<?= (int)$e->id ?>,'ignored',this)"><i class="bi bi-eye-slash"></i> Ignore</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-warning" onclick="fhResolve(<?= (int)$e->id ?>,'new',this)"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
async function fhResolve(id, status, btn) {
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('status', status);
        fd.append('_csrf_token', '<?= csrf_token() ?>');
        const r = await fetch('/firehose/resolve', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': '<?= csrf_token() ?>' } });
        const j = await r.json();
        if (j && j.success) { location.reload(); }
        else { alert('Error: ' + (j && j.message || 'failed')); btn.disabled = false; }
    } catch (e) { alert('Error: ' + e.message); btn.disabled = false; }
}
</script>
