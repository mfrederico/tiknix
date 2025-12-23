<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?= htmlspecialchars($title) ?></h4>
                    <a href="/mcpregistry" class="btn btn-outline-secondary btn-sm">Back to List</a>
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

                        <h5 class="border-bottom pb-2 mb-3">Basic Information</h5>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?= htmlspecialchars($server->name ?? '') ?>" required
                                       minlength="2" maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label for="slug" class="form-label">Slug</label>
                                <input type="text" class="form-control" id="slug" name="slug"
                                       value="<?= htmlspecialchars($server->slug ?? '') ?>"
                                       pattern="[a-z0-9-]+" placeholder="auto-generated-from-name">
                                <small class="form-text text-muted">Lowercase letters, numbers, and hyphens only. Auto-generated if blank.</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($server->description ?? '') ?></textarea>
                        </div>

                        <h5 class="border-bottom pb-2 mb-3 mt-4">Connection</h5>
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label for="endpointUrl" class="form-label">Endpoint URL <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="url" class="form-control" id="endpointUrl" name="endpointUrl"
                                           value="<?= htmlspecialchars($server->endpointUrl ?? '') ?>" required
                                           placeholder="https://example.com/mcp/message">
                                    <button type="button" class="btn btn-outline-secondary" id="fetchToolsBtn">
                                        Fetch Tools
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="version" class="form-label">Version</label>
                                <input type="text" class="form-control" id="version" name="version"
                                       value="<?= htmlspecialchars($server->version ?? '1.0.0') ?>"
                                       placeholder="1.0.0">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="authType" class="form-label">Authentication Type</label>
                                <select class="form-select" id="authType" name="authType">
                                    <option value="none" <?= ($server->authType ?? 'none') === 'none' ? 'selected' : '' ?>>None</option>
                                    <option value="basic" <?= ($server->authType ?? '') === 'basic' ? 'selected' : '' ?>>Basic Auth</option>
                                    <option value="bearer" <?= ($server->authType ?? '') === 'bearer' ? 'selected' : '' ?>>Bearer Token</option>
                                    <option value="apikey" <?= ($server->authType ?? '') === 'apikey' ? 'selected' : '' ?>>API Key</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?= ($server->status ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($server->status ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="deprecated" <?= ($server->status ?? '') === 'deprecated' ? 'selected' : '' ?>>Deprecated</option>
                                </select>
                            </div>
                        </div>

                        <h5 class="border-bottom pb-2 mb-3 mt-4">Author Information</h5>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="author" class="form-label">Author/Organization</label>
                                <input type="text" class="form-control" id="author" name="author"
                                       value="<?= htmlspecialchars($server->author ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="authorUrl" class="form-label">Author URL</label>
                                <input type="url" class="form-control" id="authorUrl" name="authorUrl"
                                       value="<?= htmlspecialchars($server->authorUrl ?? '') ?>"
                                       placeholder="https://example.com">
                            </div>
                        </div>

                        <h5 class="border-bottom pb-2 mb-3 mt-4">Tools</h5>
                        <div class="mb-3">
                            <label for="tools" class="form-label">Tool Definitions (JSON)</label>
                            <textarea class="form-control font-monospace" id="tools" name="tools" rows="10"><?= htmlspecialchars($server->tools ?? '[]') ?></textarea>
                            <small class="form-text text-muted">Click "Fetch Tools" to auto-populate from the endpoint. Format: array of {name, description, inputSchema}</small>
                        </div>

                        <h5 class="border-bottom pb-2 mb-3 mt-4">Additional</h5>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="documentationUrl" class="form-label">Documentation URL</label>
                                <input type="url" class="form-control" id="documentationUrl" name="documentationUrl"
                                       value="<?= htmlspecialchars($server->documentationUrl ?? '') ?>"
                                       placeholder="https://docs.example.com">
                            </div>
                            <div class="col-md-6">
                                <label for="iconUrl" class="form-label">Icon URL</label>
                                <input type="url" class="form-control" id="iconUrl" name="iconUrl"
                                       value="<?= htmlspecialchars($server->iconUrl ?? '') ?>"
                                       placeholder="https://example.com/icon.png">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="tags" class="form-label">Tags (JSON array)</label>
                                <input type="text" class="form-control" id="tags" name="tags"
                                       value="<?= htmlspecialchars($server->tags ?? '[]') ?>"
                                       placeholder='["ai", "tools", "database"]'>
                                <small class="form-text text-muted">JSON array of strings, e.g., ["ai", "tools"]</small>
                            </div>
                            <div class="col-md-3">
                                <label for="sortOrder" class="form-label">Sort Order</label>
                                <input type="number" class="form-control" id="sortOrder" name="sortOrder"
                                       value="<?= htmlspecialchars($server->sortOrder ?? 0) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label d-block">Options</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="featured" name="featured" value="1"
                                           <?= ($server->featured ?? 0) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="featured">Featured server</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <a href="/mcpregistry" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <?= isset($server->id) ? 'Update Server' : 'Create Server' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('fetchToolsBtn').addEventListener('click', async function() {
    const url = document.getElementById('endpointUrl').value;
    if (!url) {
        alert('Please enter an endpoint URL first');
        return;
    }

    this.disabled = true;
    this.textContent = 'Fetching...';

    try {
        const response = await fetch('/mcpregistry/fetchTools?url=' + encodeURIComponent(url));
        const data = await response.json();

        if (data.success) {
            document.getElementById('tools').value = JSON.stringify(data.tools, null, 2);
            alert('Found ' + data.tools.length + ' tools');
        } else {
            alert('Error fetching tools: ' + data.error);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }

    this.disabled = false;
    this.textContent = 'Fetch Tools';
});

// Auto-generate slug from name
document.getElementById('name').addEventListener('blur', function() {
    const slugField = document.getElementById('slug');
    if (!slugField.value && this.value) {
        slugField.value = this.value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }
});

// Validate JSON fields before submit
document.querySelector('form').addEventListener('submit', function(e) {
    const toolsField = document.getElementById('tools');
    const tagsField = document.getElementById('tags');

    try {
        if (toolsField.value.trim()) {
            JSON.parse(toolsField.value);
        }
    } catch (err) {
        e.preventDefault();
        alert('Invalid JSON in Tools field: ' + err.message);
        toolsField.focus();
        return;
    }

    try {
        if (tagsField.value.trim()) {
            JSON.parse(tagsField.value);
        }
    } catch (err) {
        e.preventDefault();
        alert('Invalid JSON in Tags field: ' + err.message);
        tagsField.focus();
        return;
    }
});
</script>
