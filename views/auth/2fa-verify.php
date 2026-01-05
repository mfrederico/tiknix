<?php
/**
 * Two-Factor Authentication Verification
 * Shown after password login when 2FA is enabled
 */
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow">
                <div class="card-header bg-primary text-white text-center">
                    <h4 class="mb-0"><i class="bi bi-shield-check me-2"></i>Two-Factor Authentication</h4>
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

                    <p class="text-center text-muted mb-4">
                        Enter the verification code from your authenticator app
                    </p>

                    <form method="POST" action="/auth/twofaverify" class="needs-validation" novalidate>
                        <?= \app\SimpleCsrf::getTokenField() ?>

                        <div class="mb-4">
                            <label for="code" class="form-label">Verification Code</label>
                            <input type="text"
                                   class="form-control form-control-lg text-center"
                                   id="code"
                                   name="code"
                                   maxlength="14"
                                   placeholder="000000"
                                   autocomplete="one-time-code"
                                   inputmode="numeric"
                                   required
                                   autofocus>
                            <div class="form-text text-center">
                                Enter your 6-digit code or recovery code
                            </div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="trust_device" name="trust_device" value="1" checked>
                            <label class="form-check-label" for="trust_device">
                                Trust this device for 30 days
                            </label>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-unlock me-2"></i>Verify
                            </button>
                            <a href="/auth/logout" class="btn btn-outline-secondary">
                                <i class="bi bi-box-arrow-left me-2"></i>Cancel and Logout
                            </a>
                        </div>
                    </form>

                    <hr>

                    <div class="text-center">
                        <a href="#" data-bs-toggle="collapse" data-bs-target="#recoveryHelp" class="text-muted small">
                            <i class="bi bi-question-circle me-1"></i>Lost access to your authenticator?
                        </a>
                        <div class="collapse mt-3" id="recoveryHelp">
                            <div class="alert alert-secondary small">
                                <p class="mb-2">You can use one of your recovery codes instead of the authenticator code.</p>
                                <p class="mb-0">Recovery codes are in the format: <code>XXXX-XXXX-XXXX</code></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
