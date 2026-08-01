<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">

      <?php if (!empty($fatal)): ?>
        <?php /* Dead link. Say WHICH problem it is — expired, used, withdrawn — because the
                 only useful next step differs for each, and "invalid link" hides all three. */ ?>
        <div class="card shadow-sm">
          <div class="card-body text-center py-5">
            <i class="bi bi-envelope-slash fs-1 text-body-secondary"></i>
            <h1 class="h5 mt-3">This invitation can't be used</h1>
            <p class="text-body-secondary mb-4"><?= htmlspecialchars($fatal) ?></p>
            <a href="/auth/login" class="btn btn-outline-secondary">Go to sign in</a>
          </div>
        </div>
      <?php else: ?>

        <div class="text-center mb-4">
          <h1 class="h4 fw-bold mb-1">You've been invited to Tiknix</h1>
          <div class="text-body-secondary small">
            Sign-ups are closed — this invitation is your way in.
          </div>
        </div>

        <div class="card shadow-sm">
          <div class="card-body">

            <?php if (!empty($errors)): ?>
              <div class="alert alert-danger py-2">
                <?php foreach ($errors as $e): ?>
                  <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <form method="POST" action="/auth/invite">
              <?php foreach (($csrf ?? []) as $n => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($n) ?>" value="<?= htmlspecialchars($v) ?>">
              <?php endforeach; ?>
              <input type="hidden" name="token" value="<?= htmlspecialchars((string) ($invite->token ?? '')) ?>">

              <?php /* Shown, never offered. The address is what the invitation WAS — letting
                       it be edited would turn a link sent to one person into a signup for
                       anyone it was forwarded to. */ ?>
              <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" value="<?= htmlspecialchars((string) $email) ?>" disabled>
                <div class="form-text">Your account is created for this address.</div>
              </div>

              <div class="mb-3">
                <label for="username" class="form-label">Choose a username</label>
                <input type="text" class="form-control" id="username" name="username" required
                       autofocus autocomplete="username" pattern="[a-zA-Z0-9_.\-]{3,32}"
                       value="<?= htmlspecialchars((string) ($username ?? '')) ?>">
              </div>

              <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password"
                       required minlength="8" autocomplete="new-password">
                <div class="form-text">At least 8 characters.</div>
              </div>

              <div class="mb-4">
                <label for="password_confirm" class="form-label">Confirm password</label>
                <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                       required minlength="8" autocomplete="new-password">
              </div>

              <button type="submit" class="btn btn-primary w-100">Create my account</button>
            </form>
          </div>
        </div>

        <?php if (!empty($invite->expiresAt)): ?>
          <div class="text-center text-body-secondary small mt-3">
            This invitation expires on <?= htmlspecialchars(date('j M Y', strtotime((string) $invite->expiresAt))) ?>.
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>
