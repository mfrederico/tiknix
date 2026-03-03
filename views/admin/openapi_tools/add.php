<?php require_once '../layout/header.php'; ?>

<div class="container mt-5">
    <h1>Add OpenAPI Tool</h1>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form id="addToolForm" method="post">
        <div class="mb-3">
            <label for="name" class="form-label">Name</label>
            <input type="text" class="form-control" id="name" name="name" required>
        </div>
        
        <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control" id="description" name="description" rows="4"></textarea>
        </div>
        
        <div class="mb-3">
            <label for="spec_url" class="form-label">OpenAPI Spec URL</label>
            <input type="url" class="form-control" id="spec_url" name="spec_url" required>
        </div>
        
        <button type="submit" class="btn btn-primary">Add Tool</button>
    </form>
</div>

<?php require_once '../layout/footer.php'; ?>

<script>
document.getElementById('addToolForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const form = this;
    const formData = new FormData(form);
    
    fetch('/admin/openapi-tools/add', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            form.reset();
        } else {
            showMessage(data.error || 'Failed to add tool', 'danger');
        }
    })
    .catch(() => showMessage('Network error', 'danger'));
});

function showMessage(message, type) {
    const container = document.getElementById('messageContainer');
    container.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
}
</script>