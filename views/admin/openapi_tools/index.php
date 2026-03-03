<?php require_once '../layout/header.php'; ?>

<div class="container mt-5">
    <h1>OpenAPI Tools</h1>
    
    <div class="d-flex justify-content-between mb-3">
        <a href="/admin/openapi-tools/add" class="btn btn-primary">Add New Tool</a>
        <form id="filterForm" class="d-flex align-items-center">
            <input type="text" class="form-control me-2" id="searchInput" placeholder="Search tools...">
            <button type="submit" class="btn btn-outline-secondary">Filter</button>
        </form>
    </div>
    
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Spec URL</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="toolTableBody">
            <?php foreach ($activeTools as $tool): ?>
            <tr class="bg-success text-white">
                <td><?= htmlspecialchars($tool->name) ?></td>
                <td><?= htmlspecialchars(substr($tool->description ?: '', 0, 150)) . (strlen($tool->description) > 150 ? '...' : '') ?></td>
                <td><a href="<?= htmlspecialchars($tool->spec_url) ?>" target="_blank" class="text-white text-decoration-none">View Spec</a></td>
                <td>Active</td>
                <td>
                    <button data-id="<?= $tool->id ?>" class="btn btn-danger btn-sm toggle-status-btn">Deactivate</button>
                    <a href="/admin/openapi-tools/edit/<?= $tool->id ?>" class="btn btn-secondary btn-sm">Edit</a>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <?php foreach ($inactiveTools as $tool): ?>
            <tr class="bg-secondary text-white">
                <td><?= htmlspecialchars($tool->name) ?></td>
                <td><?= htmlspecialchars(substr($tool->description ?: '', 0, 150)) . (strlen($tool->description) > 150 ? '...' : '') ?></td>
                <td><a href="<?= htmlspecialchars($tool->spec_url) ?>" target="_blank" class="text-white text-decoration-none">View Spec</a></td>
                <td>Inactive</td>
                <td>
                    <button data-id="<?= $tool->id ?>" class="btn btn-success btn-sm toggle-status-btn">Activate</button>
                    <a href="/admin/openapi-tools/edit/<?= $tool->id ?>" class="btn btn-secondary btn-sm">Edit</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div id="messageContainer" class="mt-3"></div>
</div>

<?php require_once '../layout/footer.php'; ?>

<script>
document.querySelectorAll('.toggle-status-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const toolId = this.getAttribute('data-id');
        const actionText = this.textContent;
        
        fetch(`/admin/openapi-tools/toggle/${toolId}`, { method: 'POST' })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    btn.classList.toggle('btn-success');
                    btn.classList.toggle('btn-danger');
                    btn.textContent = actionText === 'Deactivate' ? 'Activate' : 'Deactivate';
                    
                    const row = this.closest('tr');
                    row.classList.toggle('bg-success');
                    row.classList.toggle('bg-secondary');
                    
                    const message = data.message.replace(/Tool '([^']+)'/, '<strong>$1</strong>');
                    showMessage(message, 'success');
                } else {
                    showMessage(data.error || 'Failed to toggle status', 'danger');
                }
            })
            .catch(() => showMessage('Network error', 'danger'));
    });
});

document.getElementById('filterForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const searchQuery = document.getElementById('searchInput').value.toLowerCase();
    
    document.querySelectorAll('#toolTableBody tr').forEach(row => {
        const name = row.querySelector('td:first-child').textContent.toLowerCase();
        if (name.includes(searchQuery)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

function showMessage(message, type) {
    const container = document.getElementById('messageContainer');
    container.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
}
</script>