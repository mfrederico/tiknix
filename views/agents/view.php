<?php
    $capabilities = $capabilities ?? [];
    $mcpServers = $mcpServers ?? [];
    $providerBadge = [
        'claude_cli' => ['bg-purple', 'Claude CLI'],
        'ollama'     => ['bg-success', 'Ollama'],
        'openai'     => ['bg-info', 'OpenAI'],
        'custom'     => ['bg-secondary', 'Custom'],
    ];
    $badge = $providerBadge[$agent->provider] ?? ['bg-secondary', ucfirst($agent->provider)];
?>
<div class="container py-4">
    <div class="mb-4">
        <a href="/agents" class="text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to Agents
        </a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">
            <i class="bi bi-robot"></i> <?= htmlspecialchars($agent->name) ?>
            <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
            <?php if (!$agent->isActive): ?>
                <span class="badge bg-warning text-dark">Inactive</span>
            <?php endif; ?>
        </h1>
        <?php if ((int)$agent->createdBy === (int)$member->id || (int)$member->level <= 50): ?>
            <a href="/agents/edit?id=<?= $agent->id ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($agent->description)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <p class="mb-0"><?= nl2br(htmlspecialchars($agent->description)) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Details -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Details</h6></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted">Provider</td><td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $agent->provider))) ?></td></tr>
                        <tr><td class="text-muted">Slug</td><td><code><?= htmlspecialchars($agent->slug) ?></code></td></tr>
                        <tr><td class="text-muted">Status</td><td><?= $agent->isActive ? '<span class="text-success">Active</span>' : '<span class="text-warning">Inactive</span>' ?></td></tr>
                        <tr><td class="text-muted">MCP Exposed</td><td><?= $agent->exposeAsMcp ? 'Yes' : 'No' ?></td></tr>
                        <?php if ($agent->mcpToolName): ?>
                            <tr><td class="text-muted">MCP Tool Name</td><td><code><?= htmlspecialchars($agent->mcpToolName) ?></code></td></tr>
                        <?php endif; ?>
                        <tr><td class="text-muted">Created</td><td><?= htmlspecialchars($agent->createdAt ?? 'N/A') ?></td></tr>
                        <tr><td class="text-muted">Updated</td><td><?= htmlspecialchars($agent->updatedAt ?? 'N/A') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Capabilities -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Capabilities</h6></div>
                <div class="card-body">
                    <?php if (!empty($capabilities)): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($capabilities as $cap): ?>
                                <span class="badge bg-dark border border-secondary"><?= htmlspecialchars($cap) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No capabilities configured</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- System Prompt -->
    <?php if (!empty($agent->systemPrompt)): ?>
    <div class="card mt-4">
        <div class="card-header"><h6 class="mb-0">System Prompt</h6></div>
        <div class="card-body">
            <pre class="mb-0 text-light" style="white-space: pre-wrap;"><?= htmlspecialchars($agent->systemPrompt) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <!-- MCP Servers -->
    <?php if (!empty($mcpServers)): ?>
    <div class="card mt-4">
        <div class="card-header"><h6 class="mb-0">MCP Servers (<?= count($mcpServers) ?>)</h6></div>
        <div class="card-body">
            <pre class="mb-0 text-light" style="white-space: pre-wrap;"><?= htmlspecialchars(json_encode($mcpServers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.bg-purple { background-color: #6f42c1 !important; }
</style>
