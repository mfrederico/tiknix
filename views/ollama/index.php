<div class="container-fluid py-4">
    <div class="row">
        <!-- Main Chat Area -->
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-chat-dots"></i> Ollama Chat
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($isAvailable): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> Connected</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Offline</span>
                        <?php endif; ?>

                        <select id="model-select" class="form-select form-select-sm" style="width: auto;">
                            <?php if (empty($models)): ?>
                                <option value="">No models available</option>
                            <?php else: ?>
                                <?php foreach ($models as $model): ?>
                                    <option value="<?= htmlspecialchars($model['name']) ?>"
                                            <?= ($preferredModel === $model['name']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($model['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>

                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-clear-chat" title="Clear chat">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <div class="card-body p-0">
                    <!-- Messages Container -->
                    <div id="chat-messages" class="overflow-auto" style="height: 500px; padding: 1rem;">
                        <div class="text-center text-muted py-5" id="empty-state">
                            <i class="bi bi-robot" style="font-size: 3rem;"></i>
                            <p class="mt-2">Start a conversation with Ollama</p>
                            <?php if (!$isAvailable): ?>
                                <div class="alert alert-warning d-inline-block">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    Ollama server is not available at <code><?= htmlspecialchars($ollamaUrl) ?></code>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card-footer">
                    <form id="chat-form">
                        <?= csrf_field() ?>
                        <div class="input-group">
                            <input type="text" id="chat-input" class="form-control"
                                   placeholder="Type your message..."
                                   <?= !$isAvailable ? 'disabled' : '' ?>
                                   autocomplete="off">
                            <button type="submit" class="btn btn-primary" id="btn-send" <?= !$isAvailable ? 'disabled' : '' ?>>
                                <i class="bi bi-send"></i> Send
                            </button>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="enable-tools" checked>
                            <label class="form-check-label" for="enable-tools">
                                Enable tool calling
                            </label>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-3">
            <!-- Available Tools -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="bi bi-tools"></i> Available Tools
                        <span class="badge bg-secondary"><?= count($tools) ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;">
                        <?php if (empty($tools)): ?>
                            <div class="list-group-item text-muted">No tools available</div>
                        <?php else: ?>
                            <?php foreach ($tools as $tool): ?>
                                <div class="list-group-item">
                                    <div class="fw-bold"><code><?= htmlspecialchars($tool['name']) ?></code></div>
                                    <small class="text-muted"><?= htmlspecialchars($tool['description']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tool Execution Log -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="bi bi-terminal"></i> Tool Log
                    </h6>
                    <button type="button" class="btn btn-sm btn-link p-0" id="btn-clear-log" title="Clear log">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div id="tool-log" class="font-monospace small" style="max-height: 250px; overflow-y: auto; padding: 0.5rem;">
                        <div class="text-muted">Tool executions will appear here...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.chat-message {
    margin-bottom: 1rem;
    padding: 0.75rem;
    border-radius: 0.5rem;
    max-width: 85%;
}
.chat-message.user {
    background: #e3f2fd;
    margin-left: auto;
}
.chat-message.assistant {
    background: #f5f5f5;
}
.chat-message.tool {
    background: #fff3e0;
    border-left: 3px solid #ff9800;
    font-family: monospace;
    font-size: 0.85rem;
}
.chat-message .role {
    font-weight: bold;
    font-size: 0.75rem;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}
.chat-message.user .role { color: #1565c0; }
.chat-message.assistant .role { color: #424242; }
.chat-message.tool .role { color: #e65100; }

.chat-message pre {
    background: #263238;
    color: #aed581;
    padding: 0.5rem;
    border-radius: 0.25rem;
    overflow-x: auto;
    margin: 0.5rem 0 0 0;
}
.chat-message code {
    background: rgba(0,0,0,0.1);
    padding: 0.1rem 0.25rem;
    border-radius: 0.2rem;
}

.tool-log-entry {
    padding: 0.5rem;
    border-bottom: 1px solid #eee;
}
.tool-log-entry:last-child {
    border-bottom: none;
}
.tool-log-entry.success { border-left: 3px solid #4caf50; }
.tool-log-entry.error { border-left: 3px solid #f44336; }

.typing-indicator {
    display: inline-flex;
    gap: 4px;
    padding: 0.5rem;
}
.typing-indicator span {
    width: 8px;
    height: 8px;
    background: #666;
    border-radius: 50%;
    animation: typing 1.4s infinite ease-in-out;
}
.typing-indicator span:nth-child(1) { animation-delay: 0s; }
.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.6; }
    30% { transform: translateY(-4px); opacity: 1; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const modelSelect = document.getElementById('model-select');
    const enableTools = document.getElementById('enable-tools');
    const toolLog = document.getElementById('tool-log');
    const emptyState = document.getElementById('empty-state');
    const btnSend = document.getElementById('btn-send');

    let messages = [];
    let isProcessing = false;

    // Clear chat
    document.getElementById('btn-clear-chat').addEventListener('click', function() {
        messages = [];
        chatMessages.innerHTML = '';
        emptyState.style.display = 'block';
        chatMessages.appendChild(emptyState);
    });

    // Clear tool log
    document.getElementById('btn-clear-log').addEventListener('click', function() {
        toolLog.innerHTML = '<div class="text-muted">Tool executions will appear here...</div>';
    });

    // Submit chat
    chatForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const content = chatInput.value.trim();
        if (!content || isProcessing) return;

        const model = modelSelect.value;
        if (!model) {
            alert('Please select a model');
            return;
        }

        // Hide empty state
        if (emptyState.parentNode === chatMessages) {
            emptyState.style.display = 'none';
        }

        // Add user message
        messages.push({ role: 'user', content: content });
        appendMessage('user', content);
        chatInput.value = '';

        // Show typing indicator
        isProcessing = true;
        btnSend.disabled = true;
        const typingEl = showTyping();

        try {
            const response = await fetch('/ollama/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({
                    model: model,
                    messages: messages,
                    enable_tools: enableTools.checked
                })
            });

            const data = await response.json();

            // Remove typing indicator
            typingEl.remove();

            if (data.success) {
                // Log tool results
                if (data.tool_results && data.tool_results.length > 0) {
                    data.tool_results.forEach(tr => {
                        logToolExecution(tr.tool, tr.arguments, tr.result);
                    });
                }

                // Add assistant message
                const assistantContent = data.message?.content || 'No response';
                messages.push({ role: 'assistant', content: assistantContent });
                appendMessage('assistant', assistantContent, data.tool_results);

            } else {
                appendMessage('assistant', 'Error: ' + (data.error || 'Unknown error'));
            }

        } catch (err) {
            typingEl.remove();
            appendMessage('assistant', 'Error: ' + err.message);
        } finally {
            isProcessing = false;
            btnSend.disabled = false;
            chatInput.focus();
        }
    });

    function appendMessage(role, content, toolResults = null) {
        const div = document.createElement('div');
        div.className = 'chat-message ' + role;

        let html = '<div class="role">' + role + '</div>';
        html += '<div class="content">' + formatContent(content) + '</div>';

        // Show tool results if any
        if (toolResults && toolResults.length > 0) {
            html += '<div class="mt-2 small">';
            html += '<span class="badge bg-warning text-dark"><i class="bi bi-tools"></i> Used ' + toolResults.length + ' tool(s)</span>';
            html += '</div>';
        }

        div.innerHTML = html;
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function formatContent(content) {
        // Escape HTML first
        let html = content
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Code blocks
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, function(match, lang, code) {
            return '<pre><code>' + code.trim() + '</code></pre>';
        });

        // Inline code
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Line breaks
        html = html.replace(/\n/g, '<br>');

        return html;
    }

    function showTyping() {
        const div = document.createElement('div');
        div.className = 'chat-message assistant';
        div.innerHTML = '<div class="typing-indicator"><span></span><span></span><span></span></div>';
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return div;
    }

    function logToolExecution(toolName, args, result) {
        // Clear placeholder if present
        if (toolLog.querySelector('.text-muted')) {
            toolLog.innerHTML = '';
        }

        const div = document.createElement('div');
        const isError = result && result.error;
        div.className = 'tool-log-entry ' + (isError ? 'error' : 'success');

        let html = '<div class="fw-bold">' + toolName + '</div>';
        html += '<div class="text-muted small">Args: ' + JSON.stringify(args) + '</div>';

        if (isError) {
            html += '<div class="text-danger small">Error: ' + result.error + '</div>';
        } else {
            const resultStr = typeof result === 'string' ? result : JSON.stringify(result);
            const truncated = resultStr.length > 200 ? resultStr.substring(0, 200) + '...' : resultStr;
            html += '<div class="text-success small">' + truncated + '</div>';
        }

        div.innerHTML = html;
        toolLog.insertBefore(div, toolLog.firstChild);
    }

    // Focus input on load
    chatInput.focus();
});
</script>
