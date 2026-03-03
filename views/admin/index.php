<?php require_once '../layout/header.php'; ?>
<?php require_once '../layout/admin_nav.php'; ?>

<div class="container-fluid">
    <h1>Admin Dashboard</h1>
    
    <?php if (is_admin()): ?>
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">OpenAPI Tools</h5>
                        <p class="card-text">Manage your OpenAPI tool registry.</p>
                        <a href="/admin/openapi-tools" class="btn btn-primary">Go to Tool Registry</a>
                    </div>
                </div>
            </div>
            
            <!-- Other admin cards -->
        </div>
    <?php endif; ?>
    
    <!-- Main content for non-admin users -->
</div>

<?php require_once '../layout/footer.php'; ?>