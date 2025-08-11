<!-- Footer -->
<footer class="footer mt-auto py-3 bg-secondary text-light">
    <div class="container">
        <div class="row">
            <!-- About Section -->
            <div class="col-md-4">
                <h5><?= htmlspecialchars($site_name ?? 'MyApp') ?></h5>
                <p class="text-muted">
                    <?= htmlspecialchars($site_description ?? 'A modern PHP application built with Flight, RedBean, and Bootstrap.') ?>
                </p>
            </div>
            
            <!-- Quick Links -->
            <div class="col-md-4">
                <h5>Quick Links</h5>
                <ul class="list-unstyled">
                    <li><a href="/" class="text-muted text-decoration-none">Home</a></li>
                    <li><a href="/docs" class="text-muted text-decoration-none">Documentation</a></li>
                    <li><a href="/help" class="text-muted text-decoration-none">Help Center</a></li>
                    <li><a href="/contact" class="text-muted text-decoration-none">Contact</a></li>
                    <li><a href="/privacy" class="text-muted text-decoration-none">Privacy Policy</a></li>
                    <li><a href="/terms" class="text-muted text-decoration-none">Terms of Service</a></li>
                </ul>
            </div>
            
            <!-- Contact Info -->
            <div class="col-md-4">
                <h5>Connect</h5>
                <div class="d-flex gap-3">
                    <?php if (isset($social_links)): ?>
                        <?php foreach ($social_links as $social): ?>
                            <a href="<?= $social['url'] ?>" class="text-muted" target="_blank" rel="noopener">
                                <i class="bi bi-<?= $social['icon'] ?> fs-4"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if (isset($contact_email)): ?>
                    <p class="mt-3 text-muted">
                        <i class="bi bi-envelope"></i> <?= htmlspecialchars($contact_email) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        
        <hr class="my-4 bg-secondary">
        
        <!-- Copyright -->
        <div class="row">
            <div class="col-md-6">
                <p class="text-muted mb-0">
                    &copy; <?= date('Y') ?> <?= htmlspecialchars($site_name ?? 'MyApp') ?>. All rights reserved.
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <p class="text-muted mb-0">
                    Built with <i class="bi bi-heart-fill text-danger"></i> using 
                    <a href="https://flightphp.com" class="text-muted" target="_blank">Flight</a> &amp; 
                    <a href="https://redbeanphp.com" class="text-muted" target="_blank">RedBean</a>
                </p>
            </div>
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<button type="button" class="btn btn-primary btn-floating btn-lg" id="btn-back-to-top" style="position: fixed; bottom: 20px; right: 20px; display: none;">
    <i class="bi bi-arrow-up"></i>
</button>

<script>
// Back to top button
window.onscroll = function() {
    if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
        document.getElementById("btn-back-to-top").style.display = "block";
    } else {
        document.getElementById("btn-back-to-top").style.display = "none";
    }
};

document.getElementById("btn-back-to-top").addEventListener("click", function() {
    document.body.scrollTop = 0;
    document.documentElement.scrollTop = 0;
});

// Toast notification function
function showToast(type, message) {
    const toastEl = document.getElementById('liveToast');
    const toast = new bootstrap.Toast(toastEl);
    const toastBody = toastEl.querySelector('.toast-body');
    const toastHeader = toastEl.querySelector('.toast-header');
    
    // Set message
    toastBody.textContent = message;
    
    // Set type styling
    toastHeader.className = 'toast-header';
    if (type === 'success') {
        toastHeader.classList.add('bg-success', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-check-circle me-2';
    } else if (type === 'error') {
        toastHeader.classList.add('bg-danger', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-x-circle me-2';
    } else if (type === 'warning') {
        toastHeader.classList.add('bg-warning');
        toastHeader.querySelector('i').className = 'bi bi-exclamation-triangle me-2';
    } else {
        toastHeader.classList.add('bg-info', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-info-circle me-2';
    }
    
    toast.show();
}
</script>
