<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="mb-4">
                <a href="/agents" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to Agents
                </a>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0"><i class="bi bi-robot"></i> Create Agent</h4>
                </div>
                <div class="card-body">
                    <?php
                    $flash = $_SESSION['flash'] ?? [];
                    unset($_SESSION['flash']);
                    foreach ($flash as $msg):
                    ?>
                        <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?>">
                            <?= htmlspecialchars($msg['message']) ?>
                        </div>
                    <?php endforeach; ?>

                    <form method="POST" action="/agents/store">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>

                        <div class="mb-3">
                            <label for="name" class="form-label">Agent Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required
                                   minlength="2" maxlength="255" placeholder="e.g., Code Reviewer">
                            <div class="form-text">A descriptive name for this agent (2-255 characters)</div>
                        </div>

                        <div class="mb-3">
                            <label for="provider" class="form-label">Provider <span class="text-danger">*</span></label>
                            <select class="form-select" id="provider" name="provider" required>
                                <?php foreach ($providers as $p): ?>
                                    <option value="<?= htmlspecialchars($p) ?>" <?= $p === 'claude_cli' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $p))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">The AI provider this agent will use</div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"
                                      placeholder="What does this agent do?"></textarea>
                        </div>

                        <div class="mb-4">
                            <label for="system_prompt" class="form-label">System Prompt</label>
                            <textarea class="form-control font-monospace" id="system_prompt" name="system_prompt" rows="6"
                                      placeholder="Instructions for the agent's behavior..."></textarea>
                            <div class="form-text">The system prompt defines how the agent behaves. You can refine this later.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Create Agent
                            </button>
                            <a href="/agents" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-body">
                    <h6><i class="bi bi-info-circle"></i> About Agents</h6>
                    <ul class="mb-0 small text-muted">
                        <li>Agents are AI-powered team members that can run workbench tasks</li>
                        <li>Each agent gets a linked bot account for team membership</li>
                        <li>Configure provider settings, MCP servers, and capabilities after creation</li>
                        <li>Agents can be exposed as MCP tools for other agents to use</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
