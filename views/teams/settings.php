<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="mb-4">
                <a href="/teams/view?id=<?= $team->id ?>" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to <?= htmlspecialchars($team->name) ?>
                </a>
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

            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="mb-0">Team Settings</h4>
                </div>
                <div class="card-body">
                    <form method="POST" action="/teams/update">
                        <input type="hidden" name="<?= $csrf['name'] ?>" value="<?= $csrf['value'] ?>">
                        <input type="hidden" name="id" value="<?= $team->id ?>">

                        <div class="mb-3">
                            <label for="name" class="form-label">Team Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required
                                   value="<?= htmlspecialchars($team->name) ?>"
                                   minlength="2" maxlength="100">
                        </div>

                        <div class="mb-3">
                            <label for="slug" class="form-label">Slug</label>
                            <input type="text" class="form-control" id="slug" value="<?= htmlspecialchars($team->slug) ?>" readonly disabled>
                            <div class="form-text">The team slug cannot be changed</div>
                        </div>

                        <div class="mb-4">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($team->description ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Team Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Team Information</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Created</dt>
                        <dd class="col-sm-8"><?= date('F j, Y', strtotime($team->createdAt)) ?></dd>

                        <dt class="col-sm-4">Team ID</dt>
                        <dd class="col-sm-8"><code><?= $team->id ?></code></dd>
                    </dl>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Danger Zone</h5>
                </div>
                <div class="card-body">
                    <h6>Delete Team</h6>
                    <p class="text-muted small">
                        Once you delete a team, there is no going back. All memberships and invitations will be removed.
                        Tasks will be converted to personal tasks for their original creators.
                    </p>
                    <form method="POST" action="/teams/delete" onsubmit="return confirmDelete();">
                        <input type="hidden" name="<?= $csrf['name'] ?>" value="<?= $csrf['value'] ?>">
                        <input type="hidden" name="id" value="<?= $team->id ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Delete Team
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete() {
    const teamName = <?= json_encode($team->name) ?>;
    const confirm1 = confirm('Are you sure you want to delete "' + teamName + '"?');
    if (!confirm1) return false;

    const confirm2 = confirm('This action cannot be undone. All members will be removed. Continue?');
    return confirm2;
}
</script>
