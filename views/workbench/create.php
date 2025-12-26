<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="mb-4">
                <a href="/workbench" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to Workbench
                </a>
            </div>

            <?php
            $flash = $_SESSION['flash'] ?? [];
            unset($_SESSION['flash']);
            foreach ($flash as $msg):
            ?>
                <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?>">
                    <?= htmlspecialchars($msg['message']) ?>
                </div>
            <?php endforeach; ?>

            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Create Task</h4>
                </div>
                <div class="card-body">
                    <form method="POST" action="/workbench/store">
                        <input type="hidden" name="<?= $csrf['name'] ?>" value="<?= $csrf['value'] ?>">

                        <!-- Title -->
                        <div class="mb-3">
                            <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required
                                   placeholder="Describe what needs to be done">
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"
                                      placeholder="Provide detailed context for Claude..."></textarea>
                            <div class="form-text">Be specific about what you want. Include relevant code paths, requirements, and constraints.</div>
                        </div>

                        <div class="row">
                            <!-- Task Type -->
                            <div class="col-md-4 mb-3">
                                <label for="task_type" class="form-label">Type</label>
                                <select class="form-select" id="task_type" name="task_type">
                                    <?php foreach ($taskTypes as $type => $info): ?>
                                        <option value="<?= $type ?>">
                                            <?= $info['label'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Priority -->
                            <div class="col-md-4 mb-3">
                                <label for="priority" class="form-label">Priority</label>
                                <select class="form-select" id="priority" name="priority">
                                    <?php foreach ($priorities as $level => $info): ?>
                                        <option value="<?= $level ?>" <?= $level === 3 ? 'selected' : '' ?>>
                                            <?= $info['label'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Team -->
                            <div class="col-md-4 mb-3">
                                <label for="team_id" class="form-label">Team</label>
                                <select class="form-select" id="team_id" name="team_id">
                                    <option value="personal">Personal Task</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= $team['id'] ?>" <?= $preselectedTeamId == $team['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($team['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Acceptance Criteria -->
                        <div class="mb-3">
                            <label for="acceptance_criteria" class="form-label">Acceptance Criteria</label>
                            <textarea class="form-control" id="acceptance_criteria" name="acceptance_criteria" rows="3"
                                      placeholder="What conditions must be met for this task to be complete?"></textarea>
                            <div class="form-text">Claude will use these to verify the work is done correctly.</div>
                        </div>

                        <!-- Related Files -->
                        <div class="mb-3">
                            <label for="related_files" class="form-label">Related Files</label>
                            <textarea class="form-control font-monospace" id="related_files" name="related_files" rows="3"
                                      placeholder="src/controllers/UserController.php&#10;tests/UserTest.php"></textarea>
                            <div class="form-text">One file path per line. These will be prioritized during analysis.</div>
                        </div>

                        <!-- Tags -->
                        <div class="mb-4">
                            <label for="tags" class="form-label">Tags</label>
                            <input type="text" class="form-control" id="tags" name="tags"
                                   placeholder="api, authentication, backend">
                            <div class="form-text">Comma-separated tags for organization.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Create Task
                            </button>
                            <a href="/workbench" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
