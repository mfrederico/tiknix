<?php
/**
 * 2FA Success - Store trust token in localStorage and redirect
 */
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow">
                <div class="card-header bg-success text-white text-center">
                    <h4 class="mb-0"><i class="bi bi-check-circle me-2"></i>Verification Successful</h4>
                </div>
                <div class="card-body text-center">
                    <div class="spinner-border text-success mb-3" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted">Redirecting...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    // Store trust token in localStorage
    const trustToken = <?= json_encode($trustToken ?? '') ?>;
    const redirect = <?= json_encode($redirect ?? '/dashboard') ?>;

    if (trustToken) {
        try {
            localStorage.setItem('tiknix_2fa_trust', trustToken);
        } catch (e) {
            console.warn('Could not store 2FA trust token:', e);
        }
    }

    // Redirect
    window.location.href = redirect;
})();
</script>
