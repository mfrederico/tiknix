<!-- Top Utility Bar -->
<div style="background: #080b10; border-bottom: 1px solid rgba(48,54,61,0.6); padding: 6px 0; font-size: 0.8rem;">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <a href="tel:+18668457447" style="color: var(--text-secondary); text-decoration: none;">
                <i class="fas fa-phone" style="color: var(--primary-color); margin-right: 4px;"></i> (866) 845-7447
            </a>
            <span style="color: var(--text-secondary); opacity: 0.5;" class="d-none d-md-inline">|</span>
            <span style="color: var(--text-secondary);" class="d-none d-md-inline">Mon&ndash;Fri 9AM&ndash;5PM EST</span>
        </div>
        <div class="d-none d-md-flex align-items-center">
            <a href="mailto:info@shipcannon.com" style="color: var(--text-secondary); text-decoration: none;">
                <i class="fas fa-envelope" style="color: var(--primary-color); margin-right: 4px;"></i> info@shipcannon.com
            </a>
        </div>
    </div>
</div>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container">
        <a class="navbar-brand" href="/">
            <img src="/images/NEW-ShipCannon_header_footer.webp" alt="ShipCannon Logo" style="height: 40px;">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="/">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="/#why-cannon">Why CannonWMS</a></li>
                <li class="nav-item"><a class="nav-link" href="/#integrations">Integrations</a></li>
                <li class="nav-item"><a class="nav-link" href="/pricing">Pricing</a></li>
                <li class="nav-item"><a class="nav-link" href="/contact-us">Contact</a></li>
                <li class="nav-item"><a class="nav-link" href="/blog">Blog</a></li>
            </ul>
            <div class="ms-3">
                <a href="/login" class="btn btn-outline-primary me-2">Login</a>
                <a href="/get-started" class="btn btn-primary">Start Free Trial</a>
            </div>
        </div>
    </div>
</nav>

<?php if (($currentPage ?? '') !== 'pricing'): ?>
<div class="sticky-cta">
    <a href="/pricing" class="btn btn-primary btn-lg">
        <i class="fas fa-calculator"></i> See Pricing
    </a>
</div>
<?php endif; ?>
