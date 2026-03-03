<?php require_once '../layout/header.php'; ?>

<div class="container mt-5">
    <h1>Edit OpenAPI Tool: <?= htmlspecialchars($tool->name) ?></h1>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form id="editToolForm" method="post">
        <input type="hidden" name="id" value="<?= $tool->id ?>">
        
        <div class="mb-3">
            <label for="name" class="form-label">Name</label>
            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($tool->name) ?>" required>
        </div>
        
        <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control" id="description" name="description" rows="4"><?= htmlspecialchars($tool->description ?: '') ?></textarea>
        </div>
        
        <div class="mb-3">
            <label for="spec_url" class="form-label">OpenAPI Spec URL</label>
            <input type="url" class="form-control" id="spec_url" name="spec_url" value="<?= htmlspecialchars($tool->spec_url) ?>" required>
        </div>
        
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>

<?php require_once '../layout/footer.php'; ?>

<script>
document.getElementById('editToolForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const form = this