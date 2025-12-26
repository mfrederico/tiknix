<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="mb-4">
                <a href="/teams" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to Teams
                </a>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Create Team</h4>
                </div>
                <div class="card-body">
                    <?php
                    $flash = $_SESSION['flash'] ?? [];
                    unset($_SESSION['flash']);
                    foreach ($flash as $msg):
                    ?>
                        <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?>">
                            <?= htmlspecialchars($msg['message']) ?>
                        </div>
                    <?php endforeach; ?>

                    <form method="POST" action="/teams/store">
                        <input type="hidden" name="<?= $csrf['name'] ?>" value="<?= $csrf['value'] ?>">

                        <div class="mb-3">
                            <label for="name" class="form-label">Team Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required
                                   minlength="2" maxlength="100" placeholder="e.g., Frontend Team">
                            <div class="form-text">A unique name for your team (2-100 characters)</div>
                        </div>

                        <div class="mb-4">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"
                                      placeholder="What does this team work on?"></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Create Team
                            </button>
                            <a href="/teams" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-body">
                    <h6><i class="bi bi-info-circle"></i> About Teams</h6>
                    <ul class="mb-0 small text-muted">
                        <li>Teams allow you to share workbench tasks with others</li>
                        <li>As the owner, you can invite members and manage permissions</li>
                        <li>Team members can view, edit, and run tasks based on their role</li>
                        <li>You can create multiple teams for different projects</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
