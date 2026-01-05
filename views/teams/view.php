<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="/teams" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left"></i> All Teams
            </a>
            <h1 class="h2 mb-0 mt-1"><?= htmlspecialchars($team->name) ?></h1>
            <?php if (!empty($team->description)): ?>
                <p class="text-muted mb-0"><?= htmlspecialchars($team->description) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($isAdmin): ?>
                <a href="/teams/members?id=<?= $team->id ?>" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-people"></i> Members
                </a>
            <?php endif; ?>
            <?php if ($isOwner): ?>
                <a href="/teams/settings?id=<?= $team->id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-gear"></i> Settings
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    foreach ($flash as $msg):
    ?>
        <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : ($msg['type'] === 'info' ? 'info' : 'success') ?> alert-dismissible fade show">
            <?= htmlspecialchars($msg['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Quick Actions -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex gap-2">
                        <a href="/workbench/create?team_id=<?= $team->id ?>" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> New Task
                        </a>
                        <a href="/workbench?team_id=<?= $team->id ?>" class="btn btn-outline-primary">
                            <i class="bi bi-kanban"></i> View All Tasks
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Tasks -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Tasks</h5>
                    <a href="/workbench?team_id=<?= $team->id ?>" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($tasks)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                            <p class="mt-2 mb-0">No tasks yet</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($tasks as $task): ?>
                                <?php $creator = $task->member; ?>
                                <a href="/workbench/view?id=<?= $task->id ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($task->title) ?></h6>
                                            <small class="text-muted">
                                                <?= ucfirst($task->taskType) ?> &bull;
                                                by <?= htmlspecialchars($creator ? $creator->displayName() : 'Unknown') ?> &bull;
                                                <?= date('M j', strtotime($task->createdAt)) ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-<?= match($task->status) {
                                            'completed' => 'success',
                                            'running' => 'primary',
                                            'failed' => 'danger',
                                            'paused' => 'warning',
                                            default => 'secondary'
                                        } ?>">
                                            <?= ucfirst($task->status) ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Team Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0">Your Role</h6>
                </div>
                <div class="card-body">
                    <span class="badge bg-<?= $userRole === 'owner' ? 'primary' : ($userRole === 'admin' ? 'info' : 'secondary') ?> fs-6">
                        <?= ucfirst($userRole) ?>
                    </span>
                    <p class="small text-muted mt-2 mb-0">
                        <?php
                        echo match($userRole) {
                            'owner' => 'Full control over team settings, members, and all tasks.',
                            'admin' => 'Can manage members and all team tasks.',
                            'member' => 'Can create, edit, and run team tasks.',
                            'viewer' => 'Can view team tasks (read-only).',
                            default => ''
                        };
                        ?>
                    </p>
                </div>
            </div>

            <!-- Team Members -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Members (<?= count($memberships) ?>)</h6>
                    <?php if ($isAdmin): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#inviteModal">
                            <i class="bi bi-person-plus"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="list-group list-group-flush">
                    <?php $count = 0; foreach ($memberships as $tm): ?>
                        <?php if ($count++ >= 5) break; ?>
                        <?php $member = $tm->member; ?>
                        <div class="list-group-item d-flex align-items-center">
                            <?php if (!empty($member->avatarUrl)): ?>
                                <img src="<?= htmlspecialchars($member->avatarUrl) ?>" class="rounded-circle me-2" width="32" height="32">
                            <?php else: ?>
                                <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                    <?= $member->initials() ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <div class="fw-medium small">
                                    <?= htmlspecialchars($member->displayName()) ?>
                                </div>
                            </div>
                            <span class="badge bg-<?= $tm->role === 'owner' ? 'primary' : ($tm->role === 'admin' ? 'info' : 'secondary') ?> small">
                                <?= ucfirst($tm->role) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($memberships) > 5): ?>
                        <a href="/teams/members?id=<?= $team->id ?>" class="list-group-item list-group-item-action text-center small text-muted">
                            View all <?= count($memberships) ?> members
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pending Invitations (Admins only) -->
            <?php if ($isAdmin && !empty($invitations)): ?>
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Pending Invitations</h6>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($invitations as $inv): ?>
                            <div class="list-group-item">
                                <div class="small"><?= htmlspecialchars($inv->email) ?></div>
                                <small class="text-muted">
                                    Expires <?= date('M j', strtotime($inv->expiresAt)) ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Leave Team (if not owner) -->
            <?php if (!$isOwner): ?>
                <div class="card mt-4 border-danger">
                    <div class="card-body">
                        <form method="POST" action="/teams/leave" onsubmit="return confirm('Are you sure you want to leave this team?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $team->id ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                <i class="bi bi-box-arrow-left"></i> Leave Team
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Invite Modal -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="inviteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Invite Team Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="inviteForm">
                    <div class="mb-3">
                        <label for="inviteEmail" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="inviteEmail" name="email" required
                               placeholder="colleague@example.com">
                    </div>
                    <div class="mb-3">
                        <label for="inviteRole" class="form-label">Role</label>
                        <select class="form-select" id="inviteRole" name="role">
                            <option value="member">Member - Can create, edit, and run tasks</option>
                            <option value="admin">Admin - Can also manage team members</option>
                            <option value="viewer">Viewer - Read-only access</option>
                        </select>
                    </div>
                </form>
                <div id="inviteResult" style="display: none;">
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> Invitation sent!
                    </div>
                    <p>Share this link with the invitee:</p>
                    <div class="input-group">
                        <input type="text" class="form-control" id="inviteLink" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyInviteLink()">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="sendInviteBtn" onclick="sendInvite()">
                    Send Invitation
                </button>
            </div>
        </div>
    </div>
</div>

<script>
async function sendInvite() {
    const email = document.getElementById('inviteEmail').value;
    const role = document.getElementById('inviteRole').value;
    const btn = document.getElementById('sendInviteBtn');

    if (!email) {
        alert('Please enter an email address');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Sending...';

    try {
        const formData = new FormData();
        formData.append('email', email);
        formData.append('role', role);
        formData.append('id', <?= $team->id ?>);

        const response = await fetch('/teams/invite', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            document.getElementById('inviteForm').style.display = 'none';
            document.getElementById('inviteResult').style.display = 'block';
            document.getElementById('inviteLink').value = data.join_url;
            btn.style.display = 'none';
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error sending invitation: ' + e.message);
    }

    btn.disabled = false;
    btn.textContent = 'Send Invitation';
}

function copyInviteLink() {
    const input = document.getElementById('inviteLink');
    input.select();
    document.execCommand('copy');

    const btn = input.nextElementSibling;
    btn.innerHTML = '<i class="bi bi-check"></i>';
    setTimeout(() => {
        btn.innerHTML = '<i class="bi bi-clipboard"></i>';
    }, 2000);
}

// Reset modal on close
document.getElementById('inviteModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('inviteForm').style.display = 'block';
    document.getElementById('inviteResult').style.display = 'none';
    document.getElementById('sendInviteBtn').style.display = 'block';
    document.getElementById('inviteEmail').value = '';
});
</script>
<?php endif; ?>
