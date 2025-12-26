<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">My Teams</h1>
        <div>
            <a href="/workbench" class="btn btn-outline-secondary me-2">
                <i class="bi bi-kanban"></i> Workbench
            </a>
            <a href="/teams/create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Create Team
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

    <?php if (empty($teams)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-people" style="font-size: 3rem; color: #6c757d;"></i>
                <h4 class="mt-3">No Teams Yet</h4>
                <p class="text-muted">Create a team to collaborate on tasks with others.</p>
                <a href="/teams/create" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Create Your First Team
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
            <?php foreach ($teams as $team): ?>
                <div class="col">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0">
                                    <a href="/teams/view?id=<?= $team['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($team['name']) ?>
                                    </a>
                                </h5>
                                <span class="badge bg-<?= $team['role'] === 'owner' ? 'primary' : ($team['role'] === 'admin' ? 'info' : 'secondary') ?>">
                                    <?= ucfirst($team['role']) ?>
                                </span>
                            </div>
                            <?php if (!empty($team['description'])): ?>
                                <p class="card-text text-muted small">
                                    <?= htmlspecialchars(substr($team['description'], 0, 100)) ?>
                                    <?= strlen($team['description']) > 100 ? '...' : '' ?>
                                </p>
                            <?php endif; ?>
                            <div class="d-flex gap-3 text-muted small">
                                <span><i class="bi bi-people"></i> <?= $team['member_count'] ?> members</span>
                                <span><i class="bi bi-list-task"></i> <?= $team['task_count'] ?> tasks</span>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <a href="/teams/view?id=<?= $team['id'] ?>" class="btn btn-sm btn-outline-primary">
                                View Team
                            </a>
                            <?php if ($team['role'] === 'owner'): ?>
                                <a href="/teams/settings?id=<?= $team['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-gear"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
