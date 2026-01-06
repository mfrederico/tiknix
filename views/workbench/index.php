<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Workbench</h1>
        <div>
            <a href="/teams" class="btn btn-outline-secondary me-2">
                <i class="bi bi-people"></i> Teams
            </a>
            <?php
            $createUrl = '/workbench/create';
            if (!empty($filters['team_id']) && $filters['team_id'] !== 'personal' && is_numeric($filters['team_id'])) {
                $createUrl .= '?team_id=' . (int)$filters['team_id'];
            }
            ?>
            <a href="<?= $createUrl ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New Task
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

    <div class="row">
        <!-- Sidebar Filters -->
        <div class="col-lg-3 col-md-4 mb-4">
            <h6 class="text-muted text-uppercase mb-3"><i class="bi bi-funnel"></i> Filters</h6>

            <!-- Status Counts -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">Status</h6>
                </div>
                <div class="list-group list-group-flush">
                    <a href="/workbench" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= empty($filters['status']) ? 'active' : '' ?>">
                        All Tasks
                        <span class="badge bg-secondary rounded-pill"><?= $counts['total'] ?></span>
                    </a>
                    <a href="/workbench?status=pending" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $filters['status'] === 'pending' ? 'active' : '' ?>">
                        <span><i class="bi bi-circle text-secondary"></i> Pending</span>
                        <span class="badge bg-secondary rounded-pill"><?= $counts['pending'] ?></span>
                    </a>
                    <a href="/workbench?status=running" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $filters['status'] === 'running' ? 'active' : '' ?>">
                        <span><i class="bi bi-play-circle text-primary"></i> Running</span>
                        <span class="badge bg-primary rounded-pill"><?= $counts['running'] + $counts['queued'] ?></span>
                    </a>
                    <a href="/workbench?status=completed" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $filters['status'] === 'completed' ? 'active' : '' ?>">
                        <span><i class="bi bi-check-circle text-success"></i> Completed</span>
                        <span class="badge bg-success rounded-pill"><?= $counts['completed'] ?></span>
                    </a>
                    <a href="/workbench?status=failed" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $filters['status'] === 'failed' ? 'active' : '' ?>">
                        <span><i class="bi bi-x-circle text-danger"></i> Failed</span>
                        <span class="badge bg-danger rounded-pill"><?= $counts['failed'] ?></span>
                    </a>
                </div>
            </div>

            <!-- Type Filter -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Type</h6>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($taskTypes as $type => $info): ?>
                        <a href="/workbench?type=<?= $type ?>" class="list-group-item list-group-item-action <?= $filters['task_type'] === $type ? 'active' : '' ?>">
                            <i class="bi bi-<?= $info['icon'] ?> text-<?= $info['color'] ?>"></i> <?= $info['label'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Task List -->
        <div class="col-lg-9 col-md-8">
            <!-- Team Tabs -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?= empty($filters['team_id']) ? 'active' : '' ?>" href="/workbench<?= !empty($filters['status']) ? '?status=' . $filters['status'] : '' ?>">
                        All Tasks
                        <span class="badge bg-secondary rounded-pill ms-1"><?= $teamCounts['total'] ?? 0 ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filters['team_id'] === 'personal' ? 'active' : '' ?>" href="/workbench?team_id=personal<?= !empty($filters['status']) ? '&status=' . $filters['status'] : '' ?>">
                        <i class="bi bi-person"></i> Personal
                        <span class="badge bg-secondary rounded-pill ms-1"><?= $teamCounts['personal'] ?? 0 ?></span>
                    </a>
                </li>
                <?php foreach ($teams ?? [] as $team): ?>
                <li class="nav-item">
                    <a class="nav-link <?= (string)$filters['team_id'] === (string)$team['id'] ? 'active' : '' ?>" href="/workbench?team_id=<?= $team['id'] ?><?= !empty($filters['status']) ? '&status=' . $filters['status'] : '' ?>">
                        <i class="bi bi-people"></i> <?= htmlspecialchars($team['name']) ?>
                        <span class="badge bg-secondary rounded-pill ms-1"><?= $teamCounts[$team['id']] ?? 0 ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if (empty($tasks)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                        <h4 class="mt-3">No Tasks Found</h4>
                        <p class="text-muted">Create a new task to get started with AI-assisted development.</p>
                        <a href="<?= $createUrl ?>" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Create Task
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50%;">Task</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task): ?>
                                    <?php
                                    $typeInfo = $taskTypes[$task->taskType] ?? $taskTypes['feature'];
                                    $priorityInfo = $priorities[$task->priority] ?? $priorities[3];
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="/workbench/view?id=<?= $task->id ?>" class="text-decoration-none fw-medium">
                                                <?= htmlspecialchars($task->title) ?>
                                            </a>
                                            <?php if ($task->teamId): ?>
                                                <br><small class="text-muted"><i class="bi bi-people"></i> Team task</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $typeInfo['color'] ?>-subtle text-<?= $typeInfo['color'] ?>">
                                                <i class="bi bi-<?= $typeInfo['icon'] ?>"></i> <?= $typeInfo['label'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $priorityInfo['color'] ?>-subtle text-<?= $priorityInfo['color'] ?>">
                                                <?= $priorityInfo['label'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $statusBadge = match($task->status) {
                                                'pending' => 'secondary',
                                                'queued' => 'info',
                                                'running' => 'primary',
                                                'completed' => 'success',
                                                'failed' => 'danger',
                                                'paused' => 'warning',
                                                'awaiting', 'waiting' => 'warning',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge bg-<?= $statusBadge ?>">
                                                <?php if ($task->status === 'running'): ?>
                                                    <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                                <?php endif; ?>
                                                <?= ucfirst($task->status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= date('M j', strtotime($task->createdAt)) ?></small>
                                        </td>
                                        <td>
                                            <a href="/workbench/view?id=<?= $task->id ?>" class="btn btn-sm btn-outline-primary">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
