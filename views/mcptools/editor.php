<?php
$isEdit = !$isNew;
?>
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-9">
            <div class="mb-3">
                <a href="/mcptools" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to Tools
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
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?= $isEdit ? 'Edit Tool: ' . htmlspecialchars($toolName ?? '') : 'Create New Tool' ?></h4>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="vimToggleBtn" title="Toggle Vim Mode">
                            <i class="bi bi-keyboard"></i> <span id="vimStatus">Vim</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="validateBtn">
                            <i class="bi bi-check-circle"></i> Validate
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <form method="POST" action="<?= $isEdit ? '/mcptools/update' : '/mcptools/store' ?>" id="toolForm">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>

                        <?php if ($isEdit): ?>
                            <input type="hidden" name="name" value="<?= htmlspecialchars($toolName ?? '') ?>">
                        <?php endif; ?>

                        <!-- File Name (only for new tools) -->
                        <?php if (!$isEdit): ?>
                        <div class="p-3 border-bottom bg-light">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <label for="file_name" class="form-label mb-0">File Name:</label>
                                </div>
                                <div class="col-auto">
                                    <input type="text" class="form-control form-control-sm font-monospace"
                                           id="file_name" name="file_name"
                                           pattern="[A-Z][a-zA-Z0-9]*Tool\.php"
                                           placeholder="MyCustomTool.php"
                                           value="<?= htmlspecialchars($fileName) ?>"
                                           required style="width: 250px;">
                                </div>
                                <div class="col">
                                    <span class="text-muted small">PascalCase ending with Tool.php</span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Validation Results -->
                        <div id="validationResults" class="d-none border-bottom">
                            <div class="p-3">
                                <div id="validationContent"></div>
                            </div>
                        </div>

                        <!-- Code Editor -->
                        <?php
                        $editorCode = $code ?? '';
                        $editorHeight = '500px';
                        include dirname(__DIR__) . '/components/php-editor.php';
                        ?>

                        <div class="p-3 border-top bg-light d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="saveBtn">
                                <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save Changes' : 'Create Tool' ?>
                            </button>
                            <a href="/mcptools" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar with help -->
        <div class="col-lg-3">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i> Quick Reference</h6>
                </div>
                <div class="card-body small">
                    <h6 class="text-muted">Required Properties</h6>
                    <ul class="mb-3">
                        <li><code>$name</code> - Tool identifier (snake_case)</li>
                        <li><code>$description</code> - Description for Claude</li>
                        <li><code>$inputSchema</code> - JSON Schema for params</li>
                    </ul>

                    <h6 class="text-muted">Required Method</h6>
                    <ul class="mb-3">
                        <li><code>execute(array $args): string</code></li>
                    </ul>

                    <h6 class="text-muted">Helper Methods</h6>
                    <ul class="mb-3">
                        <li><code>$this->validateArgs($args)</code></li>
                        <li><code>$this->isAdmin()</code></li>
                        <li><code>$this->requireAdmin()</code></li>
                    </ul>

                    <h6 class="text-muted">Available Context</h6>
                    <ul class="mb-0">
                        <li><code>$this->mcp</code> - MCP controller</li>
                        <li><code>$this->member</code> - Auth member</li>
                        <li><code>$this->apiKey</code> - API key bean</li>
                    </ul>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-code-slash me-1"></i> Input Schema Types</h6>
                </div>
                <div class="card-body small">
                    <table class="table table-sm mb-0">
                        <tr><td><code>string</code></td><td>Text value</td></tr>
                        <tr><td><code>integer</code></td><td>Whole number</td></tr>
                        <tr><td><code>number</code></td><td>Decimal number</td></tr>
                        <tr><td><code>boolean</code></td><td>true/false</td></tr>
                        <tr><td><code>array</code></td><td>List of values</td></tr>
                        <tr><td><code>object</code></td><td>Key-value pairs</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('toolForm');
    const validateBtn = document.getElementById('validateBtn');
    const vimToggleBtn = document.getElementById('vimToggleBtn');
    const vimStatus = document.getElementById('vimStatus');
    const validationResults = document.getElementById('validationResults');
    const validationContent = document.getElementById('validationContent');

    // Update vim button state on load
    function updateVimButton() {
        const enabled = window.isVimModeEnabled && window.isVimModeEnabled();
        if (enabled) {
            vimToggleBtn.classList.remove('btn-outline-secondary');
            vimToggleBtn.classList.add('btn-success');
            vimStatus.textContent = 'Vim ON';
        } else {
            vimToggleBtn.classList.remove('btn-success');
            vimToggleBtn.classList.add('btn-outline-secondary');
            vimStatus.textContent = 'Vim';
        }
    }

    // Initialize vim button state after editor loads
    setTimeout(updateVimButton, 100);

    // Vim toggle handler
    vimToggleBtn.addEventListener('click', function() {
        if (window.toggleVimMode) {
            window.toggleVimMode('phpEditor');
            updateVimButton();
        }
    });

    // Validate and optionally save
    let isValidating = false;
    async function validateCode(andSave = false) {
        if (!window.phpEditor || isValidating) return false;
        isValidating = true;
        validateBtn.disabled = true;

        const code = window.phpEditor.state.doc.toString();

        try {
            const response = await fetch('/api/validatephp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({ code: code, type: 'tool' })
            });

            const data = await response.json();
            validationResults.classList.remove('d-none');

            // Collect all errors from nested structure
            const allErrors = [
                ...(data.syntax?.errors || []),
                ...(data.structure?.errors || [])
            ];

            // Collect warnings
            const allWarnings = data.structure?.warnings || [];

            // Security issues
            const securityIssues = data.security?.issues || [];

            if (data.valid && allErrors.length === 0) {
                let html = '<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i> Code is valid!</div>';

                if (allWarnings.length > 0) {
                    html += '<div class="alert alert-warning mt-2 mb-0"><strong>Warnings:</strong><ul class="mb-0">';
                    allWarnings.forEach(w => {
                        html += '<li>' + escapeHtml(typeof w === 'string' ? w : w.message) + '</li>';
                    });
                    html += '</ul></div>';
                }

                if (securityIssues.length > 0) {
                    html += '<div class="alert alert-info mt-2 mb-0"><strong>Security Notes:</strong><ul class="mb-0">';
                    securityIssues.forEach(s => {
                        const icon = s.severity === 'danger' ? 'text-danger' : 'text-warning';
                        html += '<li class="' + icon + '">' + escapeHtml(s.message);
                        if (s.line) html += ' (line ' + s.line + ')';
                        html += '</li>';
                    });
                    html += '</ul></div>';
                }

                validationContent.innerHTML = html;

                // If validation passed and save requested, submit the form
                if (andSave) {
                    form.submit();
                    return true; // Don't reset - form is submitting
                }
                isValidating = false;
                validateBtn.disabled = false;
                return true;
            } else {
                isValidating = false;
                validateBtn.disabled = false;
                let html = '<div class="alert alert-danger mb-0"><strong>Errors:</strong><ul class="mb-0">';
                allErrors.forEach(err => {
                    // Errors can be strings or objects with message property
                    const msg = typeof err === 'string' ? err : err.message;
                    html += '<li>' + escapeHtml(msg);
                    if (err.line) html += ' (line ' + err.line + ')';
                    html += '</li>';
                });
                html += '</ul></div>';
                validationContent.innerHTML = html;
                return false;
            }
        } catch (err) {
            isValidating = false;
            validateBtn.disabled = false;
            validationResults.classList.remove('d-none');
            validationContent.innerHTML = '<div class="alert alert-danger mb-0">Validation request failed: ' + escapeHtml(err.message) + '</div>';
            return false;
        }
    }

    // Validate button click
    validateBtn.addEventListener('click', function() {
        validateCode(false);
    });

    // Register vim commands (validate + save)
    // Wait for editor to be ready then register commands (only once)
    setTimeout(function() {
        if (window.CodeMirror && window.CodeMirror.Vim && !window._vimCommandsRegistered) {
            window._vimCommandsRegistered = true;

            // :w - validate and save
            window.CodeMirror.Vim.defineEx('write', 'w', function() {
                validateCode(true);
            });

            // :wq - validate, save and quit (same as :w since save redirects)
            window.CodeMirror.Vim.defineEx('wq', 'wq', function() {
                validateCode(true);
            });

            // :wq! - force validate, save and quit
            window.CodeMirror.Vim.defineEx('wq!', 'wq!', function() {
                validateCode(true);
            });

            // :q! - quit without saving (go back to list)
            window.CodeMirror.Vim.defineEx('quit!', 'q!', function() {
                window.location.href = '/mcptools';
            });

            // :q - quit (warn if unsaved changes)
            window.CodeMirror.Vim.defineEx('quit', 'q', function() {
                window.location.href = '/mcptools';
            });
        }
    }, 200);

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
});
</script>
