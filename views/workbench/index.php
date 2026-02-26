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

    <!-- Agent Status Dashboard -->
    <div id="agent-status-strip" class="mb-4" style="display: none;">
        <div class="d-flex align-items-center mb-2">
            <h6 class="text-muted text-uppercase mb-0 me-2"><i class="bi bi-robot"></i> Active Agents</h6>
            <span id="agent-count-badge" class="badge bg-info rounded-pill" style="font-size: 0.7em;">0</span>
        </div>
        <div id="agent-cards" class="d-flex flex-wrap gap-2"></div>
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
                                                'merged' => 'success',
                                                'failed' => 'danger',
                                                'paused' => 'warning',
                                                'awaiting', 'waiting' => 'warning',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge bg-<?= $statusBadge ?>">
                                                <?php if ($task->status === 'running'): ?>
                                                    <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                                <?php elseif ($task->status === 'merged'): ?>
                                                    <i class="bi bi-git me-1"></i>
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

<style>
.agent-card {
    min-width: 220px;
    max-width: 300px;
    transition: all 0.3s ease;
    cursor: pointer;
    border-left: 3px solid transparent;
}
.agent-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.agent-card[data-status="running"],
.agent-card[data-status="working"],
.agent-card[data-status="thinking"],
.agent-card[data-status="determining"],
.agent-card[data-status="analyzing"],
.agent-card[data-status="executing"] {
    border-left-color: #0d6efd;
}
.agent-card[data-status="waiting"],
.agent-card[data-status="awaiting"] {
    border-left-color: #ffc107;
}
.agent-card[data-status="queued"] {
    border-left-color: #0dcaf0;
}
.agent-card[data-status="idle"] {
    border-left-color: #6c757d;
}
.agent-card[data-status="error"] {
    border-left-color: #dc3545;
}
.agent-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.agent-status-dot.running { background-color: #0d6efd; animation: pulse-dot 1.5s infinite; }
.agent-status-dot.waiting { background-color: #ffc107; }
.agent-status-dot.queued { background-color: #0dcaf0; animation: pulse-dot 2s infinite; }
.agent-status-dot.idle { background-color: #6c757d; }
.agent-status-dot.error { background-color: #dc3545; }
@keyframes pulse-dot {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}
.provider-icon { font-size: 0.7em; }
.provider-claude_cli { background-color: #6f42c1 !important; }
.provider-ollama { background-color: #198754 !important; }
.provider-openai { background-color: #0dcaf0 !important; }
.provider-custom { background-color: #6c757d !important; }
</style>

<script>
(function() {
    const strip = document.getElementById('agent-status-strip');
    const cardsContainer = document.getElementById('agent-cards');
    const countBadge = document.getElementById('agent-count-badge');
    let previousAgents = {};

    function getStatusClass(status) {
        const running = ['running', 'working', 'thinking', 'determining', 'analyzing',
                         'exploring', 'searching', 'reading', 'writing', 'executing', 'processing'];
        if (running.includes(status)) return 'running';
        if (status === 'queued') return 'queued';
        if (status === 'waiting' || status === 'awaiting') return 'waiting';
        if (status === 'error' || status === 'failed') return 'error';
        return 'idle';
    }

    function getStatusLabel(status) {
        const labels = {
            'running': 'Running', 'working': 'Working', 'thinking': 'Thinking',
            'determining': 'Determining', 'analyzing': 'Analyzing', 'exploring': 'Exploring',
            'searching': 'Searching', 'reading': 'Reading', 'writing': 'Writing',
            'executing': 'Executing', 'processing': 'Processing', 'queued': 'Queued',
            'waiting': 'Waiting', 'awaiting': 'Awaiting input', 'idle': 'Idle',
            'error': 'Error', 'failed': 'Failed', 'completed': 'Completed'
        };
        return labels[status] || (status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Idle');
    }

    function getProviderLabel(provider) {
        const labels = { 'claude_cli': 'Claude', 'ollama': 'Ollama', 'openai': 'OpenAI', 'custom': 'Custom' };
        return labels[provider] || provider;
    }

    function renderAgentCard(agent) {
        const statusClass = getStatusClass(agent.status);
        const hasTask = agent.current_task_id != null;
        const clickUrl = hasTask ? '/workbench/view?id=' + agent.current_task_id : '/agents/view?id=' + agent.id;

        let activityHtml = '';
        if (agent.current_activity) {
            activityHtml = '<div class="text-muted small text-truncate" style="max-width: 250px;">' +
                           escapeHtml(agent.current_activity) + '</div>';
        } else if (hasTask) {
            activityHtml = '<div class="text-muted small text-truncate" style="max-width: 250px;">' +
                           escapeHtml(agent.current_task_title) + '</div>';
        }

        return '<a href="' + clickUrl + '" class="agent-card card text-decoration-none" ' +
               'data-agent-id="' + agent.id + '" data-status="' + statusClass + '">' +
               '<div class="card-body py-2 px-3">' +
               '<div class="d-flex align-items-center gap-2 mb-1">' +
               '<span class="agent-status-dot ' + statusClass + '"></span>' +
               '<span class="fw-medium small text-dark text-truncate">' + escapeHtml(agent.name) + '</span>' +
               '<span class="badge provider-' + agent.provider + ' provider-icon text-white ms-auto">' +
               getProviderLabel(agent.provider) + '</span>' +
               '</div>' +
               '<div class="d-flex align-items-center gap-1">' +
               '<span class="badge bg-' + (statusClass === 'running' ? 'primary' :
               statusClass === 'waiting' ? 'warning text-dark' :
               statusClass === 'queued' ? 'info' :
               statusClass === 'error' ? 'danger' : 'secondary') +
               '" style="font-size: 0.65em;">' + getStatusLabel(agent.status) + '</span>' +
               (agent.team ? '<span class="text-muted small ms-auto" style="font-size: 0.7em;"><i class="bi bi-people"></i> ' +
               escapeHtml(agent.team) + '</span>' : '') +
               '</div>' +
               activityHtml +
               '</div></a>';
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function updateAgentCards(agents) {
        // Only show strip if there are agents with tasks or active status
        var activeAgents = agents.filter(function(a) {
            return a.current_task_id != null || getStatusClass(a.status) !== 'idle';
        });

        if (activeAgents.length === 0) {
            strip.style.display = 'none';
            return;
        }

        strip.style.display = '';
        countBadge.textContent = activeAgents.length;

        // Build new cards HTML
        var html = '';
        activeAgents.forEach(function(agent) {
            html += renderAgentCard(agent);
        });

        // Check if content actually changed to avoid unnecessary DOM updates
        var newAgentKey = activeAgents.map(function(a) {
            return a.id + ':' + a.status + ':' + (a.current_task_id || '') + ':' + (a.current_activity || '');
        }).join('|');

        var prevKey = cardsContainer.getAttribute('data-agent-key') || '';
        if (newAgentKey !== prevKey) {
            cardsContainer.innerHTML = html;
            cardsContainer.setAttribute('data-agent-key', newAgentKey);
        }
    }

    function pollAgentStatus() {
        fetch('/workbench/agentstatus')
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (data && data.agents) {
                    updateAgentCards(data.agents);
                }
            })
            .catch(function() {
                // Silently ignore polling errors
            });
    }

    // Initial poll
    pollAgentStatus();

    // Poll every 8 seconds
    setInterval(pollAgentStatus, 8000);
})();
</script>
