<?php include __DIR__ . '/../partials/agent-badge.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="/teams/view?id=<?= $team->id ?>" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($team->name) ?>
            </a>
            <h1 class="h2 mb-0 mt-1">Team Members</h1>
        </div>
        <div>
            <button class="btn btn-outline-info me-2" data-bs-toggle="modal" data-bs-target="#addAgentModal">
                <i class="bi bi-robot"></i> Add Agent
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inviteModal">
                <i class="bi bi-person-plus"></i> Invite Member
            </button>
        </div>
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

    <!-- Current Members -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Members (<?= count($memberships) ?>)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Member</th>
                        <th>Role</th>
                        <th>Permissions</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($memberships as $tm): ?>
                        <?php $member = $tm->member; ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="me-2"><?= render_member_badge($member, 40) ?></div>
                                    <div>
                                        <div class="fw-medium"><?= render_member_name($member) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($member->email) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($isOwner && $tm->role !== 'owner'): ?>
                                    <select class="form-select form-select-sm" style="width: auto;"
                                            onchange="updateRole(<?= $member->id ?>, this.value)">
                                        <option value="admin" <?= $tm->role === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        <option value="member" <?= $tm->role === 'member' ? 'selected' : '' ?>>Member</option>
                                        <option value="viewer" <?= $tm->role === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                    </select>
                                <?php else: ?>
                                    <span class="badge bg-<?= $tm->role === 'owner' ? 'primary' : ($tm->role === 'admin' ? 'info' : 'secondary') ?>">
                                        <?= ucfirst($tm->role) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small>
                                    <?php if ($tm->canRunTasks): ?>
                                        <span class="badge bg-success-subtle text-success">Run</span>
                                    <?php endif; ?>
                                    <?php if ($tm->canEditTasks): ?>
                                        <span class="badge bg-info-subtle text-info">Edit</span>
                                    <?php endif; ?>
                                    <?php if ($tm->canDeleteTasks): ?>
                                        <span class="badge bg-warning-subtle text-warning">Delete</span>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <small class="text-muted"><?= date('M j, Y', strtotime($tm->joinedAt)) ?></small>
                            </td>
                            <td>
                                <?php if ($tm->role !== 'owner' && $isOwner): ?>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="removeMember(<?= $member->id ?>, '<?= htmlspecialchars($member->displayName(), ENT_QUOTES) ?>')">
                                        <i class="bi bi-person-x"></i>
                                    </button>
                                <?php elseif ($tm->role === 'owner'): ?>
                                    <span class="text-muted small">Owner</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pending Invitations -->
    <?php if (!empty($invitations)): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Pending Invitations (<?= count($invitations) ?>)</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Sent</th>
                            <th>Expires</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invitations as $inv): ?>
                            <tr>
                                <td><?= htmlspecialchars($inv->email) ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= ucfirst($inv->role) ?></span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= date('M j, Y', strtotime($inv->createdAt)) ?></small>
                                </td>
                                <td>
                                    <?php
                                    $expires = strtotime($inv->expiresAt);
                                    $isExpired = $expires < time();
                                    ?>
                                    <small class="<?= $isExpired ? 'text-danger' : 'text-muted' ?>">
                                        <?= $isExpired ? 'Expired' : date('M j, Y', $expires) ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Invite Modal -->
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

<!-- Add Agent Modal -->
<div class="modal fade" id="addAgentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-robot"></i> Add Agent to Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="agentList">
                    <p class="text-muted">Loading available agents...</p>
                </div>
                <div id="addAgentResult" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const teamId = <?= $team->id ?>;

// Load available agents when modal opens
document.getElementById('addAgentModal')?.addEventListener('show.bs.modal', function() {
    const listDiv = document.getElementById('agentList');
    const resultDiv = document.getElementById('addAgentResult');
    resultDiv.style.display = 'none';

    listDiv.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span> Loading agents...</div>';

    // Fetch available agents and render as clickable cards
    fetch('/agents/view?format=json&team_id=' + teamId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.agents || data.agents.length === 0) {
                listDiv.innerHTML = `
                    <div class="text-center py-4">
                        <i class="bi bi-robot text-muted" style="font-size: 2rem;"></i>
                        <p class="text-muted mt-2">No agents available.</p>
                        <a href="/agents/create" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-lg"></i> Create an Agent First
                        </a>
                    </div>`;
                return;
            }

            let html = '<div class="list-group">';
            data.agents.forEach(agent => {
                const providerColors = { claude_cli: 'purple', ollama: 'success', openai: 'primary', custom_http: 'secondary' };
                const color = providerColors[agent.provider] || 'secondary';
                const providerLabel = agent.provider.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
                html += `
                    <button type="button" class="list-group-item list-group-item-action d-flex align-items-center agent-pick-btn" data-agent-id="${agent.id}">
                        <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width:40px;height:40px;">
                            <i class="bi bi-robot"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-medium">${agent.name}</div>
                            <small class="text-muted">${agent.description || 'No description'}</small>
                        </div>
                        <span class="badge bg-${color} bg-opacity-75 ms-2">${providerLabel}</span>
                    </button>`;
            });
            html += '</div>';
            listDiv.innerHTML = html;

            // Attach click handlers
            listDiv.querySelectorAll('.agent-pick-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('agentSelect').value = btn.dataset.agentId;
                    // Highlight selected
                    listDiv.querySelectorAll('.agent-pick-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                });
            });
        })
        .catch(() => {
            listDiv.innerHTML = '<div class="alert alert-danger">Failed to load agents.</div>';
        });

    listDiv.insertAdjacentHTML('afterend', `
        <input type="hidden" id="agentSelect" value="">
        <div class="mt-3">
            <button type="button" class="btn btn-info w-100" onclick="addAgentToTeam()">
                <i class="bi bi-robot"></i> Add Selected Agent to Team
            </button>
        </div>
    `);
});

async function addAgentToTeam() {
    const agentId = document.getElementById('agentSelect').value;
    const resultDiv = document.getElementById('addAgentResult');

    if (!agentId) {
        alert('Please enter an agent ID');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('team_id', teamId);
        formData.append('agent_id', agentId);

        const response = await fetch('/teams/addagent', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        resultDiv.style.display = 'block';
        if (data.success) {
            resultDiv.className = 'alert alert-success';
            resultDiv.textContent = data.message || 'Agent added to team';
            setTimeout(() => location.reload(), 1000);
        } else {
            resultDiv.className = 'alert alert-danger';
            resultDiv.textContent = data.message || 'Failed to add agent';
        }
    } catch (e) {
        resultDiv.style.display = 'block';
        resultDiv.className = 'alert alert-danger';
        resultDiv.textContent = 'Error: ' + e.message;
    }
}

async function updateRole(memberId, newRole) {
    try {
        const formData = new FormData();
        formData.append('team_id', teamId);
        formData.append('member_id', memberId);
        formData.append('role', newRole);

        const response = await fetch('/teams/updaterole', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error updating role: ' + e.message);
    }
}

async function removeMember(memberId, memberName) {
    if (!confirm('Remove ' + memberName + ' from the team?')) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('team_id', teamId);
        formData.append('member_id', memberId);

        const response = await fetch('/teams/removemember', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error removing member: ' + e.message);
    }
}

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
        formData.append('id', teamId);

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
