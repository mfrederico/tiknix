<div class="ui-page-header d-flex justify-content-between align-items-end flex-wrap gap-2">
    <div>
        <span class="ui-eyebrow">Dashboard</span>
        <h1>Welcome back, <?= htmlspecialchars($member['username'] ?? 'User') ?></h1>
        <div class="ui-sub">Your central hub for every feature and tool available to you.</div>
    </div>
    <a href="/workbench" class="btn btn-primary"><i class="bi bi-rocket-takeoff"></i> Open Workbench</a>
</div>

<!-- Workbench feature panel -->
<div class="ui-panel mb-4 feature-panel">
    <div class="ui-panel-body">
        <div class="row align-items-center g-3">
            <div class="col-md-8">
                <h3 class="text-primary mb-2"><i class="bi bi-hammer me-2"></i>Build Apps with Claude</h3>
                <p class="text-secondary mb-3">
                    Use the Workbench to create, manage, and deploy applications powered by Claude AI.
                    Define tasks, let Claude write the code, and watch your ideas come to life.
                </p>
                <ul class="list-unstyled mb-3 small">
                    <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Create feature requests, bug fixes, and refactoring tasks</li>
                    <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Claude writes code following your project conventions</li>
                    <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Collaborate with your team on shared tasks</li>
                </ul>
                <a href="/workbench" class="btn btn-primary"><i class="bi bi-rocket-takeoff"></i> Open Workbench</a>
                <a href="/teams" class="btn btn-outline-primary ms-2"><i class="bi bi-people"></i> Manage Teams</a>
            </div>
            <div class="col-md-4 text-center d-none d-md-block">
                <i class="bi bi-cpu display-1 text-primary opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Profile -->
    <div class="col-lg-4">
        <div class="ui-panel h-100">
            <div class="ui-panel-header"><h3><i class="bi bi-person-circle text-primary me-2"></i>Your Profile</h3></div>
            <div class="ui-panel-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-secondary fw-normal">Username</dt><dd class="col-7"><?= htmlspecialchars($member['username'] ?? 'N/A') ?></dd>
                    <dt class="col-5 text-secondary fw-normal">Email</dt><dd class="col-7 text-truncate"><?= htmlspecialchars($member['email'] ?? 'N/A') ?></dd>
                    <dt class="col-5 text-secondary fw-normal">Member Since</dt><dd class="col-7"><?= htmlspecialchars($stats['member_since'] ?? 'Unknown') ?></dd>
                    <dt class="col-5 text-secondary fw-normal">Last Login</dt><dd class="col-7"><?= htmlspecialchars($stats['last_login'] ?? 'Never') ?></dd>
                    <dt class="col-5 text-secondary fw-normal">Total Logins</dt><dd class="col-7 ui-mono"><?= (int)($stats['login_count'] ?? 0) ?></dd>
                </dl>
                <hr>
                <a href="/member/profile" class="btn btn-sm btn-primary">View Profile</a>
                <a href="/member/edit" class="btn btn-sm btn-outline-primary">Edit Profile</a>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="col-lg-4">
        <div class="ui-panel h-100">
            <div class="ui-panel-header"><h3><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Quick Actions</h3></div>
            <div class="ui-panel-body">
                <div class="d-grid gap-2">
                    <a href="/workbench" class="btn btn-primary"><i class="bi bi-hammer"></i> Workbench</a>
                    <a href="/teams" class="btn btn-outline-primary"><i class="bi bi-people"></i> My Teams</a>
                    <a href="/member/profile" class="btn btn-outline-secondary"><i class="bi bi-person"></i> My Profile</a>
                    <a href="/member/settings" class="btn btn-outline-secondary"><i class="bi bi-gear"></i> Settings</a>
                    <?php if (isset($member['level']) && $member['level'] <= 50): ?>
                    <a href="/admin" class="btn btn-outline-danger"><i class="bi bi-shield-lock"></i> Admin Panel</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- System Info -->
    <div class="col-lg-4">
        <div class="ui-panel h-100">
            <div class="ui-panel-header"><h3><i class="bi bi-info-circle text-info me-2"></i>System</h3></div>
            <div class="ui-panel-body">
                <dl class="row mb-0 small">
                    <dt class="col-6 text-secondary fw-normal">Application</dt><dd class="col-6">TikNix</dd>
                    <dt class="col-6 text-secondary fw-normal">Version</dt><dd class="col-6 ui-mono">1.0.0</dd>
                    <dt class="col-6 text-secondary fw-normal">Environment</dt><dd class="col-6"><?= htmlspecialchars(Flight::get('app.environment') ?? 'Development') ?></dd>
                    <dt class="col-6 text-secondary fw-normal">Your Level</dt><dd class="col-6 ui-mono"><?= htmlspecialchars((string)($member['level'] ?? 'Unknown')) ?></dd>
                    <?php if (isset($stats['total_members'])): ?>
                    <dt class="col-6 text-secondary fw-normal">Total Members</dt><dd class="col-6 ui-mono"><?= (int)$stats['total_members'] ?></dd>
                    <dt class="col-6 text-secondary fw-normal">Active Members</dt><dd class="col-6 ui-mono"><?= (int)$stats['active_members'] ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Getting Started -->
<div class="ui-panel mt-4">
    <div class="ui-panel-header"><h3><i class="bi bi-journal-text text-primary me-2"></i>Getting Started</h3></div>
    <div class="ui-panel-body">
        <p class="text-secondary">This is your main dashboard where you can access all the features available to you based on your permissions.</p>
        <h6 class="mt-3">Available features</h6>
        <ul class="text-secondary">
            <li><strong class="text-body">Workbench</strong> — create and manage tasks for Claude to build your apps</li>
            <li><strong class="text-body">Teams</strong> — collaborate with others on shared projects</li>
            <li><strong class="text-body">Profile Management</strong> — view and edit your personal information</li>
            <li><strong class="text-body">Settings</strong> — customize your preferences and account settings</li>
            <?php if (isset($member['level']) && $member['level'] <= 50): ?>
            <li><strong class="text-body">Admin Panel</strong> — manage users, permissions, and system settings</li>
            <?php endif; ?>
        </ul>
        <div class="mt-3">
            <a href="/help" class="btn btn-outline-secondary"><i class="bi bi-question-circle"></i> Help Center</a>
            <a href="/contact" class="btn btn-outline-primary"><i class="bi bi-envelope"></i> Contact Support</a>
        </div>
    </div>
</div>

<style>
/* Feature panel — subtle primary tint that adapts to the active theme */
.feature-panel {
    background: linear-gradient(135deg, rgba(var(--bs-primary-rgb), 0.10) 0%, rgba(var(--bs-primary-rgb), 0.02) 100%);
    border-color: rgba(var(--bs-primary-rgb), 0.25);
}
</style>
