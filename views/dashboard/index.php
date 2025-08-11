<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mb-4">Welcome to Your Dashboard</h1>
            
            <div class="alert alert-info">
                <h4 class="alert-heading">Hello, <?= htmlspecialchars($member['username'] ?? 'User') ?>!</h4>
                <p>Welcome to the TikNix Framework Dashboard. This is your central hub for accessing all available features and tools.</p>
                <hr>
                <p class="mb-0">You're successfully logged in and ready to get started.</p>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <!-- User Info Card -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-person-circle"></i> Your Profile
                </div>
                <div class="card-body">
                    <p><strong>Username:</strong> <?= htmlspecialchars($member['username'] ?? 'N/A') ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($member['email'] ?? 'N/A') ?></p>
                    <p><strong>Member Since:</strong> <?= $stats['member_since'] ?? 'Unknown' ?></p>
                    <p><strong>Last Login:</strong> <?= $stats['last_login'] ?? 'Never' ?></p>
                    <p><strong>Total Logins:</strong> <?= $stats['login_count'] ?? 0 ?></p>
                    <hr>
                    <a href="/member/profile" class="btn btn-sm btn-primary">View Profile</a>
                    <a href="/member/edit" class="btn btn-sm btn-outline-primary">Edit Profile</a>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions Card -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-lightning"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="/member/profile" class="btn btn-outline-success">
                            <i class="bi bi-person"></i> My Profile
                        </a>
                        <a href="/member/settings" class="btn btn-outline-success">
                            <i class="bi bi-gear"></i> Settings
                        </a>
                        <a href="/member/edit" class="btn btn-outline-success">
                            <i class="bi bi-pencil"></i> Edit Profile
                        </a>
                        <?php if (isset($member['level']) && $member['level'] <= 50): ?>
                        <a href="/admin" class="btn btn-outline-danger">
                            <i class="bi bi-shield-lock"></i> Admin Panel
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- System Info Card -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-info-circle"></i> System Information
                </div>
                <div class="card-body">
                    <p><strong>Application:</strong> TikNix Framework</p>
                    <p><strong>Version:</strong> 1.0.0</p>
                    <p><strong>Environment:</strong> <?= Flight::get('app.environment') ?? 'Development' ?></p>
                    <p><strong>Your Level:</strong> <?= $member['level'] ?? 'Unknown' ?></p>
                    
                    <?php if (isset($stats['total_members'])): ?>
                    <hr>
                    <p><strong>Total Members:</strong> <?= $stats['total_members'] ?></p>
                    <p><strong>Active Members:</strong> <?= $stats['active_members'] ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <i class="bi bi-journal-text"></i> Getting Started
                </div>
                <div class="card-body">
                    <h5>Welcome to TikNix!</h5>
                    <p>This is your main dashboard where you can access all the features available to you based on your permissions.</p>
                    
                    <h6 class="mt-3">Available Features:</h6>
                    <ul>
                        <li><strong>Profile Management:</strong> View and edit your personal information</li>
                        <li><strong>Settings:</strong> Customize your preferences and account settings</li>
                        <?php if (isset($member['level']) && $member['level'] <= 50): ?>
                        <li><strong>Admin Panel:</strong> Manage users, permissions, and system settings</li>
                        <?php endif; ?>
                    </ul>
                    
                    <h6 class="mt-3">Need Help?</h6>
                    <p>If you need assistance or have questions, please don't hesitate to reach out to our support team.</p>
                    
                    <div class="mt-3">
                        <a href="/help" class="btn btn-outline-secondary">
                            <i class="bi bi-question-circle"></i> Help Center
                        </a>
                        <a href="/contact" class="btn btn-outline-primary">
                            <i class="bi bi-envelope"></i> Contact Support
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    margin-bottom: 20px;
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
    transition: transform 0.2s;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
}

.alert-info {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
}

.btn-outline-success:hover,
.btn-outline-secondary:hover {
    transform: scale(1.02);
}
</style>
