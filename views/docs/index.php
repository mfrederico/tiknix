<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <!-- Sidebar Navigation Component -->
            <?php
            $activeSection = 'index';
            $quickLinks = [
                ['href' => '#features', 'icon' => 'bi-star', 'text' => 'Features'],
                ['href' => '#high-performance-caching-system', 'icon' => 'bi-lightning', 'text' => 'Caching System'],
                ['href' => '#quick-start', 'icon' => 'bi-rocket-takeoff', 'text' => 'Quick Start'],
                ['href' => '#project-structure', 'icon' => 'bi-folder-fill', 'text' => 'Project Structure'],
                ['href' => '#creating-controllers', 'icon' => 'bi-file-code', 'text' => 'Controllers'],
                ['href' => '#cli-support', 'icon' => 'bi-terminal-fill', 'text' => 'CLI Support'],
                ['href' => '#deployment-checklist', 'icon' => 'bi-cloud-upload', 'text' => 'Deployment']
            ];
            $showPerformanceBadge = true;
            include __DIR__ . '/partials/sidebar.php';
            ?>
        </div>

        <div class="col-lg-9 col-md-8">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-light px-3 py-2 rounded shadow-sm">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Documentation</li>
                </ol>
            </nav>

            <!-- Documentation Content -->
            <div class="documentation-content bg-white p-4 rounded shadow-sm">
                <?= $content ?>
            </div>

            <!-- Footer Info -->
            <div class="mt-4 p-3 bg-light rounded text-center small text-muted">
                <p class="mb-1">
                    <i class="bi bi-info-circle"></i>
                    TikNix Framework v1.0 |
                    PHP <?= phpversion() ?> |
                    <?= date('Y') ?>
                </p>
                <p class="mb-0">
                    <a href="https://github.com/mfrederico/tiknix" target="_blank" class="text-decoration-none">
                        <i class="bi bi-github"></i> View on GitHub
                    </a>
                </p>
            </div>

            <!-- Back to top button -->
            <button id="backToTop" class="btn btn-primary btn-floating" title="Back to top">
                <i class="bi bi-arrow-up"></i>
            </button>
        </div>
    </div>
</div>

<!-- Documentation styles are now in /public/css/app.css -->

<script>
// Back to top button functionality
window.addEventListener('scroll', function() {
    const backToTop = document.getElementById('backToTop');
    if (window.pageYOffset > 300) {
        backToTop.style.display = 'block';
    } else {
        backToTop.style.display = 'none';
    }
});

document.getElementById('backToTop').addEventListener('click', function() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            const offset = 80; // Account for sticky header
            const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - offset;
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        }
    });
});

// Active section highlighting
window.addEventListener('scroll', function() {
    const sections = document.querySelectorAll('h2[id], h3[id]');
    const navLinks = document.querySelectorAll('.nav-link[href^="#"]');

    let current = '';
    sections.forEach(section => {
        const sectionTop = section.offsetTop;
        const sectionHeight = section.clientHeight;
        if (pageYOffset >= (sectionTop - 100)) {
            current = section.getAttribute('id');
        }
    });

    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href').slice(1) === current) {
            link.classList.add('active');
        }
    });
});

// Code copy functionality
document.querySelectorAll('pre').forEach(pre => {
    const wrapper = document.createElement('div');
    wrapper.style.position = 'relative';
    pre.parentNode.insertBefore(wrapper, pre);
    wrapper.appendChild(pre);

    const button = document.createElement('button');
    button.className = 'btn btn-sm btn-outline-secondary';
    button.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
    button.style.position = 'absolute';
    button.style.top = '10px';
    button.style.right = '10px';

    button.addEventListener('click', function() {
        const code = pre.querySelector('code').textContent;
        navigator.clipboard.writeText(code).then(() => {
            button.innerHTML = '<i class="bi bi-check"></i> Copied!';
            setTimeout(() => {
                button.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
            }, 2000);
        });
    });

    wrapper.appendChild(button);
});
</script>