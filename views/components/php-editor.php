<?php
/**
 * PHP Code Editor Component (CodeMirror 6)
 *
 * Usage:
 * <?php
 * $editorId = 'myEditor';
 * $editorCode = $existingCode ?? '';
 * $editorHeight = '500px';
 * $editorReadonly = false;
 * include __DIR__ . '/../components/php-editor.php';
 * ?>
 *
 * JavaScript API:
 * - getEditorCode(editorId) - Get current code
 * - setEditorCode(editorId, code) - Set code
 * - validatePhpCode(editorId, type) - Validate via AJAX (returns promise)
 */

$editorId = $editorId ?? 'phpEditor';
$editorCode = $editorCode ?? "<?php\n\n";
$editorHeight = $editorHeight ?? '400px';
$editorReadonly = $editorReadonly ?? false;
?>

<!-- CodeMirror 6 Editor Container -->
<div id="<?= htmlspecialchars($editorId) ?>-container" class="php-editor-container border rounded" style="height: <?= htmlspecialchars($editorHeight) ?>;">
    <div id="<?= htmlspecialchars($editorId) ?>" class="php-editor" style="height: 100%;"></div>
</div>

<!-- Hidden textarea for form submission -->
<textarea id="<?= htmlspecialchars($editorId) ?>-textarea" name="code" style="display: none;"><?= htmlspecialchars($editorCode) ?></textarea>

<!-- Validation Results -->
<div id="<?= htmlspecialchars($editorId) ?>-validation" class="mt-2" style="display: none;">
    <div id="<?= htmlspecialchars($editorId) ?>-errors" class="alert alert-danger small" style="display: none;"></div>
    <div id="<?= htmlspecialchars($editorId) ?>-warnings" class="alert alert-warning small" style="display: none;"></div>
    <div id="<?= htmlspecialchars($editorId) ?>-security" class="alert alert-info small" style="display: none;"></div>
    <div id="<?= htmlspecialchars($editorId) ?>-success" class="alert alert-success small" style="display: none;">
        <i class="bi bi-check-circle"></i> No syntax errors detected
    </div>
</div>

<style>
.php-editor-container {
    overflow: hidden;
    background: #1e1e1e;
}
.php-editor {
    font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
    font-size: 14px;
}
.cm-editor {
    height: 100%;
}
.cm-scroller {
    overflow: auto;
}
/* Dark theme overrides */
.cm-editor .cm-gutters {
    background-color: #252526;
    border-right: 1px solid #3c3c3c;
}
.cm-editor .cm-activeLineGutter {
    background-color: #2a2a2a;
}
</style>

<!-- CodeMirror 6 (local bundle) -->
<script src="/js/codemirror.min.js"></script>
<script>
(function() {
    const editorId = <?= json_encode($editorId) ?>;
    const initialCode = <?= json_encode($editorCode) ?>;
    const readonly = <?= json_encode($editorReadonly) ?>;

    // Get CodeMirror from global bundle
    const {EditorView, EditorState, Compartment, basicSetup, php, oneDark, keymap, indentWithTab, vim, Vim} = window.CodeMirror;

    // Create editor
    const container = document.getElementById(editorId);
    const textarea = document.getElementById(editorId + '-textarea');

    // Compartment for vim mode (allows dynamic toggling)
    const vimCompartment = new Compartment();
    const vimEnabled = localStorage.getItem('editor-vim-mode') === 'true';

    const updateListener = EditorView.updateListener.of((update) => {
        if (update.docChanged) {
            // Sync to hidden textarea for form submission
            textarea.value = update.state.doc.toString();
        }
    });

    // Build extensions array with basicSetup for line numbers, bracket matching, etc.
    const extensions = [
        vimCompartment.of(vimEnabled ? vim() : []),
        basicSetup,
        php(),
        oneDark,
        keymap.of([indentWithTab]),
        updateListener,
        EditorView.lineWrapping
    ];

    if (readonly) {
        extensions.push(EditorState.readOnly.of(true));
    }

    const state = EditorState.create({
        doc: initialCode,
        extensions: extensions
    });

    const view = new EditorView({
        state: state,
        parent: container
    });

    // Store reference globally for API access
    window.phpEditors = window.phpEditors || {};
    window.phpEditors[editorId] = view;
    window.phpEditors[editorId + '_vimCompartment'] = vimCompartment;

    // Also store as window.phpEditor for backwards compatibility
    window.phpEditor = view;

    // Expose Vim for Ex command registration
    window.CodeMirror.Vim = Vim;

    // Vim toggle function
    window.toggleVimMode = function(id) {
        const editor = window.phpEditors[id];
        const compartment = window.phpEditors[id + '_vimCompartment'];
        if (!editor || !compartment) return false;

        const currentlyEnabled = localStorage.getItem('editor-vim-mode') === 'true';
        const newState = !currentlyEnabled;

        localStorage.setItem('editor-vim-mode', newState);

        editor.dispatch({
            effects: compartment.reconfigure(newState ? vim() : [])
        });

        return newState;
    };

    // Check if vim is enabled
    window.isVimModeEnabled = function() {
        return localStorage.getItem('editor-vim-mode') === 'true';
    };

    // Global API functions
    window.getEditorCode = function(id) {
        const editor = window.phpEditors[id];
        return editor ? editor.state.doc.toString() : null;
    };

    window.setEditorCode = function(id, code) {
        const editor = window.phpEditors[id];
        if (editor) {
            editor.dispatch({
                changes: {
                    from: 0,
                    to: editor.state.doc.length,
                    insert: code
                }
            });
        }
    };

    window.validatePhpCode = async function(id, type = 'tool') {
        const code = window.getEditorCode(id);
        if (!code) return null;

        const validationDiv = document.getElementById(id + '-validation');
        const errorsDiv = document.getElementById(id + '-errors');
        const warningsDiv = document.getElementById(id + '-warnings');
        const securityDiv = document.getElementById(id + '-security');
        const successDiv = document.getElementById(id + '-success');

        // Reset display
        validationDiv.style.display = 'block';
        errorsDiv.style.display = 'none';
        warningsDiv.style.display = 'none';
        securityDiv.style.display = 'none';
        successDiv.style.display = 'none';

        try {
            const response = await fetch('/api/validatephp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ code: code, type: type })
            });

            const result = await response.json();

            // Show errors
            const allErrors = [
                ...(result.syntax?.errors || []),
                ...(result.structure?.errors || [])
            ];
            if (allErrors.length > 0) {
                errorsDiv.innerHTML = '<strong><i class="bi bi-x-circle"></i> Errors:</strong><ul class="mb-0 mt-1">' +
                    allErrors.map(e => '<li>' + escapeHtml(e) + '</li>').join('') + '</ul>';
                errorsDiv.style.display = 'block';
            }

            // Show warnings
            const allWarnings = result.structure?.warnings || [];
            if (allWarnings.length > 0) {
                warningsDiv.innerHTML = '<strong><i class="bi bi-exclamation-triangle"></i> Warnings:</strong><ul class="mb-0 mt-1">' +
                    allWarnings.map(w => '<li>' + escapeHtml(w) + '</li>').join('') + '</ul>';
                warningsDiv.style.display = 'block';
            }

            // Show security issues
            const securityIssues = result.security?.issues || [];
            if (securityIssues.length > 0) {
                securityDiv.innerHTML = '<strong><i class="bi bi-shield-exclamation"></i> Security:</strong><ul class="mb-0 mt-1">' +
                    securityIssues.map(s => {
                        const icon = s.severity === 'danger' ? 'bi-exclamation-octagon text-danger' : 'bi-exclamation-triangle text-warning';
                        const line = s.line ? ` (line ${s.line})` : '';
                        return `<li><i class="${icon}"></i> ${escapeHtml(s.message)}${line}</li>`;
                    }).join('') + '</ul>';
                securityDiv.style.display = 'block';
            }

            // Show success if valid
            if (result.valid && allErrors.length === 0) {
                successDiv.style.display = 'block';
            }

            return result;
        } catch (error) {
            errorsDiv.innerHTML = '<strong><i class="bi bi-x-circle"></i> Error:</strong> ' + escapeHtml(error.message);
            errorsDiv.style.display = 'block';
            return { valid: false, error: error.message };
        }
    };

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>
