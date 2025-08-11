<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Member Management</h1>
    </div>
    
    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Level</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td><?= $member->id ?></td>
                        <td><?= htmlspecialchars($member->username) ?></td>
                        <td><?= htmlspecialchars($member->email) ?></td>
                        <td>
                            <?php
                            $levelName = 'Unknown';
                            $levelClass = 'secondary';
                            switch($member->level) {
                                case 0:
                                case 1:
                                    $levelName = 'ROOT';
                                    $levelClass = 'danger';
                                    break;
                                case 50:
                                    $levelName = 'ADMIN';
                                    $levelClass = 'warning';
                                    break;
                                case 100:
                                    $levelName = 'MEMBER';
                                    $levelClass = 'primary';
                                    break;
                                case 101:
                                    $levelName = 'PUBLIC';
                                    $levelClass = 'success';
                                    break;
                            }
                            ?>
                            <span class="badge bg-<?= $levelClass ?>">
                                <?= $levelName ?> (<?= $member->level ?>)
                            </span>
                        </td>
                        <td>
                            <?php
                            $statusClass = 'secondary';
                            switch($member->status) {
                                case 'active':
                                    $statusClass = 'success';
                                    break;
                                case 'suspended':
                                    $statusClass = 'warning';
                                    break;
                                case 'inactive':
                                    $statusClass = 'secondary';
                                    break;
                            }
                            // Special case for public-user-entity
                            if ($member->username === 'public-user-entity') {
                                $statusClass = 'info';
                            }
                            ?>
                            <span class="badge bg-<?= $statusClass ?>">
                                <?= htmlspecialchars($member->status) ?>
                            </span>
                        </td>
                        <td><?= date('Y-m-d', strtotime($member->created_at)) ?></td>
                        <td>
                            <a href="/admin/editMember?id=<?= $member->id ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($member->username !== 'public-user-entity' && $member->id != $_SESSION['member']['id']): ?>
                                <a href="/admin/members?delete=<?= $member->id ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete this member?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="mt-4">
        <h4>User Levels</h4>
        <ul class="list-group">
            <li class="list-group-item"><span class="badge bg-danger">0-1</span> ROOT - Full system access</li>
            <li class="list-group-item"><span class="badge bg-warning">50</span> ADMIN - Administrative access</li>
            <li class="list-group-item"><span class="badge bg-primary">100</span> MEMBER - Regular members</li>
            <li class="list-group-item"><span class="badge bg-success">101</span> PUBLIC - Public/Guest access</li>
        </ul>
    </div>
</div>