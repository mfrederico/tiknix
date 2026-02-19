<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="mb-4">
                <a href="/hooks" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to Hooks
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
                <div class="card-header">
                    <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Hook Configuration</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        This edits the <code>hooks</code> section of <code>.claude/settings.json</code>.
                        Changes take effect immediately for new Claude sessions.
                    </div>

                    <form method="POST" action="/hooks/save-config" id="configForm">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>

                        <div class="mb-3">
                            <label class="form-label">Hooks JSON</label>
                            <textarea class="form-control font-monospace" name="hooks_json" id="hooksJson"
                                      rows="25" style="font-size: 0.85rem;"><?= htmlspecialchars($hooksJson) ?></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> Save Configuration
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="formatBtn">
                                <i class="bi bi-code"></i> Format JSON
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="validateBtn">
                                <i class="bi bi-check-circle"></i> Validate
                            </button>
                            <a href="/hooks" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Reference -->
            <div class="card mt-4">
                <div class="card-header" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#schemaRef">
                    <h6 class="mb-0">
                        <i class="bi bi-chevron-down me-1"></i>
                        <i class="bi bi-book me-1"></i> Configuration Schema Reference
                    </h6>
                </div>
                <div class="collapse" id="schemaRef">
                    <div class="card-body">
                        <pre class="bg-light p-3 small mb-0">{
  "PreToolUse": [
    {
      "matcher": "Write|Edit",     // Regex pattern for tool names (empty = all)
      "hooks": [
        {
          "type": "command",
          "command": "/path/to/hook.php",
          "timeout": 30            // Seconds (optional, default: 60)
        }
      ]
    }
  ],
  "PostToolUse": [ ... ],
  "Stop": [ ... ]
}</pre>
                        <hr>
                        <h6>Hook Events</h6>
                        <table class="table table-sm">
                            <tr>
                                <td><code>PreToolUse</code></td>
                                <td>Runs before tool execution. Can block with exit(2).</td>
                            </tr>
                            <tr>
                                <td><code>PostToolUse</code></td>
                                <td>Runs after tool execution. For logging/notifications.</td>
                            </tr>
                            <tr>
                                <td><code>Stop</code></td>
                                <td>Runs when Claude session ends. Empty matcher runs for all.</td>
                            </tr>
                        </table>
                        <h6>Matcher Patterns</h6>
                        <ul class="small mb-0">
                            <li><code>Write|Edit</code> - Match Write OR Edit tools</li>
                            <li><code>Bash</code> - Match only Bash tool</li>
                            <li><code>Read|Write|Edit|Glob|Grep</code> - Match file operations</li>
                            <li><code>""</code> (empty) - Match all tools</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.getElementById('hooksJson');
    const formatBtn = document.getElementById('formatBtn');
    const validateBtn = document.getElementById('validateBtn');

    formatBtn.addEventListener('click', function() {
        try {
            const obj = JSON.parse(textarea.value);
            textarea.value = JSON.stringify(obj, null, 2);
        } catch (e) {
            alert('Invalid JSON: ' + e.message);
        }
    });

    validateBtn.addEventListener('click', function() {
        try {
            const obj = JSON.parse(textarea.value);

            // Check structure
            const validEvents = ['PreToolUse', 'PostToolUse', 'Stop'];
            const errors = [];

            for (const key of Object.keys(obj)) {
                if (!validEvents.includes(key)) {
                    errors.push('Unknown event: ' + key);
                }

                if (!Array.isArray(obj[key])) {
                    errors.push(key + ' must be an array');
                    continue;
                }

                for (let i = 0; i < obj[key].length; i++) {
                    const matcher = obj[key][i];
                    if (typeof matcher.matcher === 'undefined') {
                        errors.push(key + '[' + i + '] missing "matcher" property');
                    }
                    if (!Array.isArray(matcher.hooks)) {
                        errors.push(key + '[' + i + '] missing or invalid "hooks" array');
                    }
                }
            }

            if (errors.length > 0) {
                alert('Validation errors:\n- ' + errors.join('\n- '));
            } else {
                alert('JSON is valid!');
            }
        } catch (e) {
            alert('Invalid JSON: ' + e.message);
        }
    });
});
</script>
