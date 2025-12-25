<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?= htmlspecialchars($title) ?></h4>
                    <a href="/apikeys" class="btn btn-outline-secondary btn-sm">Back to Keys</a>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Basic Info -->
                        <h5 class="border-bottom pb-2 mb-3">Key Information</h5>

                        <div class="mb-3">
                            <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?= htmlspecialchars($key->name ?? '') ?>" required
                                   placeholder="e.g., Claude Code - Main Project">
                            <div class="form-text">A memorable name to identify this key</div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="isActive" name="isActive" value="1"
                                       <?= ($key->isActive ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isActive">Key is active</label>
                            </div>
                            <div class="form-text">Inactive keys cannot be used for authentication</div>
                        </div>

                        <!-- Scopes -->
                        <h5 class="border-bottom pb-2 mb-3 mt-4">Access Scopes</h5>

                        <div class="mb-3">
                            <?php
                            $currentScopes = json_decode($key->scopes ?? '[]', true) ?: [];
                            ?>
                            <?php foreach ($availableScopes as $scope => $description): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="scopes[]"
                                           id="scope_<?= htmlspecialchars($scope) ?>"
                                           value="<?= htmlspecialchars($scope) ?>"
                                           <?= in_array($scope, $currentScopes) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="scope_<?= htmlspecialchars($scope) ?>">
                                        <code><?= htmlspecialchars($scope) ?></code> - <?= htmlspecialchars($description) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div class="form-text">Select which capabilities this key should have</div>
                        </div>

                        <!-- Server Restrictions -->
                        <h5 class="border-bottom pb-2 mb-3 mt-4">Server Restrictions</h5>

                        <p class="text-muted small">Optionally restrict this key to specific MCP servers. Leave all unchecked to allow access to all servers.</p>

                        <div class="mb-3">
                            <?php
                            $currentServers = json_decode($key->allowedServers ?? '[]', true) ?: [];
                            ?>
                            <?php if (empty($mcpServers)): ?>
                                <div class="alert alert-info">
                                    No MCP servers registered yet. <a href="/mcp/registry/add">Add a server</a> to restrict access.
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($mcpServers as $server): ?>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="allowedServers[]"
                                                       id="server_<?= htmlspecialchars($server->slug) ?>"
                                                       value="<?= htmlspecialchars($server->slug) ?>"
                                                       <?= in_array($server->slug, $currentServers) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="server_<?= htmlspecialchars($server->slug) ?>">
                                                    <strong><?= htmlspecialchars($server->name) ?></strong>
                                                    <br><small class="text-muted"><?= htmlspecialchars($server->slug) ?></small>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Expiration -->
                        <h5 class="border-bottom pb-2 mb-3 mt-4">Expiration</h5>

                        <div class="mb-3">
                            <label for="expiresIn" class="form-label">Key Expiration</label>
                            <?php
                            $hasExpiry = !empty($key->expiresAt);
                            ?>
                            <select class="form-select" id="expiresIn" name="expiresIn" onchange="toggleCustomDate()">
                                <option value="never" <?= !$hasExpiry ? 'selected' : '' ?>>Never expires</option>
                                <option value="7d">7 days</option>
                                <option value="30d">30 days</option>
                                <option value="90d">90 days</option>
                                <option value="1y">1 year</option>
                                <option value="custom" <?= $hasExpiry ? 'selected' : '' ?>>Custom date</option>
                            </select>
                        </div>

                        <div class="mb-3" id="customDateRow" style="<?= $hasExpiry ? '' : 'display: none;' ?>">
                            <label for="expiresAtCustom" class="form-label">Expiration Date</label>
                            <input type="date" class="form-control" id="expiresAtCustom" name="expiresAtCustom"
                                   value="<?= $hasExpiry ? date('Y-m-d', strtotime($key->expiresAt)) : '' ?>"
                                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        </div>

                        <?php if (isset($key->id)): ?>
                            <!-- Show existing key info -->
                            <h5 class="border-bottom pb-2 mb-3 mt-4">Key Details</h5>
                            <div class="row mb-3">
                                <div class="col-sm-4"><strong>Token:</strong></div>
                                <div class="col-sm-8">
                                    <code><?= substr($key->token, 0, 12) ?>...</code>
                                    <small class="text-muted">(hidden for security)</small>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-sm-4"><strong>Created:</strong></div>
                                <div class="col-sm-8"><?= htmlspecialchars($key->createdAt) ?></div>
                            </div>
                            <?php if ($key->lastUsedAt): ?>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Last Used:</strong></div>
                                    <div class="col-sm-8">
                                        <?= htmlspecialchars($key->lastUsedAt) ?>
                                        <?php if ($key->lastUsedIp): ?>
                                            <small class="text-muted">from <?= htmlspecialchars($key->lastUsedIp) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Usage Count:</strong></div>
                                    <div class="col-sm-8"><?= $key->usageCount ?? 0 ?> times</div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between mt-4">
                            <a href="/apikeys" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <?= isset($key->id) ? 'Update Key' : 'Create Key' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Help Card -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> About API Keys</h6>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li><strong>Scopes</strong> control what the key can do (e.g., full access vs read-only)</li>
                        <li><strong>Server restrictions</strong> limit which MCP servers the key can access</li>
                        <li><strong>Expiration</strong> automatically disables the key after a set time</li>
                        <li>You can create multiple keys for different projects or purposes</li>
                        <li>Regenerate a key's token if you think it may have been compromised</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleCustomDate() {
    const select = document.getElementById('expiresIn');
    const customRow = document.getElementById('customDateRow');
    customRow.style.display = select.value === 'custom' ? 'block' : 'none';
}
</script>
