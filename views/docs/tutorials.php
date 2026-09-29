<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <?php
            $activeSection = 'tutorials';
            include __DIR__ . '/partials/sidebar.php';
            ?>
        </div>

        <div class="col-lg-9 col-md-8">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-light px-3 py-2 rounded shadow-sm">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/docs">Documentation</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Tutorials</li>
                </ol>
            </nav>

            <!-- Hero Section -->
            <div class="card bg-primary text-white mb-4 shadow">
                <div class="card-body text-center py-5">
                    <h1 class="display-5 fw-bold mb-3">
                        <i class="bi bi-mortarboard"></i> Tiknix Tutorials
                    </h1>
                    <p class="lead mb-4">
                        Learn to build web apps fast with step-by-step guides
                    </p>
                    <a href="https://github.com/mfrederico/tiknix-tutorials"
                       target="_blank"
                       class="btn btn-light btn-lg">
                        <i class="bi bi-github"></i> View on GitHub
                    </a>
                </div>
            </div>

            <!-- Featured Tutorial -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-star-fill"></i> Featured: Build a Multi-User TODO App</h5>
                </div>
                <div class="card-body">
                    <p class="lead">
                        Go from zero to a fully functional TODO application in about 90 minutes.
                    </p>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-clock text-primary fs-4 me-2"></i>
                                <div>
                                    <strong>~90 minutes</strong><br>
                                    <small class="text-muted">Total time</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-journal-text text-primary fs-4 me-2"></i>
                                <div>
                                    <strong>9 lessons</strong><br>
                                    <small class="text-muted">Step by step</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-person-check text-primary fs-4 me-2"></i>
                                <div>
                                    <strong>Beginner</strong><br>
                                    <small class="text-muted">Skill level</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h6>What You'll Learn:</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li><i class="bi bi-check-circle text-success"></i> Auto-routing system</li>
                                <li><i class="bi bi-check-circle text-success"></i> Controllers & views</li>
                                <li><i class="bi bi-check-circle text-success"></i> RedBeanPHP database ops</li>
                                <li><i class="bi bi-check-circle text-success"></i> Forms & CSRF protection</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li><i class="bi bi-check-circle text-success"></i> Permission system</li>
                                <li><i class="bi bi-check-circle text-success"></i> User authentication</li>
                                <li><i class="bi bi-check-circle text-success"></i> Multi-user data isolation</li>
                                <li><i class="bi bi-check-circle text-success"></i> Complete CRUD operations</li>
                            </ul>
                        </div>
                    </div>

                    <a href="https://github.com/mfrederico/tiknix-tutorials/tree/main/lessons/00-introduction"
                       target="_blank"
                       class="btn btn-success btn-lg mt-3">
                        <i class="bi bi-play-circle"></i> Start Tutorial
                    </a>
                </div>
            </div>

            <!-- Lesson Overview -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-list-ol"></i> Lesson Overview</h5>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>00. Introduction</strong>
                            <br><small class="text-muted">What Tiknix is and how it works</small>
                        </div>
                        <span class="badge bg-secondary">5 min</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>01. Setup</strong>
                            <br><small class="text-muted">Install and configure Tiknix</small>
                        </div>
                        <span class="badge bg-secondary">10 min</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>02. Auto-Routing</strong>
                            <br><small class="text-muted">How URLs map to controllers</small>
                        </div>
                        <span class="badge bg-secondary">5 min</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>03. First Controller</strong>
                            <br><small class="text-muted">Create your Todo controller</small>
                        </div>
                        <span class="badge bg-secondary">10 min</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>04. Database Basics</strong>
                            <br><small class="text-muted">RedBeanPHP CRUD operations</small>
                        </div>
                        <span class="badge bg-secondary">10 min</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>05. Views & Forms</strong>
                            <br><small class="text-muted">Bootstrap UI and CSRF tokens</small>
                        </div>
                        <span class="badge bg-secondary">15 min</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>06. Permissions</strong>
                            <br><small class="text-muted">Auth levels and access control</small>
                        </div>
                        <span class="badge bg-secondary">10 min</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>07. CRUD Complete</strong>
                            <br><small class="text-muted">Edit, delete, toggle complete</small>
                        </div>
                        <span class="badge bg-secondary">15 min</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>08. Next Steps</strong>
                            <br><small class="text-muted">Where to go from here</small>
                        </div>
                        <span class="badge bg-secondary">5 min</span>
                    </li>
                </ul>
            </div>

            <!-- AI Assistant Note -->
            <div class="card border-info shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-robot"></i> Using AI Assistants?</h5>
                </div>
                <div class="card-body">
                    <p>
                        The tutorial repo includes <code>.claude/TIKNIX_GUIDE.md</code> - a reference file
                        that helps AI tools like Claude Code understand Tiknix patterns.
                    </p>
                    <p class="mb-0">
                        This reduces token usage and improves code suggestions when building with Tiknix.
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div class="mt-4 p-3 bg-light rounded text-center small text-muted">
                <p class="mb-0">
                    <a href="https://github.com/mfrederico/tiknix-tutorials" target="_blank" class="text-decoration-none">
                        <i class="bi bi-github"></i> tiknix-tutorials
                    </a>
                    &nbsp;|&nbsp;
                    <a href="https://github.com/mfrederico/tiknix" target="_blank" class="text-decoration-none">
                        <i class="bi bi-github"></i> tiknix
                    </a>
                </p>
            </div>
        </div>
    </div>
</div>
