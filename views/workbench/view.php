<div class="container-fluid py-4">
    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <div class="mb-3">
                <a href="/workbench" class="text-decoration-none text-muted">
                    <i class="bi bi-arrow-left"></i> Back to Workbench
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

            <!-- Task Header -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h2 class="mb-1"><?= htmlspecialchars($task->title) ?></h2>
                            <div class="text-muted small">
                                Created by <?= htmlspecialchars($creator->displayName ?? $creator->email) ?>
                                on <?= date('M j, Y', strtotime($task->createdAt)) ?>
                                <?php if ($team): ?>
                                    &bull; <a href="/teams/view?id=<?= $team->id ?>"><?= htmlspecialchars($team->name) ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <?php
                            $typeInfo = $taskTypes[$task->taskType] ?? $taskTypes['feature'];
                            $priorityInfo = $priorities[$task->priority] ?? $priorities[3];
                            $statusBadge = match($task->status) {
                                'pending' => 'secondary',
                                'queued' => 'info',
                                'running' => 'primary',
                                'awaiting' => 'warning',
                                'completed' => 'success',
                                'failed' => 'danger',
                                'paused' => 'warning',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?= $typeInfo['color'] ?> me-1">
                                <i class="bi bi-<?= $typeInfo['icon'] ?>"></i> <?= $typeInfo['label'] ?>
                            </span>
                            <span class="badge bg-<?= $priorityInfo['color'] ?> me-1">
                                <?= $priorityInfo['label'] ?>
                            </span>
                            <span class="badge bg-<?= $statusBadge ?> fs-6">
                                <?php if ($task->status === 'running'): ?>
                                    <span class="spinner-border spinner-border-sm me-1"></span>
                                <?php endif; ?>
                                <?= ucfirst($task->status) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($canRun && in_array($task->status, ['pending', 'failed'])): ?>
                            <button class="btn btn-success" onclick="runTask(<?= $task->id ?>)">
                                <i class="bi bi-play-fill"></i> Run with Claude
                            </button>
                        <?php endif; ?>

                        <?php if ($canRun && $task->status === 'completed'): ?>
                            <button class="btn btn-outline-success" onclick="rerunTask(<?= $task->id ?>)">
                                <i class="bi bi-arrow-repeat"></i> Re-run
                            </button>
                        <?php endif; ?>

                        <?php if ($canRun && in_array($task->status, ['queued', 'running'])): ?>
                            <button class="btn btn-outline-warning" onclick="forceResetTask(<?= $task->id ?>)">
                                <i class="bi bi-arrow-counterclockwise"></i> Force Reset
                            </button>
                        <?php endif; ?>

                        <?php if ($canRun && $task->status === 'running'): ?>
                            <button class="btn btn-warning" onclick="pauseTask(<?= $task->id ?>)">
                                <i class="bi bi-pause-fill"></i> Pause
                            </button>
                            <button class="btn btn-danger" onclick="stopTask(<?= $task->id ?>)">
                                <i class="bi bi-stop-fill"></i> Stop
                            </button>
                        <?php endif; ?>

                        <?php if ($canRun && $task->status === 'paused'): ?>
                            <button class="btn btn-success" onclick="resumeTask(<?= $task->id ?>)">
                                <i class="bi bi-play-fill"></i> Resume
                            </button>
                        <?php endif; ?>

                        <?php if ($canRun && $task->status === 'awaiting'): ?>
                            <button class="btn btn-success" onclick="markComplete(<?= $task->id ?>)">
                                <i class="bi bi-check-circle"></i> Mark Complete
                            </button>
                            <button class="btn btn-outline-primary" onclick="document.getElementById('commentContent').focus()">
                                <i class="bi bi-chat-dots"></i> Send Instructions
                            </button>
                        <?php endif; ?>

                        <?php if ($canEdit): ?>
                            <a href="/workbench/edit?id=<?= $task->id ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                        <?php endif; ?>

                        <?php if ($task->prUrl): ?>
                            <a href="<?= htmlspecialchars($task->prUrl) ?>" class="btn btn-outline-primary" target="_blank">
                                <i class="bi bi-github"></i> View PR
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <?php if (!empty($task->description)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Description</h5>
                    </div>
                    <div class="card-body">
                        <div class="prose"><?= nl2br(htmlspecialchars($task->description)) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Acceptance Criteria -->
            <?php if (!empty($task->acceptanceCriteria)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Acceptance Criteria</h5>
                    </div>
                    <div class="card-body">
                        <div class="prose"><?= nl2br(htmlspecialchars($task->acceptanceCriteria)) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Live Progress (when running or awaiting) -->
            <?php if (in_array($task->status, ['running', 'queued', 'awaiting'])): ?>
                <div class="card mb-4" id="progressCard">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <span class="spinner-border spinner-border-sm me-2"></span>
                            Live Progress
                        </h5>
                        <small class="text-muted" id="lastUpdate">Updating...</small>
                    </div>
                    <div class="card-body">
                        <div id="progressContent">
                            <p class="text-muted">Connecting to Claude runner...</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Results (when completed) -->
            <?php if ($task->status === 'completed'): ?>
                <div class="card mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-check-circle"></i> Task Completed</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($task->branchName): ?>
                            <p><strong>Branch:</strong> <code><?= htmlspecialchars($task->branchName) ?></code></p>
                        <?php endif; ?>
                        <?php if ($task->prUrl): ?>
                            <p><strong>Pull Request:</strong> <a href="<?= htmlspecialchars($task->prUrl) ?>" target="_blank"><?= htmlspecialchars($task->prUrl) ?></a></p>
                        <?php endif; ?>
                        <?php if ($task->completedAt): ?>
                            <p><strong>Completed:</strong> <?= date('M j, Y g:i A', strtotime($task->completedAt)) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Error (when failed) -->
            <?php if ($task->status === 'failed' && $task->errorMessage): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-x-circle"></i> Task Failed</h5>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0 text-danger"><?= htmlspecialchars($task->errorMessage) ?></pre>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Comments -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>Conversation</h5>
                </div>
                <div class="card-body">
                    <div id="commentsList">
                        <?php if (empty($comments)): ?>
                            <p class="text-muted" id="noComments">No messages yet. Add instructions or context below.</p>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <?php
                                $isFromClaude = !empty($comment['is_from_claude']);
                                $authorName = $isFromClaude ? 'Claude' : trim(($comment['first_name'] ?? '') . ' ' . ($comment['last_name'] ?? ''));
                                if (empty($authorName)) $authorName = $comment['username'] ?? $comment['email'] ?? 'Unknown';
                                ?>
                                <div class="d-flex mb-3 <?= $isFromClaude ? 'flex-row-reverse' : '' ?>">
                                    <?php if ($isFromClaude): ?>
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center ms-2" style="width: 40px; height: 40px;">
                                            <i class="bi bi-robot"></i>
                                        </div>
                                    <?php elseif (!empty($comment['avatar_url'])): ?>
                                        <img src="<?= htmlspecialchars($comment['avatar_url']) ?>" class="rounded-circle me-2" width="40" height="40">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px;">
                                            <?= strtoupper(substr($authorName, 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <div class="<?= $isFromClaude ? 'bg-primary bg-opacity-10 border border-primary' : 'bg-light' ?> rounded p-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <strong><?= $isFromClaude ? '<i class="bi bi-robot me-1"></i>' : '' ?><?= htmlspecialchars($authorName) ?></strong>
                                                <small class="text-muted"><?= date('M j, g:i A', strtotime($comment['created_at'])) ?></small>
                                            </div>
                                            <?php
                                            // Simple markdown parsing for Claude messages
                                            $content = htmlspecialchars($comment['content']);
                                            if ($isFromClaude) {
                                                // Bold: **text** or __text__
                                                $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
                                                // Italic: *text* or _text_
                                                $content = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $content);
                                                // Lists: - item
                                                $content = preg_replace('/^- (.+)$/m', '<li>$1</li>', $content);
                                            }
                                            $content = nl2br($content);
                                            ?>
                                            <div class="comment-content"><?= $content ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Add Message Form -->
                    <form id="commentForm" class="mt-3">
                        <div class="mb-2">
                            <textarea class="form-control" id="commentContent" name="content" rows="2" placeholder="Send instructions or respond to Claude..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Send</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Task Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0">Details</h6>
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt>Status</dt>
                        <dd><span class="badge bg-<?= $statusBadge ?>"><?= ucfirst($task->status) ?></span></dd>

                        <dt>Run Count</dt>
                        <dd><?= $task->runCount ?? 0 ?></dd>

                        <?php if ($task->startedAt): ?>
                            <dt>Last Started</dt>
                            <dd><?= date('M j, g:i A', strtotime($task->startedAt)) ?></dd>
                        <?php endif; ?>

                        <?php if ($task->tmuxSession): ?>
                            <dt>Session</dt>
                            <dd><code class="small"><?= htmlspecialchars($task->tmuxSession) ?></code></dd>
                        <?php endif; ?>

                        <?php
                        $relatedFiles = json_decode($task->relatedFiles, true) ?: [];
                        if (!empty($relatedFiles)):
                        ?>
                            <dt>Related Files</dt>
                            <dd>
                                <?php foreach ($relatedFiles as $file): ?>
                                    <code class="d-block small"><?= htmlspecialchars($file) ?></code>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>

                        <?php
                        $tags = json_decode($task->tags, true) ?: [];
                        if (!empty($tags)):
                        ?>
                            <dt>Tags</dt>
                            <dd>
                                <?php foreach ($tags as $tag): ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Recent Logs -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Recent Logs</h6>
                    <a href="/workbench/logs?id=<?= $task->id ?>" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;" id="logsList">
                    <?php if (empty($logs)): ?>
                        <div class="list-group-item text-muted small">No logs yet</div>
                    <?php else: ?>
                        <?php foreach (array_slice($logs, 0, 10) as $log): ?>
                            <div class="list-group-item py-2">
                                <div class="d-flex justify-content-between">
                                    <span class="badge bg-<?= match($log->logLevel) {
                                        'error' => 'danger',
                                        'warning' => 'warning',
                                        'info' => 'info',
                                        default => 'secondary'
                                    } ?>"><?= $log->logLevel ?></span>
                                    <small class="text-muted"><?= date('g:i A', strtotime($log->createdAt)) ?></small>
                                </div>
                                <div class="small mt-1"><?= htmlspecialchars($log->message) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Danger Zone -->
            <?php if ($canDelete): ?>
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">Danger Zone</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/workbench/delete" onsubmit="return confirm('Delete this task? This cannot be undone.');">
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                            <?php endforeach; ?>
                            <input type="hidden" name="id" value="<?= $task->id ?>">
                            <button type="submit" class="btn btn-danger btn-sm w-100">
                                <i class="bi bi-trash"></i> Delete Task
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const taskId = <?= $task->id ?>;
const taskStatus = '<?= $task->status ?>';
const csrfToken = '<?= \app\SimpleCsrf::getToken() ?>';
let pollInterval = null;

// Task actions
async function runTask(id) {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/run', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-play-fill"></i> Run with Claude';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-fill"></i> Run with Claude';
    }
}

async function forceResetTask(id) {
    if (!confirm('Force reset this task? This will kill any running session and reset to pending.')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Resetting...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/forcereset', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Force Reset';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Force Reset';
    }
}

async function rerunTask(id) {
    if (!confirm('Re-run this task with Claude?')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/rerun', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Re-run';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Re-run';
    }
}

async function pauseTask(id) {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf_token', csrfToken);
    const response = await fetch('/workbench/pause', { method: 'POST', body: formData });
    const data = await response.json();
    if (data.success) location.reload();
    else alert('Error: ' + data.message);
}

async function resumeTask(id) {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf_token', csrfToken);
    const response = await fetch('/workbench/resume', { method: 'POST', body: formData });
    const data = await response.json();
    if (data.success) location.reload();
    else alert('Error: ' + data.message);
}

async function stopTask(id) {
    if (!confirm('Stop the Claude runner? Progress may be lost.')) return;
    const formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf_token', csrfToken);
    const response = await fetch('/workbench/stop', { method: 'POST', body: formData });
    const data = await response.json();
    if (data.success) location.reload();
    else alert('Error: ' + data.message);
}

// Progress polling
async function pollProgress() {
    try {
        const response = await fetch('/workbench/progress?id=' + taskId);
        const data = await response.json();

        document.getElementById('lastUpdate').textContent = 'Updated ' + new Date().toLocaleTimeString();

        if (data.status !== 'running' && data.status !== 'queued') {
            clearInterval(pollInterval);
            location.reload();
            return;
        }

        let html = '';

        if (data.live) {
            html += '<div class="mb-2"><strong>Status:</strong> ' + (data.live.status || 'Running') + '</div>';
            if (data.live.current_task) {
                html += '<div class="mb-2"><strong>Current:</strong> ' + data.live.current_task + '</div>';
            }
            if (data.live.files_changed && data.live.files_changed.length > 0) {
                html += '<div class="mb-2"><strong>Files Changed:</strong></div>';
                html += '<ul class="list-unstyled ms-3">';
                data.live.files_changed.forEach(f => {
                    html += '<li><code>' + f + '</code></li>';
                });
                html += '</ul>';
            }
        }

        if (data.recent_logs && data.recent_logs.length > 0) {
            html += '<div class="mt-3"><strong>Recent Activity:</strong></div>';
            html += '<div class="small text-muted mt-2">';
            data.recent_logs.slice(0, 5).forEach(log => {
                html += '<div>' + log.message + '</div>';
            });
            html += '</div>';
        }

        document.getElementById('progressContent').innerHTML = html || '<p class="text-muted">Waiting for activity...</p>';

    } catch (e) {
        console.error('Poll error:', e);
    }
}

// Start polling if task is running or awaiting
if (taskStatus === 'running' || taskStatus === 'queued' || taskStatus === 'awaiting') {
    pollProgress();
    pollInterval = setInterval(pollProgress, 3000);
}

// Mark task as complete
async function markComplete(id) {
    if (!confirm('Mark this task as complete?')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Completing...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/complete', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle"></i> Mark Complete';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Mark Complete';
    }
}

// Comment form
document.getElementById('commentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const content = document.getElementById('commentContent').value.trim();
    if (!content) return;

    const formData = new FormData();
    formData.append('id', taskId);
    formData.append('content', content);
    formData.append('_csrf_token', csrfToken);

    try {
        const response = await fetch('/workbench/comment', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            const noComments = document.getElementById('noComments');
            if (noComments) noComments.remove();

            const html = `
                <div class="d-flex mb-3">
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px;">
                        ${data.comment.author.charAt(0).toUpperCase()}
                    </div>
                    <div class="flex-grow-1">
                        <div class="bg-light rounded p-3">
                            <div class="d-flex justify-content-between mb-1">
                                <strong>${data.comment.author}</strong>
                                <small class="text-muted">Just now</small>
                            </div>
                            <div>${data.comment.content.replace(/\n/g, '<br>')}</div>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('commentsList').insertAdjacentHTML('beforeend', html);
            document.getElementById('commentContent').value = '';
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error posting comment');
    }
});
</script>
