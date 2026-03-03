<?php
/* Create admin interface for OpenAPI tool management */
require_once __DIR__ . '/../../bootstrap.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $openapiUrl = $_POST['openapi_url'] ?? '';
    
    if (empty($name) || empty($openapiUrl)) {
        $error = 'Name and OpenAPI URL are required';
    } else {
        // Validate URL format
        if (!filter_var($openapiUrl, FILTER_VALIDATE_URL)) {
            $error = 'Invalid OpenAPI URL format';
        } else {
            // Store in database
            $stmt = DB::prepare('INSERT INTO openapi_tools (name, description, openapi_url) VALUES (?, ?, ?)');
            $result = $stmt->execute([$name, $description, $openapiUrl]);
            
            if ($result) {
                success_redirect('/admin/openapi-tools', 'OpenAPI tool created successfully');
            } else {
                $error = 'Failed to create tool';
            }
        }
    }
}

// Get all tools for table
$toolsStmt = DB::prepare('SELECT * FROM openapi_tools ORDER BY name');
$toolsStmt->execute();
$tools = $toolsStmt->fetchAll();
?>
<div class="container-fluid mt-4">
    <h2>Manage OpenAPI Tools</h2>
    
    <?php if (isset($error)) : ?>
        <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <!-- Add new tool form -->
    <div class="card mb-4">
        <div class="card-header">Add New OpenAPI Tool</div>
        <div class="card-body">
            <form method="post" action="">
                <div class="mb-3">
                    <label for="name" class="form-label">Tool Name</label>
                    <input type="text" class="form-control" id="name" name="name" required>
                </div>
                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description"></textarea>
                </div>
                <div class="mb-3">
                    <label for="openapi_url" class="form-label">OpenAPI Spec URL</label>
                    <input type="url" class="form-control" id="openapi_url" name="openapi_url" required>
                </div>
                <button type="submit" class="btn btn-primary">Add Tool</button>
            </form>
        </div>
    </div>
    
    <!-- Tools table -->
    <div class="card">
        <div class="card-header">Configured OpenAPI Tools</div>
        <div class="card-body">
            <?php if (empty($tools)) : ?>
                <p>No tools configured yet.</p>
            <?php else : ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tools as $tool) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tool['name']) ?></td>
                                <td><?php echo htmlspecialchars($tool['description'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $tool['is_active'] ? 'success' : 'danger' ?>">
                                        <?php echo $tool['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <button data-tool-id="<?php echo $tool['id'] ?>" class="btn btn-sm btn-<?php echo $tool['is_active'] ? 'danger' : 'success' ?> toggle-status">
                                        <?php echo $tool['is_active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.toggle-status').forEach(btn => {
        btn.addEventListener('click', function() {
            const toolId = this.getAttribute('data-tool-id');
            const newStatus = !this.classList.contains('btn-success');
            
            fetch('/admin/api/toggle-openapi-tool-status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ id: toolId, is_active: newStatus })
            }).then(response => response.json())
              .then(data => {
                  if (data.success) {
                      this.classList.toggle('btn-success');
                      this.classList.toggle('btn-danger');
                      this.textContent = data.new_status === 1 ? 'Disable' : 'Enable';
                      
                      const badge = document.querySelector(`[data-tool-id="${toolId}"]`).querySelector('.badge');
                      badge.classList.remove('bg-success', 'bg-danger');
                      badge.classList.add(data.new_status === 1 ? 'bg-success' : 'bg-danger');
                      badge.textContent = data.new_status === 1 ? 'Active' : 'Inactive';
                      
                      toastSuccess(`Tool status updated to ${data.new_status === 1 ? 'active' : 'inactive'}`);
                  } else {
                      toastError('Failed to update tool status');
                  }
              }).catch(err => {
                  console.error(err);
                  toastError('Network error occurred');
              });
        });
    });
});
</script>