<?php
$isEdit = !empty($server);
$type = $server['type'] ?? 'stdio';
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="mb-4">
                <a href="/mcpconfig" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to MCP Servers
                </a>
            </div>

            <?php
            $flash = $_SESSION['flash'] ?? [];
            unset($_SESSION['flash']);
            foreach ($flash as $msg):
            ?>
                <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?>">
                    <?= htmlspecialchars($msg['message']) ?>
                </div>
            <?php endforeach; ?>

            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0"><?= $isEdit ? 'Edit' : 'Add' ?> MCP Server</h4>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= $isEdit ? '/mcpconfig/update' : '/mcpconfig/store' ?>">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>

                        <!-- Server Name (Slug) -->
                        <div class="mb-3">
                            <label for="slug" class="form-label">Server Name <span class="text-danger">*</span></label>
                            <?php if ($isEdit): ?>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($slug) ?>" disabled>
                                <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                <div class="form-text">Server name cannot be changed after creation.</div>
                            <?php else: ?>
                                <input type="text" class="form-control" id="slug" name="slug" required
                                       pattern="[a-z0-9][a-z0-9-]*"
                                       placeholder="my-mcp-server"
                                       value="<?= htmlspecialchars($slug) ?>">
                                <div class="form-text">Lowercase alphanumeric with dashes (e.g., my-mcp-server)</div>
                            <?php endif; ?>
                        </div>

                        <!-- Server Type -->
                        <div class="mb-3">
                            <label for="type" class="form-label">Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="type" name="type" onchange="toggleTypeFields()">
                                <option value="stdio" <?= $type === 'stdio' ? 'selected' : '' ?>>STDIO (Local Process)</option>
                                <option value="http" <?= $type === 'http' ? 'selected' : '' ?>>HTTP (Remote Server)</option>
                            </select>
                            <div class="form-text">
                                <strong>STDIO:</strong> Spawns a local process that communicates via stdin/stdout.<br>
                                <strong>HTTP:</strong> Connects to a remote MCP server via HTTP.
                            </div>
                        </div>

                        <!-- STDIO Fields -->
                        <div id="stdioFields" class="<?= $type !== 'stdio' ? 'd-none' : '' ?>">
                            <div class="mb-3">
                                <label for="command" class="form-label">Command <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace" id="command" name="command"
                                       placeholder="npx"
                                       value="<?= htmlspecialchars($server['command'] ?? '') ?>">
                                <div class="form-text">The executable to run (e.g., npx, node, python)</div>
                            </div>

                            <div class="mb-3">
                                <label for="args" class="form-label">Arguments (JSON Array)</label>
                                <textarea class="form-control font-monospace" id="args" name="args" rows="3"
                                          placeholder='["-y", "@modelcontextprotocol/server-filesystem", "/path/to/dir"]'><?= htmlspecialchars(
                                    !empty($server['args']) ? json_encode($server['args'], JSON_PRETTY_PRINT) : ''
                                ) ?></textarea>
                                <div class="form-text">Command line arguments as JSON array</div>
                            </div>

                            <div class="mb-3">
                                <label for="env" class="form-label">Environment Variables (JSON Object)</label>
                                <textarea class="form-control font-monospace" id="env" name="env" rows="3"
                                          placeholder='{"API_KEY": "your-key", "DEBUG": "1"}'><?= htmlspecialchars(
                                    !empty($server['env']) ? json_encode($server['env'], JSON_PRETTY_PRINT) : ''
                                ) ?></textarea>
                                <div class="form-text">Environment variables as JSON object (optional)</div>
                            </div>
                        </div>

                        <!-- HTTP Fields -->
                        <div id="httpFields" class="<?= $type !== 'http' ? 'd-none' : '' ?>">
                            <div class="mb-3">
                                <label for="url" class="form-label">URL <span class="text-danger">*</span></label>
                                <input type="url" class="form-control font-monospace" id="url" name="url"
                                       placeholder="https://example.com/mcp/message"
                                       value="<?= htmlspecialchars($server['url'] ?? '') ?>">
                                <div class="form-text">The MCP server endpoint URL</div>
                            </div>

                            <div class="mb-3">
                                <label for="headers" class="form-label">Headers (JSON Object)</label>
                                <textarea class="form-control font-monospace" id="headers" name="headers" rows="3"
                                          placeholder='{"Authorization": "Bearer your-token"}'><?= htmlspecialchars(
                                    !empty($server['headers']) ? json_encode($server['headers'], JSON_PRETTY_PRINT) : ''
                                ) ?></textarea>
                                <div class="form-text">HTTP headers as JSON object (optional)</div>
                            </div>
                        </div>

                        <!-- Examples -->
                        <div class="alert alert-light mt-4">
                            <h6 class="alert-heading"><i class="bi bi-lightbulb me-1"></i> Examples</h6>
                            <div class="row small">
                                <div class="col-md-6">
                                    <strong>STDIO - Filesystem Server:</strong>
                                    <pre class="bg-white p-2 mt-1 mb-0">Command: npx
Args: ["-y", "@modelcontextprotocol/server-filesystem", "/home/user/docs"]</pre>
                                </div>
                                <div class="col-md-6">
                                    <strong>HTTP - Remote Server:</strong>
                                    <pre class="bg-white p-2 mt-1 mb-0">URL: https://api.example.com/mcp
Headers: {"X-API-Key": "secret"}</pre>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save Changes' : 'Add Server' ?>
                            </button>
                            <a href="/mcpconfig" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleTypeFields() {
    const type = document.getElementById('type').value;
    document.getElementById('stdioFields').classList.toggle('d-none', type !== 'stdio');
    document.getElementById('httpFields').classList.toggle('d-none', type !== 'http');
}
</script>
