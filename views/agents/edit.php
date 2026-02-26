<?php
    $activeTab = $tab ?? 'general';
    $providerConfig = $providerConfig ?? [];
    $capabilities = $capabilities ?? [];
    $mcpServers = $mcpServers ?? [];
    $hooks = $hooks ?? [];
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
            <?php if (!$agent->isActive): ?>
                <span class="badge bg-warning text-dark">Inactive</span>
            <?php endif; ?>
        </h1>
        <div>
            <a href="/agents/view?id=<?= $agent->id ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-eye"></i> View Profile
            </a>
            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">
                <i class="bi bi-trash"></i> Delete
            </button>
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

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" href="/agents/edit?id=<?= $agent->id ?>&tab=general">
                <i class="bi bi-gear"></i> General
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'provider' ? 'active' : '' ?>" href="/agents/edit?id=<?= $agent->id ?>&tab=provider">
                <i class="bi bi-cpu"></i> Provider
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'mcp' ? 'active' : '' ?>" href="/agents/edit?id=<?= $agent->id ?>&tab=mcp">
                <i class="bi bi-plug"></i> MCP
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'hooks' ? 'active' : '' ?>" href="/agents/edit?id=<?= $agent->id ?>&tab=hooks">
                <i class="bi bi-link-45deg"></i> Hooks
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'capabilities' ? 'active' : '' ?>" href="/agents/edit?id=<?= $agent->id ?>&tab=capabilities">
                <i class="bi bi-stars"></i> Capabilities
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'workstation' ? 'active' : '' ?>" href="/agents/edit?id=<?= $agent->id ?>&tab=workstation">
                <i class="bi bi-pc-display"></i> Workstation
            </a>
        </li>
    </ul>

    <!-- General Tab -->
    <?php if ($activeTab === 'general'): ?>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="/agents/update">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <input type="hidden" name="id" value="<?= $agent->id ?>">
                <input type="hidden" name="tab" value="general">

                <div class="mb-3">
                    <label for="name" class="form-label">Agent Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" required
                           value="<?= htmlspecialchars($agent->name) ?>"
                           minlength="2" maxlength="255">
                </div>

                <div class="mb-3">
                    <label class="form-label">Slug</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($agent->slug) ?>" disabled>
                    <div class="form-text">Auto-generated from the agent name</div>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($agent->description ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="system_prompt" class="form-label">System Prompt</label>
                    <textarea class="form-control font-monospace" id="system_prompt" name="system_prompt" rows="8"><?= htmlspecialchars($agent->systemPrompt ?? '') ?></textarea>
                </div>

                <div class="mb-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $agent->isActive ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                    <div class="form-text">Inactive agents cannot be assigned to tasks</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Save General Settings
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Provider Tab -->
    <?php if ($activeTab === 'provider'): ?>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="/agents/update">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <input type="hidden" name="id" value="<?= $agent->id ?>">
                <input type="hidden" name="tab" value="provider">

                <div class="mb-3">
                    <label for="provider" class="form-label">Provider</label>
                    <select class="form-select" id="provider" name="provider">
                        <?php foreach ($providers as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $agent->provider === $p ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $p))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Friendly provider config fields (auto-builds JSON) -->
                <input type="hidden" id="provider_config" name="provider_config" value="<?= htmlspecialchars(json_encode($providerConfig, JSON_UNESCAPED_SLASHES)) ?>">

                <?php $pc = $providerConfig; ?>

                <!-- Claude CLI / Claude API fields -->
                <div id="cfg-claude" class="provider-cfg-section" style="display:none;">
                    <div class="mb-3">
                        <label for="cfg_model_claude" class="form-label">Model</label>
                        <select class="form-select cfg-field" id="cfg_model_claude" data-key="model">
                            <option value="sonnet" <?= ($pc['model'] ?? '') === 'sonnet' ? 'selected' : '' ?>>Sonnet (fast, balanced)</option>
                            <option value="opus" <?= ($pc['model'] ?? '') === 'opus' ? 'selected' : '' ?>>Opus (most capable)</option>
                            <option value="haiku" <?= ($pc['model'] ?? '') === 'haiku' ? 'selected' : '' ?>>Haiku (fastest, cheapest)</option>
                            <option value="claude-sonnet-4-5-20250514" <?= ($pc['model'] ?? '') === 'claude-sonnet-4-5-20250514' ? 'selected' : '' ?>>Sonnet 4.5 (API)</option>
                            <option value="claude-opus-4-6" <?= ($pc['model'] ?? '') === 'claude-opus-4-6' ? 'selected' : '' ?>>Opus 4.6 (API)</option>
                        </select>
                        <div class="form-text">For CLI: use short names (sonnet, opus, haiku). For API: use full model IDs.</div>
                    </div>
                </div>

                <!-- Ollama fields -->
                <div id="cfg-ollama" class="provider-cfg-section" style="display:none;">
                    <div class="mb-3">
                        <label for="cfg_base_url" class="form-label">Ollama Server URL</label>
                        <input type="text" class="form-control cfg-field" id="cfg_base_url" data-key="base_url"
                               value="<?= htmlspecialchars($pc['base_url'] ?? 'http://localhost:11434') ?>"
                               placeholder="http://localhost:11434">
                    </div>
                    <div class="mb-3">
                        <label for="cfg_model_ollama" class="form-label">Model Name</label>
                        <input type="text" class="form-control cfg-field" id="cfg_model_ollama" data-key="model"
                               value="<?= htmlspecialchars($pc['model'] ?? '') ?>"
                               placeholder="e.g., llama3, qwen3-coder:30b, codellama">
                        <div class="form-text">Enter the Ollama model name. Must be already pulled on the server.</div>
                    </div>
                </div>

                <!-- OpenAI fields -->
                <div id="cfg-openai" class="provider-cfg-section" style="display:none;">
                    <div class="mb-3">
                        <label for="cfg_model_openai" class="form-label">Model</label>
                        <select class="form-select cfg-field" id="cfg_model_openai" data-key="model">
                            <option value="gpt-4-turbo" <?= ($pc['model'] ?? '') === 'gpt-4-turbo' ? 'selected' : '' ?>>GPT-4 Turbo</option>
                            <option value="gpt-4o" <?= ($pc['model'] ?? '') === 'gpt-4o' ? 'selected' : '' ?>>GPT-4o</option>
                            <option value="gpt-4o-mini" <?= ($pc['model'] ?? '') === 'gpt-4o-mini' ? 'selected' : '' ?>>GPT-4o Mini</option>
                        </select>
                    </div>
                </div>

                <!-- Custom HTTP fields -->
                <div id="cfg-custom_http" class="provider-cfg-section" style="display:none;">
                    <div class="mb-3">
                        <label for="cfg_endpoint" class="form-label">API Endpoint URL</label>
                        <input type="url" class="form-control cfg-field" id="cfg_endpoint" data-key="endpoint"
                               value="<?= htmlspecialchars($pc['endpoint'] ?? '') ?>"
                               placeholder="https://api.example.com/v1/chat/completions">
                    </div>
                    <div class="mb-3">
                        <label for="cfg_model_custom" class="form-label">Model Name</label>
                        <input type="text" class="form-control cfg-field" id="cfg_model_custom" data-key="model"
                               value="<?= htmlspecialchars($pc['model'] ?? '') ?>"
                               placeholder="e.g., my-custom-model">
                    </div>
                </div>

                <details class="mb-3">
                    <summary class="text-muted small">Advanced: Raw JSON</summary>
                    <textarea class="form-control font-monospace mt-2" id="provider_config_raw" rows="6"><?= htmlspecialchars(json_encode($providerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                    <div class="form-text">Edit raw JSON directly. Changes here override the fields above.</div>
                </details>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Save Provider Settings
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="testConnectionBtn">
                        <i class="bi bi-broadcast"></i> Test Connection
                    </button>
                </div>

                <div id="connectionResult" class="mt-3" style="display:none;"></div>
            </form>
        </div>
    </div>

    <script>
    // Provider config section toggling + JSON building
    (function() {
        const providerSelect = document.getElementById('provider');
        const hiddenInput = document.getElementById('provider_config');
        const rawTextarea = document.getElementById('provider_config_raw');
        const sectionMap = {
            claude_cli: 'cfg-claude',
            claude_api: 'cfg-claude',
            ollama: 'cfg-ollama',
            openai: 'cfg-openai',
            custom_http: 'cfg-custom_http',
        };

        function showProviderSection() {
            document.querySelectorAll('.provider-cfg-section').forEach(s => s.style.display = 'none');
            const id = sectionMap[providerSelect.value];
            if (id) document.getElementById(id).style.display = 'block';
        }

        function buildConfigJson() {
            const section = sectionMap[providerSelect.value];
            if (!section) return '{}';
            const fields = document.getElementById(section).querySelectorAll('.cfg-field');
            const cfg = {};
            fields.forEach(f => {
                const val = f.value.trim();
                if (val) cfg[f.dataset.key] = val;
            });
            return JSON.stringify(cfg);
        }

        providerSelect.addEventListener('change', showProviderSection);
        showProviderSection();

        // Sync fields → JSON on form submit
        const form = providerSelect.closest('form');
        form.addEventListener('submit', function() {
            // If raw JSON was edited, use that; otherwise build from fields
            const rawVal = rawTextarea.value.trim();
            try {
                JSON.parse(rawVal);
                hiddenInput.value = rawVal;
            } catch(e) {
                hiddenInput.value = buildConfigJson();
            }
        });

        // Also sync raw textarea when fields change
        document.querySelectorAll('.cfg-field').forEach(f => {
            f.addEventListener('change', () => {
                rawTextarea.value = JSON.stringify(JSON.parse(buildConfigJson()), null, 2);
            });
        });
    })();

    document.getElementById('testConnectionBtn')?.addEventListener('click', function() {
        const provider = document.getElementById('provider').value;
        const config = document.getElementById('provider_config').value;
        const resultDiv = document.getElementById('connectionResult');
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';
        resultDiv.style.display = 'none';

        fetch('/agents/testconnection?provider=' + encodeURIComponent(provider) + '&config=' + encodeURIComponent(config))
            .then(r => r.json())
            .then(data => {
                resultDiv.style.display = 'block';
                resultDiv.className = 'mt-3 alert alert-' + (data.success ? 'success' : 'danger');
                resultDiv.textContent = data.message;
            })
            .catch(() => {
                resultDiv.style.display = 'block';
                resultDiv.className = 'mt-3 alert alert-danger';
                resultDiv.textContent = 'Connection test failed';
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-broadcast"></i> Test Connection';
            });
    });
    </script>
    <?php endif; ?>

    <!-- MCP Tab -->
    <?php if ($activeTab === 'mcp'): ?>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="/agents/update">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <input type="hidden" name="id" value="<?= $agent->id ?>">
                <input type="hidden" name="tab" value="mcp">

                <div class="mb-3">
                    <label for="mcp_servers" class="form-label">MCP Servers (JSON)</label>
                    <textarea class="form-control font-monospace" id="mcp_servers" name="mcp_servers" rows="10"><?= htmlspecialchars(json_encode($mcpServers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                    <div class="form-text">
                        MCP server configuration for this agent. Format:
                        <code>{"server-name": {"command": "npx", "args": ["-y", "server-pkg"]}}</code>
                    </div>
                </div>

                <hr>

                <h6>Expose as MCP Tool</h6>
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="expose_as_mcp" name="expose_as_mcp" value="1"
                               <?= $agent->exposeAsMcp ? 'checked' : '' ?>>
                        <label class="form-check-label" for="expose_as_mcp">
                            Expose this agent as an MCP tool for other agents
                        </label>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="mcp_tool_name" class="form-label">MCP Tool Name</label>
                    <input type="text" class="form-control" id="mcp_tool_name" name="mcp_tool_name"
                           value="<?= htmlspecialchars($agent->mcpToolName ?? '') ?>"
                           placeholder="e.g., code_reviewer">
                    <div class="form-text">The tool name other agents will use to invoke this agent</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Save MCP Settings
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hooks Tab -->
    <?php if ($activeTab === 'hooks'): ?>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="/agents/update">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <input type="hidden" name="id" value="<?= $agent->id ?>">
                <input type="hidden" name="tab" value="hooks">

                <div class="mb-3">
                    <label for="hooks" class="form-label">Hooks Configuration (JSON)</label>
                    <textarea class="form-control font-monospace" id="hooks" name="hooks" rows="12"><?= htmlspecialchars(json_encode($hooks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
                    <div class="form-text">
                        Hook definitions that run at specific lifecycle events. Format:
                        <code>{"pre_message": ["command1"], "post_message": ["command2"]}</code>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Save Hooks
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Capabilities Tab -->
    <?php if ($activeTab === 'capabilities'): ?>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="/agents/update">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <input type="hidden" name="id" value="<?= $agent->id ?>">
                <input type="hidden" name="tab" value="capabilities">

                <p class="text-muted">Select the capabilities this agent has. These are used for task matching and routing.</p>

                <?php
                $allCapabilities = [
                    'code_generation' => 'Code Generation',
                    'code_review' => 'Code Review',
                    'debugging' => 'Debugging',
                    'documentation' => 'Documentation',
                    'testing' => 'Testing',
                    'refactoring' => 'Refactoring',
                    'architecture' => 'Architecture',
                    'mcp_tools' => 'MCP Tools',
                    'summarization' => 'Summarization',
                    'function_calling' => 'Function Calling',
                    'web_search' => 'Web Search',
                    'file_operations' => 'File Operations',
                ];
                ?>

                <div class="row row-cols-2 row-cols-md-3 g-3 mb-4">
                    <?php foreach ($allCapabilities as $key => $label): ?>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input capability-check" type="checkbox"
                                       id="cap_<?= $key ?>" value="<?= htmlspecialchars($key) ?>"
                                       <?= in_array($key, $capabilities) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="cap_<?= $key ?>">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Hidden field populated by JS -->
                <input type="hidden" name="capabilities" id="capabilities_json"
                       value="<?= htmlspecialchars(json_encode($capabilities)) ?>">

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Save Capabilities
                </button>
            </form>
        </div>
    </div>

    <script>
    // Sync checkboxes to hidden JSON field before submit
    document.querySelector('form')?.addEventListener('submit', function() {
        const checked = [];
        document.querySelectorAll('.capability-check:checked').forEach(cb => {
            checked.push(cb.value);
        });
        document.getElementById('capabilities_json').value = JSON.stringify(checked);
    });
    </script>
    <?php endif; ?>

    <!-- Workstation Tab -->
    <?php if ($activeTab === 'workstation'): ?>
    <?php
        $runners = \app\Bean::find('runner', 'is_active = 1 ORDER BY name ASC');
        $currentRunnerId = (int)($agent->runnerId ?? 0);
    ?>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="/agents/update">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <input type="hidden" name="id" value="<?= $agent->id ?>">
                <input type="hidden" name="tab" value="workstation">

                <p class="text-muted">Assign this agent to a remote workstation where it will execute tasks via SSH+tmux.</p>

                <div class="mb-3">
                    <label for="runner_id" class="form-label">Assigned Workstation</label>
                    <select class="form-select" id="runner_id" name="runner_id">
                        <option value="">Local (this machine)</option>
                        <?php foreach ($runners as $r): ?>
                            <option value="<?= $r->id ?>" <?= $currentRunnerId === (int)$r->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r->name) ?>
                                (<?= htmlspecialchars($r->sshUser ?: 'claudeuser') ?>@<?= htmlspecialchars($r->host) ?>)
                                <?php if ($r->healthStatus === 'healthy'): ?> ✓<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        Leave empty to run on the local machine. <a href="/workstations">Manage workstations</a>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="default_work_dir" class="form-label">Default Working Directory</label>
                    <input type="text" class="form-control font-monospace" id="default_work_dir" name="default_work_dir"
                           value="<?= htmlspecialchars($agent->defaultWorkDir ?? '') ?>"
                           placeholder="~/jobs/{job_uid}">
                    <div class="form-text">
                        The directory on the workstation where this agent will operate.
                        Use <code>{job_uid}</code> as a placeholder for the unique job ID.
                        Leave empty for the default <code>~/jobs/{job_uid}</code>.
                    </div>
                </div>

                <?php if (empty($runners)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        No workstations configured yet.
                        <a href="/workstations/create" class="alert-link">Add a workstation</a> to run agents on remote machines.
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Save Workstation Settings
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Agent Info Card -->
    <div class="card mt-4">
        <div class="card-body">
            <div class="row text-muted small">
                <div class="col-md-4">
                    <strong>Slug:</strong> <?= htmlspecialchars($agent->slug) ?>
                </div>
                <div class="col-md-4">
                    <strong>Created:</strong> <?= htmlspecialchars($agent->createdAt ?? 'N/A') ?>
                </div>
                <div class="col-md-4">
                    <strong>Updated:</strong> <?= htmlspecialchars($agent->updatedAt ?? 'N/A') ?>
                </div>
            </div>
            <?php if ($agent->memberId): ?>
                <div class="mt-2 text-muted small">
                    <strong>Linked Member ID:</strong> <?= (int)$agent->memberId ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Deactivate Agent</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to deactivate <strong><?= htmlspecialchars($agent->name) ?></strong>?</p>
                <p class="text-muted small">The agent and its linked bot account will be deactivated but not permanently deleted.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/agents/delete" class="d-inline">
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="id" value="<?= $agent->id ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash"></i> Deactivate
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
