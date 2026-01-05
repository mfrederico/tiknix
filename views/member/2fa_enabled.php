<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-shield-check"></i> Two-Factor Authentication Enabled</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <strong><i class="bi bi-exclamation-triangle"></i> Save these recovery codes!</strong>
                        <p class="mb-0 mt-2">
                            Store these codes in a safe place. Each code can only be used once.
                            If you lose access to your authenticator app, you can use these codes to sign in.
                        </p>
                    </div>

                    <div class="card bg-dark mb-3">
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($recovery_codes as $code): ?>
                                    <div class="col-6 mb-2">
                                        <code class="fs-6"><?= htmlspecialchars($code) ?></code>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="downloadCodes()">
                            <i class="bi bi-download"></i> Download Codes
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="copyCodes()">
                            <i class="bi bi-clipboard"></i> Copy to Clipboard
                        </button>
                        <a href="/member/settings" class="btn btn-primary">Done</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const recoveryCodes = <?= json_encode($recovery_codes) ?>;

function downloadCodes() {
    const text = "Tiknix Recovery Codes\n" +
                 "Generated: " + new Date().toISOString() + "\n\n" +
                 recoveryCodes.join("\n") + "\n\n" +
                 "Each code can only be used once.\nStore these codes securely.";

    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'tiknix-recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
}

function copyCodes() {
    const text = recoveryCodes.join("\n");
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
        setTimeout(() => btn.innerHTML = originalHtml, 2000);
    });
}
</script>
