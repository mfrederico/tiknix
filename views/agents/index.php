<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2"><i class="bi bi-robot"></i> Agents</h1>
        <div>
            <a href="/agents/create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Create Agent
            </a>
        </div>
    </div>

    <!-- Unified tabs: Profiles + Setup -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link active" href="/agents">
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
            <a class="nav-link" href="/workstations">
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

    <?php if (empty($agents)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-robot" style="font-size: 3rem; color: #6c757d;"></i>
                <h4 class="mt-3">No Agents Yet</h4>
                <p class="text-muted">Create an AI agent to automate tasks and extend your team.</p>
                <a href="/agents/create" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Create Your First Agent
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
            <?php foreach ($agents as $agent): ?>
                <?php
                    $capabilities = json_decode($agent->capabilities ?: '[]', true);
                    $mcpServers = json_decode($agent->mcpServers ?: '{}', true);
                    $mcpCount = is_array($mcpServers) ? count($mcpServers) : 0;
                    $providerBadge = [
                        'claude_cli' => ['bg-purple', 'Claude CLI'],
                        'ollama'     => ['bg-success', 'Ollama'],
                        'openai'     => ['bg-info', 'OpenAI'],
                        'custom'     => ['bg-secondary', 'Custom'],
                    ];
                    $badge = $providerBadge[$agent->provider] ?? ['bg-secondary', ucfirst($agent->provider)];
                ?>
                <div class="col">
                    <div class="card h-100 <?= !$agent->isActive ? 'border-secondary opacity-75' : '' ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0">
                                    <a href="/agents/view?id=<?= $agent->id ?>" class="text-decoration-none">
                                        <i class="bi bi-robot"></i>
                                        <?= htmlspecialchars($agent->name) ?>
                                    </a>
                                </h5>
                                <div>
                                    <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
                                    <?php if (!$agent->isActive): ?>
                                        <span class="badge bg-warning text-dark">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($agent->description)): ?>
                                <p class="card-text text-muted small">
                                    <?= htmlspecialchars(substr($agent->description, 0, 120)) ?>
                                    <?= strlen($agent->description) > 120 ? '...' : '' ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($capabilities)): ?>
                                <div class="mb-2">
                                    <?php foreach (array_slice($capabilities, 0, 4) as $cap): ?>
                                        <span class="badge bg-dark border border-secondary me-1 mb-1"><?= htmlspecialchars($cap) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($capabilities) > 4): ?>
                                        <span class="badge bg-dark border border-secondary">+<?= count($capabilities) - 4 ?> more</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex gap-3 text-muted small">
                                <?php if ($mcpCount > 0): ?>
                                    <span><i class="bi bi-plug"></i> <?= $mcpCount ?> MCP server<?= $mcpCount > 1 ? 's' : '' ?></span>
                                <?php endif; ?>
                                <?php if ($agent->exposeAsMcp): ?>
                                    <span><i class="bi bi-broadcast"></i> MCP exposed</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <a href="/agents/edit?id=<?= $agent->id ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <a href="/agents/view?id=<?= $agent->id ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i> View
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.bg-purple { background-color: #6f42c1 !important; }
</style>
