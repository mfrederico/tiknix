<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Setup Two-Factor Authentication</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <p>Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.):</p>

                    <div class="text-center mb-4">
                        <?= $qr_code ?>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Or enter this code manually:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($secret) ?>" readonly id="secretKey">
                            <button class="btn btn-outline-secondary" type="button" onclick="copySecret()">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>

                    <hr>

                    <form method="POST" action="/member/enable2fa">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="code" class="form-label">Enter the 6-digit code from your app:</label>
                            <input type="text" class="form-control form-control-lg text-center font-monospace"
                                   id="code" name="code" maxlength="6" pattern="[0-9]{6}"
                                   placeholder="000000" required autofocus autocomplete="off">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">Verify and Enable 2FA</button>
                            <a href="/member/settings" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copySecret() {
    const input = document.getElementById('secretKey');
    input.select();
    document.execCommand('copy');

    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i>';
    setTimeout(() => btn.innerHTML = originalHtml, 2000);
}
</script>
