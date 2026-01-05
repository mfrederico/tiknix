<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-shield-lock me-2"></i><?= htmlspecialchars($title) ?></h2>
            <p class="text-muted mb-0">
                <?= $rule ? 'Edit existing security rule' : 'Create a new security sandbox rule' ?>
            </p>
        </div>
        <a href="/security" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Rules
        </a>
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

    <div class="card">
        <div class="card-body">
            <form method="POST" action="/security/store">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>

                <?php if ($rule): ?>
                    <input type="hidden" name="id" value="<?= $rule->id ?>">
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="name" class="form-label">Rule Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?= htmlspecialchars($rule->name ?? '') ?>" required>
                            <div class="form-text">A descriptive name for this rule (e.g., "Block /etc access")</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="target" class="form-label">Target Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="target" name="target" required>
                                <option value="path" <?= ($rule->target ?? 'path') === 'path' ? 'selected' : '' ?>>Path</option>
                                <option value="command" <?= ($rule->target ?? '') === 'command' ? 'selected' : '' ?>>Command</option>
                            </select>
                            <div class="form-text">What this rule applies to</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="action" class="form-label">Action <span class="text-danger">*</span></label>
                            <select class="form-select" id="action" name="action" required>
                                <option value="block" <?= ($rule->action ?? 'block') === 'block' ? 'selected' : '' ?>>Block</option>
                                <option value="allow" <?= ($rule->action ?? '') === 'allow' ? 'selected' : '' ?>>Allow</option>
                                <option value="protect" <?= ($rule->action ?? '') === 'protect' ? 'selected' : '' ?>>Protect</option>
                            </select>
                            <div class="form-text">
                                <span class="text-danger">Block</span> = deny access |
                                <span class="text-success">Allow</span> = permit access |
                                <span class="text-warning">Protect</span> = read OK, write needs level
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="pattern" class="form-label">Pattern <span class="text-danger">*</span></label>
                    <input type="text" class="form-control font-monospace" id="pattern" name="pattern"
                           value="<?= htmlspecialchars($rule->pattern ?? '') ?>" required>
                    <div class="form-text">
                        <strong>Simple match:</strong> <code>/etc</code> matches any path containing "/etc"<br>
                        <strong>Regex:</strong> Use delimiters like <code>/pattern/</code> or <code>#pattern#</code><br>
                        <strong>Examples:</strong>
                        <code>/\.env$/</code> (files ending in .env),
                        <code>#^/home/(?!allowed)#</code> (negative lookahead),
                        <code>/\brm\s+-rf/</code> (rm -rf command)
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="level" class="form-label">Member Level</label>
                            <select class="form-select" id="level" name="level">
                                <option value="" <?= ($rule->level ?? '') === null ? 'selected' : '' ?>>All Levels (no bypass)</option>
                                <option value="1" <?= ($rule->level ?? '') === '1' || ($rule->level ?? 0) === 1 ? 'selected' : '' ?>>ROOT (1) - Only ROOT can bypass</option>
                                <option value="50" <?= ($rule->level ?? '') === '50' || ($rule->level ?? 0) === 50 ? 'selected' : '' ?>>ADMIN (50) - ADMIN+ can bypass</option>
                                <option value="100" <?= ($rule->level ?? '') === '100' || ($rule->level ?? 0) === 100 ? 'selected' : '' ?>>MEMBER (100) - All logged in can bypass</option>
                            </select>
                            <div class="form-text">
                                For <strong>block</strong>: Users with level &le; this value can bypass the block<br>
                                For <strong>allow</strong>: Users with level &le; this value are allowed<br>
                                For <strong>protect</strong>: Users with level &le; this value can write
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="priority" class="form-label">Priority</label>
                            <input type="number" class="form-control" id="priority" name="priority"
                                   value="<?= htmlspecialchars($rule->priority ?? '100') ?>" min="1" max="999">
                            <div class="form-text">Lower number = higher priority. Rules are checked in priority order.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                       <?= ($rule->isActive ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                            <div class="form-text">Inactive rules are ignored</div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($rule->description ?? '') ?></textarea>
                    <div class="form-text">Optional explanation shown when rule blocks an action</div>
                </div>

                <hr>

                <div class="d-flex justify-content-between">
                    <a href="/security" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>
                        <?= $rule ? 'Update Rule' : 'Create Rule' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Help Card -->
    <div class="card mt-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Pattern Help</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Path Patterns</h6>
                    <table class="table table-sm">
                        <tr><td><code>/etc</code></td><td>Block anything containing /etc</td></tr>
                        <tr><td><code>/.ssh</code></td><td>Block SSH directories</td></tr>
                        <tr><td><code>/\.env$/</code></td><td>Regex: files ending in .env</td></tr>
                        <tr><td><code>#^/home/(?!admin)#</code></td><td>Regex: /home/ except admin</td></tr>
                        <tr><td><code>scripts/hooks</code></td><td>Protect hooks directory</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>Command Patterns</h6>
                    <table class="table table-sm">
                        <tr><td><code>/\brm\s+-rf/</code></td><td>Block rm -rf</td></tr>
                        <tr><td><code>/\bsudo\s+/</code></td><td>Block sudo commands</td></tr>
                        <tr><td><code>/DROP\s+DATABASE/i</code></td><td>Block DROP DATABASE (case-insensitive)</td></tr>
                        <tr><td><code>/\bcurl.*\|\s*sh/</code></td><td>Block curl piped to shell</td></tr>
                        <tr><td><code>/^git\s/</code></td><td>Allow git commands</td></tr>
                    </table>
                </div>
            </div>
            <div class="alert alert-info mb-0 mt-3">
                <strong>Regex Delimiters:</strong> Use <code>/</code>, <code>#</code>, <code>~</code>, or <code>@</code> as delimiters.
                The pattern must start and end with the same delimiter (e.g., <code>/pattern/</code> or <code>#pattern#</code>).
            </div>
        </div>
    </div>
</div>
