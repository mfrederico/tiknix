<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2"><i class="bi bi-pc-display"></i> Workstations</h1>
        <a href="/workstations/create" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Add Workstation
        </a>
    </div>

    <!-- Unified tabs: Agent Profiles + MCP + Hooks + Workstations -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link" href="/agents">
                <i class="bi bi-people"></i> Agent Profiles
            </a>
        </li>
        <?php if (($member['level'] ?? 100) <= 50): ?>
        <li class="nav-item">
            <a class="nav-link" href="/agentsetup">
                <i class="bi bi-sliders"></i> MCP Servers & Tools
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/agentsetup?tab=hooks">
                <i class="bi bi-link-45deg"></i> Hooks
            </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link active" href="/workstations">
                <i class="bi bi-pc-display"></i> Workstations
            </a>
        </li>
    </ul>

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

    <?php if (empty($runners)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-pc-display" style="font-size: 3rem; color: #6c757d;"></i>
                <h4 class="mt-3">No Workstations Yet</h4>
                <p class="text-muted">Add a remote workstation where your agents can execute tasks via SSH.</p>
                <a href="/workstations/create" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Add Your First Workstation
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
            <?php foreach ($runners as $runner): ?>
                <?php
                    $healthClass = match($runner->healthStatus) {
                        'healthy' => 'text-success',
                        'unhealthy' => 'text-danger',
                        default => 'text-secondary',
                    };
                    $healthIcon = match($runner->healthStatus) {
                        'healthy' => 'bi-check-circle-fill',
                        'unhealthy' => 'bi-x-circle-fill',
                        default => 'bi-question-circle',
                    };
                    $capabilities = json_decode($runner->capabilities ?: '[]', true) ?: [];
                ?>
                <div class="col">
                    <div class="card h-100 <?= !$runner->isActive ? 'border-secondary opacity-75' : '' ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0">
                                    <i class="bi <?= $healthIcon ?> <?= $healthClass ?>"></i>
                                    <?= htmlspecialchars($runner->name) ?>
                                </h5>
                                <div>
                                    <span class="badge bg-info">SSH+tmux</span>
                                    <?php if (!$runner->isActive): ?>
                                        <span class="badge bg-warning text-dark">Inactive</span>
                                    <?php endif; ?>
                                    <?php if ($runner->isDefault): ?>
                                        <span class="badge bg-primary">Default</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($runner->description)): ?>
                                <p class="card-text text-muted small mb-2">
                                    <?= htmlspecialchars(substr($runner->description, 0, 100)) ?>
                                </p>
                            <?php endif; ?>

                            <div class="small text-muted mb-2">
                                <i class="bi bi-hdd-network"></i>
                                <?= htmlspecialchars($runner->sshUser ?: 'claudeuser') ?>@<?= htmlspecialchars($runner->host) ?>:<?= (int)$runner->sshPort ?: 22 ?>
                            </div>

                            <div class="d-flex gap-3 text-muted small">
                                <span><i class="bi bi-people"></i> Max <?= (int)$runner->maxConcurrentJobs ?> jobs</span>
                                <?php if ($runner->sshValidated): ?>
                                    <span class="text-success"><i class="bi bi-shield-check"></i> SSH verified</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($capabilities)): ?>
                                <div class="mt-2">
                                    <?php foreach (array_slice($capabilities, 0, 3) as $cap): ?>
                                        <span class="badge bg-dark border border-secondary me-1"><?= htmlspecialchars($cap) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($capabilities) > 3): ?>
                                        <span class="badge bg-dark border border-secondary">+<?= count($capabilities) - 3 ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-transparent">
                            <a href="/workstations/edit?id=<?= $runner->id ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-secondary test-btn" data-id="<?= $runner->id ?>">
                                <i class="bi bi-broadcast"></i> Test
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.test-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('/workstations/test?id=' + id)
            .then(r => r.json())
            .then(data => {
                this.innerHTML = data.success
                    ? '<i class="bi bi-check-circle text-success"></i> OK'
                    : '<i class="bi bi-x-circle text-danger"></i> Fail';
                setTimeout(() => {
                    this.innerHTML = '<i class="bi bi-broadcast"></i> Test';
                    this.disabled = false;
                }, 3000);
            })
            .catch(() => {
                this.innerHTML = '<i class="bi bi-x-circle text-danger"></i> Error';
                this.disabled = false;
            });
    });
});
</script>
