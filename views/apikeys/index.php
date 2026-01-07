<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">API Keys</h1>
        <div>
            <a href="/apikeys/add" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Create New Key
            </a>
        </div>
    </div>

    <?php if (!empty($_SESSION['new_api_key'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <h5 class="alert-heading"><i class="bi bi-check-circle"></i> API Key Created!</h5>
            <p class="mb-2">Your new API key <strong><?= htmlspecialchars($_SESSION['new_api_key_name']) ?></strong> has been created. Copy it now - it won't be shown again!</p>
            <div class="input-group mb-2" style="max-width: 600px;">
                <input type="text" class="form-control font-monospace bg-light" id="newToken"
                       value="<?= htmlspecialchars($_SESSION['new_api_key']) ?>" readonly>
                <button class="btn btn-outline-secondary" type="button" onclick="copyToken('newToken')">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['new_api_key'], $_SESSION['new_api_key_name']); ?>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Your API Keys</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($keys)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-key" style="font-size: 3rem;"></i>
                    <p class="mt-3">You don't have any API keys yet.</p>
                    <a href="/apikeys/add" class="btn btn-primary">Create Your First Key</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Token</th>
                                <th>Scopes</th>
                                <th>Servers</th>
                                <th>Expires</th>
                                <th>Last Used</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keys as $key): ?>
                                <?php
                                $scopes = json_decode($key->scopes, true) ?: [];
                                $allowedServers = json_decode($key->allowedServers, true) ?: [];
                                $isExpired = $key->expiresAt && strtotime($key->expiresAt) < time();
                                ?>
                                <tr class="<?= (!$key->isActive || $isExpired) ? 'table-secondary' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($key->name) ?></strong>
                                        <br><small class="text-muted">Created <?= date('M j, Y', strtotime($key->createdAt)) ?></small>
                                    </td>
                                    <td>
                                        <code class="text-muted"><?= substr($key->token, 0, 12) ?>...</code>
                                        <button class="btn btn-sm btn-link p-0 ms-1" onclick="regenerateToken(<?= $key->id ?>, '<?= htmlspecialchars($key->name, ENT_QUOTES) ?>')" title="Regenerate token">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <?php if (empty($scopes)): ?>
                                            <span class="badge bg-warning">No scopes</span>
                                        <?php elseif (in_array('mcp:*', $scopes)): ?>
                                            <span class="badge bg-success">Full Access</span>
                                        <?php else: ?>
                                            <?php foreach ($scopes as $scope): ?>
                                                <span class="badge bg-info"><?= htmlspecialchars($scope) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (empty($allowedServers)): ?>
                                            <span class="text-muted">All servers</span>
                                        <?php else: ?>
                                            <?php foreach ($allowedServers as $slug): ?>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($slug) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$key->expiresAt): ?>
                                            <span class="text-muted">Never</span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Expired</span>
                                        <?php else: ?>
                                            <?= date('M j, Y', strtotime($key->expiresAt)) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($key->lastUsedAt): ?>
                                            <span title="<?= htmlspecialchars($key->lastUsedAt) ?>">
                                                <?= date('M j, Y', strtotime($key->lastUsedAt)) ?>
                                            </span>
                                            <br><small class="text-muted"><?= $key->usageCount ?? 0 ?> uses</small>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isExpired): ?>
                                            <span class="badge bg-danger">Expired</span>
                                        <?php elseif ($key->isActive): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-success"
                                                onclick="showUseKeyModal('<?= htmlspecialchars($key->token, ENT_QUOTES) ?>', '<?= htmlspecialchars($key->name, ENT_QUOTES) ?>')">
                                            <i class="bi bi-plug"></i> Use
                                        </button>
                                        <a href="/apikeys/edit?id=<?= $key->id ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <a href="/apikeys/delete?id=<?= $key->id ?>"
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Delete this API key? This cannot be undone.')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Usage Instructions -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-terminal"></i> Claude Code Setup</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-success mb-4">
                <i class="bi bi-lightning-charge"></i> <strong>Quick Setup:</strong>
                Click the <span class="badge bg-success"><i class="bi bi-plug"></i> Use</span> button on any API key above to get the ready-to-use CLI command or JSON config.
            </div>

            <!-- Available Tools -->
            <h6><i class="bi bi-tools"></i> Available Tools</h6>
            <p class="small text-muted">Once connected, Claude Code can use these built-in tools:</p>
            <div class="row">
                <div class="col-md-4">
                    <p class="small mb-1"><strong>Basic</strong></p>
                    <ul class="small text-muted mb-2">
                        <li><code>tiknix:hello</code> - Test connection</li>
                        <li><code>tiknix:echo</code> - Echo messages</li>
                        <li><code>tiknix:get_time</code> - Server time</li>
                        <li><code>tiknix:add_numbers</code> - Math test</li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <p class="small mb-1"><strong>Validation</strong></p>
                    <ul class="small text-muted mb-2">
                        <li><code>tiknix:validate_php</code> - PHP syntax check</li>
                        <li><code>tiknix:security_scan</code> - OWASP scan</li>
                        <li><code>tiknix:check_redbean</code> - RedBean check</li>
                        <li><code>tiknix:check_flightphp</code> - Flight check</li>
                        <li><code>tiknix:full_validation</code> - All validators</li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <p class="small mb-1"><strong>System</strong></p>
                    <ul class="small text-muted mb-2">
                        <li><code>tiknix:list_users</code> - List users</li>
                        <li><code>tiknix:list_mcp_servers</code> - List servers</li>
                        <li><code>tiknix:list_tasks</code> - Workbench tasks</li>
                        <li><code>tiknix:get_task</code> - Task details</li>
                        <li><code>tiknix:update_task</code> - Update task</li>
                    </ul>
                </div>
            </div>
            <p class="small text-muted">Plus all tools from registered MCP servers (e.g., <code>playwright-mcp:browser_*</code>)</p>

            <hr>

            <!-- Verify Setup -->
            <h6><i class="bi bi-check-circle"></i> Verify Setup</h6>
            <p class="small text-muted">Check that Claude Code is connected:</p>
            <pre class="bg-dark text-light p-3 rounded small"><code># Check MCP server status
claude mcp list

# Should show:
# tiknix: <?= htmlspecialchars(Flight::get('baseurl') ?? 'https://your-domain.com') ?>/mcp/message (HTTP) - ✓ Connected</code></pre>

            <div class="alert alert-info mt-3 mb-0">
                <i class="bi bi-lightbulb"></i> <strong>Tips:</strong>
                <ul class="mb-0 small">
                    <li>Restart Claude Code after adding the MCP server to load tools</li>
                    <li>Create separate API keys for different projects</li>
                    <li>Restrict keys to specific MCP servers for better security</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Use Key Modal -->
<div class="modal fade" id="useKeyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plug"></i> Use API Key with Claude Code</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Add <strong id="useKeyName"></strong> to Claude Code:</p>

                <!-- CLI Command -->
                <div class="mb-4">
                    <h6><i class="bi bi-terminal"></i> Option 1: CLI Command</h6>
                    <p class="small text-muted">Run this in your terminal, then restart Claude Code:</p>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace bg-dark text-light small" id="useKeyCli" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('useKeyCli')">
                            <i class="bi bi-clipboard"></i> Copy
                        </button>
                    </div>
                </div>

                <!-- JSON Config -->
                <div class="mb-3">
                    <h6><i class="bi bi-filetype-json"></i> Option 2: Add to settings.json</h6>
                    <p class="small text-muted">Add this to <code>~/.claude/settings.json</code>:</p>
                    <div class="position-relative">
                        <pre class="bg-dark text-light p-3 rounded small mb-0" id="useKeyJson" style="white-space: pre-wrap;"></pre>
                        <button class="btn btn-sm btn-outline-light position-absolute top-0 end-0 m-2" onclick="copyToClipboard('useKeyJson')">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>

                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Important:</strong> Restart Claude Code after adding the MCP server to load the tools.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Regenerate Token Modal -->
<div class="modal fade" id="regenerateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Regenerate API Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="regenerateConfirm">
                    <p>Are you sure you want to regenerate the token for <strong id="regenerateKeyName"></strong>?</p>
                    <p class="text-warning"><i class="bi bi-exclamation-triangle"></i> The old token will stop working immediately.</p>
                </div>
                <div id="regenerateResult" style="display: none;">
                    <p class="text-success"><i class="bi bi-check-circle"></i> Token regenerated! Copy it now:</p>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" id="regeneratedToken" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToken('regeneratedToken')">
                            <i class="bi bi-clipboard"></i> Copy
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="regenerateBtn" onclick="confirmRegenerate()">
                    Regenerate Token
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentRegenerateId = null;
let regenerateModal = null;
let useKeyModal = null;

const mcpBaseUrl = '<?= htmlspecialchars(Flight::get('baseurl') ?? 'https://your-domain.com') ?>/mcp/message';

// Initialize modals when DOM and bootstrap are ready
document.addEventListener('DOMContentLoaded', function() {
    if (typeof bootstrap !== 'undefined') {
        regenerateModal = new bootstrap.Modal(document.getElementById('regenerateModal'));
        useKeyModal = new bootstrap.Modal(document.getElementById('useKeyModal'));
    }
});

function showUseKeyModal(token, keyName) {
    // Lazy initialize modal if not already done
    if (!useKeyModal && typeof bootstrap !== 'undefined') {
        useKeyModal = new bootstrap.Modal(document.getElementById('useKeyModal'));
    }

    // Set the key name
    document.getElementById('useKeyName').textContent = keyName;

    // Generate CLI command
    const cliCommand = `claude mcp add --transport http tiknix ${mcpBaseUrl} --header "Authorization: Bearer ${token}"`;
    document.getElementById('useKeyCli').value = cliCommand;

    // Generate JSON config
    const jsonConfig = {
        mcpServers: {
            tiknix: {
                type: "http",
                url: mcpBaseUrl,
                headers: {
                    Authorization: `Bearer ${token}`
                }
            }
        }
    };
    document.getElementById('useKeyJson').textContent = JSON.stringify(jsonConfig, null, 2);

    if (useKeyModal) {
        useKeyModal.show();
    }
}

function copyToClipboard(elementId) {
    const element = document.getElementById(elementId);
    const text = element.value || element.textContent;

    navigator.clipboard.writeText(text).then(() => {
        // Find the copy button
        const btn = element.tagName === 'INPUT'
            ? element.nextElementSibling
            : element.parentElement.querySelector('button');

        if (btn) {
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
            btn.classList.remove('btn-outline-secondary', 'btn-outline-light');
            btn.classList.add('btn-success');

            setTimeout(() => {
                btn.innerHTML = originalHtml;
                btn.classList.remove('btn-success');
                btn.classList.add(element.tagName === 'INPUT' ? 'btn-outline-secondary' : 'btn-outline-light');
            }, 2000);
        }
    });
}

function copyToken(inputId) {
    const input = document.getElementById(inputId);
    input.select();
    document.execCommand('copy');

    // Show feedback
    const btn = input.nextElementSibling;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-success');

    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-secondary');
    }, 2000);
}

function regenerateToken(keyId, keyName) {
    currentRegenerateId = keyId;
    document.getElementById('regenerateKeyName').textContent = keyName;
    document.getElementById('regenerateConfirm').style.display = 'block';
    document.getElementById('regenerateResult').style.display = 'none';
    document.getElementById('regenerateBtn').style.display = 'block';

    // Lazy initialize modal if not already done
    if (!regenerateModal && typeof bootstrap !== 'undefined') {
        regenerateModal = new bootstrap.Modal(document.getElementById('regenerateModal'));
    }
    if (regenerateModal) {
        regenerateModal.show();
    }
}

async function confirmRegenerate() {
    if (!currentRegenerateId) return;

    const btn = document.getElementById('regenerateBtn');
    btn.disabled = true;
    btn.textContent = 'Regenerating...';

    try {
        const formData = new FormData();
        formData.append('id', currentRegenerateId);

        // Get CSRF token from the page
        const csrfInputs = document.querySelectorAll('input[name^="csrf"]');
        csrfInputs.forEach(input => formData.append(input.name, input.value));

        const response = await fetch('/apikeys/regenerate', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            document.getElementById('regenerateConfirm').style.display = 'none';
            document.getElementById('regenerateResult').style.display = 'block';
            document.getElementById('regeneratedToken').value = data.token;
            btn.style.display = 'none';
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error regenerating token: ' + e.message);
    }

    btn.disabled = false;
    btn.textContent = 'Regenerate Token';
}
</script>
