<?php
/**
 * Two-Factor Authentication Setup
 * Shows QR code for authenticator app setup
 */
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Set Up Two-Factor Authentication</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Two-factor authentication is required</strong> for your account level.
                        This adds an extra layer of security to protect your account.
                    </div>

                    <div class="text-center mb-4">
                        <h5>Step 1: Scan QR Code</h5>
                        <p class="text-muted">Use Google Authenticator, Authy, or any TOTP-compatible app</p>
                        <div class="qr-code-container bg-white p-3 d-inline-block rounded border">
                            <?= $qrCode ?>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h5>Step 2: Enter Verification Code</h5>
                        <p class="text-muted">Enter the 6-digit code from your authenticator app</p>

                        <form method="POST" action="/auth/twofasetup" class="needs-validation" novalidate>
                            <input type="hidden" name="secret" value="<?= htmlspecialchars($secret) ?>">
                            <?= \app\SimpleCsrf::getTokenField() ?>

                            <div class="mb-3">
                                <label for="code" class="form-label">Verification Code</label>
                                <input type="text"
                                       class="form-control form-control-lg text-center"
                                       id="code"
                                       name="code"
                                       maxlength="6"
                                       pattern="[0-9]{6}"
                                       placeholder="000000"
                                       autocomplete="one-time-code"
                                       inputmode="numeric"
                                       required
                                       autofocus>
                                <div class="form-text">Enter the 6-digit code shown in your authenticator app</div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-check-circle me-2"></i>Verify and Enable 2FA
                                </button>
                            </div>
                        </form>
                    </div>

                    <hr>

                    <div class="text-muted small">
                        <h6>Can't scan the QR code?</h6>
                        <p>Manually enter this key in your authenticator app:</p>
                        <code class="d-block bg-light p-2 rounded text-break"><?= htmlspecialchars($secret) ?></code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.qr-code-container svg {
    width: 200px;
    height: 200px;
}
</style>
