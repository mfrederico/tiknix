<?php
/**
 * Two-Factor Authentication Recovery Codes
 * Shown after successful 2FA setup
 */
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0"><i class="bi bi-check-circle me-2"></i>Two-Factor Authentication Enabled</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Important!</strong> Save these recovery codes in a safe place.
                        You will need them if you lose access to your authenticator app.
                    </div>

                    <h5>Your Recovery Codes</h5>
                    <p class="text-muted">Each code can only be used once.</p>

                    <div class="bg-light p-3 rounded mb-4">
                        <div class="row row-cols-2 g-2" id="recovery-codes">
                            <?php foreach ($recoveryCodes as $code): ?>
                                <div class="col">
                                    <code class="d-block text-center py-2 bg-white rounded border">
                                        <?= htmlspecialchars($code) ?>
                                    </code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mb-4">
                        <button type="button" class="btn btn-outline-secondary" onclick="copyRecoveryCodes()">
                            <i class="bi bi-clipboard me-1"></i>Copy Codes
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="downloadRecoveryCodes()">
                            <i class="bi bi-download me-1"></i>Download
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>These codes will not be shown again.</strong>
                        Store them securely before continuing.
                    </div>

                    <form method="POST" action="/auth/twofaconfirmsaved">
                        <?= \app\SimpleCsrf::getTokenField() ?>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="confirm_saved" name="confirm_saved" required>
                            <label class="form-check-label" for="confirm_saved">
                                I have saved my recovery codes in a safe place
                            </label>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-arrow-right me-2"></i>Continue to Dashboard
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyRecoveryCodes() {
    const codes = [];
    document.querySelectorAll('#recovery-codes code').forEach(el => {
        codes.push(el.textContent.trim());
    });
    navigator.clipboard.writeText(codes.join('\n')).then(() => {
        alert('Recovery codes copied to clipboard!');
    });
}

function downloadRecoveryCodes() {
    const codes = [];
    document.querySelectorAll('#recovery-codes code').forEach(el => {
        codes.push(el.textContent.trim());
    });

    const appName = '<?= htmlspecialchars(Flight::get('app.name') ?? 'Tiknix') ?>';
    const content = `${appName} Recovery Codes
Generated: <?= date('Y-m-d H:i:s') ?>

Keep these codes safe. Each code can only be used once.

${codes.join('\n')}
`;

    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'tiknix-recovery-codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<style>
@media print {
    .btn, .form-check, .alert-info {
        display: none !important;
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #ccc !important;
    }
}
</style>
