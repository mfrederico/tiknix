<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2"><i class="bi bi-people-fill me-2"></i>Leads</h1>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-info fs-6"><?= (int)$total ?> Total</span>
            <?php if (!empty($leads)): ?>
                <a href="/lead/export" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-download me-1"></i>Export CSV
                </a>
            <?php endif; ?>
            <a href="/admin" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Admin
            </a>
        </div>
    </div>

    <p class="text-muted">Signups captured from the landing-page form.</p>

    <?php if (empty($leads)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-1"></i> No leads captured yet.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Captured</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars(trim($lead->firstName . ' ' . $lead->lastName)) ?></strong></td>
                            <td><a href="mailto:<?= htmlspecialchars($lead->email) ?>"><?= htmlspecialchars($lead->email) ?></a></td>
                            <td>
                                <?php $ts = strtotime((string)$lead->createdAt); ?>
                                <span title="<?= htmlspecialchars((string)$lead->createdAt) ?>">
                                    <?= $ts ? date('M j, Y g:i A', $ts) : htmlspecialchars((string)$lead->createdAt) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <form method="post" action="/lead/delete" class="d-inline"
                                      onsubmit="return confirm('Delete this lead?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$lead->id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete lead">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php $pages = (int)ceil($total / $perPage); if ($pages > 1): ?>
            <nav aria-label="Leads pagination">
                <ul class="pagination">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="/lead/admin?page=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
