<div class="container-fluid py-4">
    <div class="row">
        <!-- Left sidebar navigation (admins) -->
        <aside class="col-12 col-md-3 col-lg-2 mb-4">
            <div class="list-group shadow-sm">
                <div class="list-group-item bg-dark text-uppercase small fw-bold text-secondary">
                    Admin
                </div>
                <a href="/leads" class="list-group-item list-group-item-action active">
                    <i class="bi bi-person-lines-fill"></i> Leads
                    <span class="badge bg-light text-dark float-end"><?= (int)($total ?? 0) ?></span>
                </a>
                <a href="/admin" class="list-group-item list-group-item-action">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="/admin/members" class="list-group-item list-group-item-action">
                    <i class="bi bi-people"></i> Members
                </a>
            </div>
        </aside>

        <!-- Main content -->
        <main class="col-12 col-md-9 col-lg-10">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h3 mb-0"><i class="bi bi-person-lines-fill"></i> Leads</h1>
                <span class="text-secondary"><?= (int)($total ?? 0) ?> total</span>
            </div>

            <?php if (empty($leads)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> No leads yet. They'll appear here as people sign up on your Coming Soon page.
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>First Name</th>
                                    <th>Last Name</th>
                                    <th>Email</th>
                                    <th>Signed Up</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leads as $lead): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($lead->firstName ?? '') ?></td>
                                        <td><?= htmlspecialchars($lead->lastName ?? '') ?></td>
                                        <td>
                                            <a href="mailto:<?= htmlspecialchars($lead->email ?? '') ?>">
                                                <?= htmlspecialchars($lead->email ?? '') ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($lead->createdAt ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>
