<div class="container py-4">
    <h1 class="mb-4">MCP Server</h1>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Server Information</h5>
        </div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <tr>
                    <th style="width: 200px;">Server Name</th>
                    <td><code><?= htmlspecialchars($serverName) ?></code></td>
                </tr>
                <tr>
                    <th>Version</th>
                    <td><code><?= htmlspecialchars($serverVersion) ?></code></td>
                </tr>
                <tr>
                    <th>Protocol Version</th>
                    <td><code><?= htmlspecialchars($protocolVersion) ?></code></td>
                </tr>
                <tr>
                    <th>Endpoint</th>
                    <td><code>POST <?= htmlspecialchars($mcpUrl ?? '') ?></code></td>
                </tr>
                <tr>
                    <th>Health Check</th>
                    <td><code>GET <?= htmlspecialchars(dirname($mcpUrl ?? '')) ?>/health</code></td>
                </tr>
                <tr>
                    <th>Auto-Config</th>
                    <td><code>GET <?= htmlspecialchars(dirname($mcpUrl ?? '')) ?>/config</code></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Available Tools</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Tool</th>
                            <th>Description</th>
                            <th>Parameters</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tools as $name => $config): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($name) ?></code></td>
                            <td><?= htmlspecialchars($config['description']) ?></td>
                            <td>
                                <?php
                                $props = $config['inputSchema']['properties'] ?? [];
                                $required = $config['inputSchema']['required'] ?? [];
                                if (empty($props)):
                                ?>
                                    <span class="text-muted">None</span>
                                <?php else: ?>
                                    <ul class="list-unstyled mb-0">
                                    <?php foreach ($props as $propName => $propConfig): ?>
                                        <li>
                                            <code><?= htmlspecialchars($propName) ?></code>
                                            <span class="text-muted">(<?= htmlspecialchars($propConfig['type']) ?>)</span>
                                            <?php if (in_array($propName, $required)): ?>
                                                <span class="badge bg-danger">required</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Claude Code Configuration</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>Quick Setup:</strong> Get your personalized config with your API token:
                <code>curl -u username:password <?= htmlspecialchars(dirname($mcpUrl ?? '')) ?>/config</code>
            </div>

            <p>Or manually add this to your <code>~/.claude/settings.json</code> or project <code>.mcp.json</code>:</p>
            <pre class="bg-dark text-light p-3 rounded"><code>{
  "mcpServers": {
    "<?= htmlspecialchars($serverName) ?>": {
      "type": "http",
      "url": "<?= htmlspecialchars($mcpUrl ?? '') ?>",
      "headers": {
        "Authorization": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}</code></pre>

            <h6 class="mt-4">Authentication Methods</h6>
            <ul>
                <li><strong>Basic Auth:</strong> <code>Authorization: Basic base64(username:password)</code></li>
                <li><strong>Bearer Token:</strong> <code>Authorization: Bearer &lt;api_token&gt;</code></li>
                <li><strong>Custom Header:</strong> <code>X-MCP-Token: &lt;api_token&gt;</code></li>
            </ul>

            <h6 class="mt-4">Generate API Token</h6>
            <p>Generate a new API token (POST with Basic Auth):</p>
            <pre class="bg-dark text-light p-3 rounded"><code>curl -X POST -u username:password <?= htmlspecialchars(dirname($mcpUrl ?? '')) ?>/token</code></pre>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Test Connection</h5>
        </div>
        <div class="card-body">
            <pre class="bg-dark text-light p-3 rounded"><code># Health check
curl <?= htmlspecialchars(dirname($mcpUrl ?? '')) ?>/health

# Test hello tool
curl -X POST <?= htmlspecialchars($mcpUrl ?? '') ?> \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/call",
    "params": {
      "name": "hello",
      "arguments": {"name": "World"}
    }
  }'</code></pre>
        </div>
    </div>
</div>
