<!-- Hero Section -->
<div class="bg-primary text-white py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h1 class="display-4 fw-bold">Welcome to PHP Framework Starter</h1>
                <p class="lead">A modern, secure, and feature-rich PHP framework built with FlightPHP, RedBeanPHP, and Bootstrap 5.</p>
                <div class="d-grid gap-2 d-md-flex">
                    <?php if (!$isLoggedIn): ?>
                        <a href="/auth/register" class="btn btn-light btn-lg">Get Started</a>
                        <a href="/auth/login" class="btn btn-outline-light btn-lg">Login</a>
                    <?php else: ?>
                        <a href="/dashboard" class="btn btn-light btn-lg">Go to Dashboard</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6">
            </div>
        </div>
    </div>
</div>

<!-- Features Section -->
<div class="py-5">
    <div class="container">
        <h2 class="text-center mb-5">Framework Features</h2>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-shield-check text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Authentication System</h4>
                        <p class="card-text">Complete auth system with registration, login, password reset, and email verification.</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-lock text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Role-Based Permissions</h4>
                        <p class="card-text">Granular permission system with roles, levels, and automatic route protection.</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-lightning-charge text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Auto-Routing</h4>
                        <p class="card-text">Intelligent routing system that automatically maps URLs to controllers and methods.</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-database text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">RedBeanPHP ORM</h4>
                        <p class="card-text">Zero-config ORM that creates tables and columns on the fly.</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-bootstrap text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Bootstrap 5 UI</h4>
                        <p class="card-text">Modern, responsive UI with Bootstrap 5 and customizable themes.</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-gear text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Easy Configuration</h4>
                        <p class="card-text">Simple INI-based configuration with environment support.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tech Stack -->
<div class="bg-light py-5">
    <div class="container">
        <h2 class="text-center mb-5">Built With Modern Technologies</h2>
        <div class="row justify-content-center">
            <div class="col-md-8">
                <ul class="list-group">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>FlightPHP</strong> - Extensible micro-framework</span>
                        <span class="badge bg-primary rounded-pill">v3.0+</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>RedBeanPHP</strong> - Zero-config ORM</span>
                        <span class="badge bg-primary rounded-pill">v5.7+</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>Bootstrap</strong> - Responsive UI framework</span>
                        <span class="badge bg-primary rounded-pill">v5.3</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>Monolog</strong> - Comprehensive logging</span>
                        <span class="badge bg-primary rounded-pill">v3.5+</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>AntiCSRF</strong> - CSRF protection</span>
                        <span class="badge bg-primary rounded-pill">v2.3+</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- CTA Section -->
<div class="py-5">
    <div class="container text-center">
        <h2 class="mb-4">Ready to Build Your Next Project?</h2>
        <p class="lead mb-4">Get started with our comprehensive documentation and examples.</p>
        <div class="d-grid gap-2 d-md-flex justify-content-md-center">
            <a href="https://github.com/yourusername/php-framework-starter" class="btn btn-primary btn-lg">
                <i class="bi bi-github"></i> View on GitHub
            </a>
            <a href="/docs" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-book"></i> Documentation
            </a>
        </div>
    </div>
</div>
