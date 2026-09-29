<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <!-- Sidebar Navigation Component -->
            <?php
            $activeSection = 'caching';
            $quickLinks = [
                ['href' => '#overview', 'icon' => 'bi-eye', 'text' => 'Overview'],
                ['href' => '#architecture', 'icon' => 'bi-diagram-3', 'text' => 'Architecture'],
                ['href' => '#component-details', 'icon' => 'bi-puzzle', 'text' => 'Components'],
                ['href' => '#installation', 'icon' => 'bi-download', 'text' => 'Installation'],
                ['href' => '#performance-tuning', 'icon' => 'bi-speedometer2', 'text' => 'Performance'],
                ['href' => '#best-practices', 'icon' => 'bi-trophy', 'text' => 'Best Practices']
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
                    <li class="breadcrumb-item"><a href="/docs">Documentation</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Caching System</li>
                </ol>
            </nav>

            <!-- Documentation Content -->
            <div class="documentation-content bg-white p-4 rounded shadow-sm">
                <?= $content ?>
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
// Back to top button
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
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});
</script>