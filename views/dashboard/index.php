<div class="ui-page-header d-flex justify-content-between align-items-end flex-wrap gap-2">
    <div>
        <span class="ui-eyebrow">Dashboard</span>
        <h1>Welcome back, <?= htmlspecialchars($member['username'] ?? 'User') ?></h1>
        <div class="ui-sub">Your central hub for every feature and tool available to you.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php /* Projects, Teams and the builder live on the CONTROL PLANE. A finished app
                 running on its own domain has none of them, and offering links that 404
                 is worse than offering nothing — so the dashboard shows what this system
                 actually has. */ ?>
        <?php if (builder_tools_enabled()): ?>
            <a href="/projects" class="btn btn-primary"><i class="bi bi-grid-3x3-gap"></i> Projects</a>
        <?php endif; ?>
        <a href="https://docs.tiknix.com" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="bi bi-book"></i> Read Tiknix docs</a>
    </div>
</div>

<?php if (builder_tools_enabled()): ?>
<!-- Workbench feature panel -->
<div class="ui-panel mb-4 feature-panel">
    <div class="ui-panel-body">
        <div class="row align-items-center g-3">
            <div class="col-md-8">
                <h3 class="text-primary mb-2"><i class="bi bi-hammer me-2"></i>Build Apps with Claude</h3>
                <p class="text-secondary mb-3">
                    Use Projects to create, manage, and deploy applications powered by Claude AI.
                    Define tasks, let Claude write the code, and watch your ideas come to life.
                </p>
                <ul class="list-unstyled mb-3 small">
                    <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Create feature requests, bug fixes, and refactoring tasks</li>
                    <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Claude writes code following your project conventions</li>
                    <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Collaborate with your team on shared tasks</li>
                </ul>
                <a href="/projects" class="btn btn-primary"><i class="bi bi-grid-3x3-gap"></i> Projects</a>
                <a href="/teams" class="btn btn-outline-primary ms-2"><i class="bi bi-people"></i> Manage Teams</a>
            </div>
            <div class="col-md-4 text-center d-none d-md-block">
                <i class="bi bi-cpu display-1 text-primary opacity-50"></i>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
                    <?php if (builder_tools_enabled()): ?>
                    <a href="/projects" class="btn btn-primary"><i class="bi bi-grid-3x3-gap"></i> Projects</a>
                    <a href="/teams" class="btn btn-outline-primary"><i class="bi bi-people"></i> My Teams</a>
                    <?php endif; ?>
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

<?php
/* This page is SCAFFOLDING. It ships with every instance so a fresh app has somewhere to
   land after login — but it is a starting point, not the product, and saying so plainly
   here is better than letting people assume the dashboard is fixed furniture they have to
   work around. Everything below tells them where it lives and how to take it over. */
?>
<div class="ui-panel mt-4">
    <div class="ui-panel-header"><h3><i class="bi bi-pencil-square text-primary me-2"></i>Make this page yours</h3></div>
    <div class="ui-panel-body">
        <p class="text-secondary mb-3">
            This dashboard is a starting point that ships with every app — it is meant to be replaced.
            It is one ordinary view file, so you can rewrite it, or point the route somewhere else entirely.
        </p>

        <div class="row g-3">
            <div class="col-md-6">
                <h6 class="mb-2"><i class="bi bi-1-circle text-primary me-1"></i> Rewrite this page</h6>
                <p class="text-secondary small mb-2">The whole page is one file. Replace its contents with yours.</p>
                <pre class="ui-mono small p-2 rounded bg-body-secondary mb-0">views/dashboard/index.php</pre>
            </div>
            <div class="col-md-6">
                <h6 class="mb-2"><i class="bi bi-2-circle text-primary me-1"></i> Or send people elsewhere</h6>
                <p class="text-secondary small mb-2">Add your own controller and make it the landing page. A file in <code>controls/</code> is routed by its name — <code>controls/Orders.php</code> serves <code>/orders</code>.</p>
                <pre class="ui-mono small p-2 rounded bg-body-secondary mb-0">controls/Orders.php  &rarr;  /orders</pre>
            </div>
        </div>

        <hr class="my-3">

        <h6 class="mb-2">A good first thing to build</h6>
        <p class="text-secondary small mb-2">
            Pick one real thing your app does and build the smallest version of it end to end — a list, a form
            that saves, a page that shows what was saved. Working software beats a plan, and you can extend it once
            it runs. Three pieces you will use every time:
        </p>
        <ul class="text-secondary small mb-3">
            <li><strong class="text-body">A controller</strong> in <code>controls/</code> — each public method is a URL.</li>
            <li><strong class="text-body">A view</strong> in <code>views/</code> — rendered with <code>$this-&gt;render('folder/name', $data)</code>.</li>
            <li><strong class="text-body">Data</strong> — no migrations to write; storing a bean creates its table.</li>
        </ul>
        <p class="text-secondary small mb-3">
            Every new route needs a permission row, or it is not reachable. That is deliberate: nothing is exposed
            by accident.
        </p>

        <div>
            <a href="https://docs.tiknix.com" target="_blank" rel="noopener" class="btn btn-primary"><i class="bi bi-book"></i> Read the docs</a>
            <a href="/help" class="btn btn-outline-secondary ms-2"><i class="bi bi-question-circle"></i> Help Center</a>
            <a href="/contact" class="btn btn-outline-primary ms-2"><i class="bi bi-envelope"></i> Contact Support</a>
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
