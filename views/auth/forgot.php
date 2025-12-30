<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h4>Forgot Password</h4>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Enter your email address and we'll send you a link to reset your password.
                    </p>

                    <form method="POST" action="/auth/doforgot">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" required autofocus
                                   placeholder="you@example.com">
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-envelope me-1"></i> Send Reset Link
                        </button>
                    </form>

                    <hr>

                    <div class="text-center">
                        <a href="/auth/login"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
