<?php
/**
 * First-run setup wizard. Vars: $errors (array), $username, $email (repopulate on error).
 */
$username = $username ?? 'admin';
$email    = $email ?? '';
?><!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set up tiknix</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
  <div class="container" style="max-width:520px">
    <div class="text-center my-4">
      <i class="bi bi-rocket-takeoff fs-1 text-primary"></i>
      <h1 class="h3 fw-bold mt-2 mb-1">Welcome to tiknix</h1>
      <p class="text-body-secondary">Create your administrator account to get started.</p>
    </div>

    <div class="card shadow-sm">
      <div class="card-body p-4">
        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger py-2">
            <ul class="mb-0 small"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>

        <form method="POST" action="/install/save">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Admin username</label>
            <input name="username" class="form-control" value="<?= htmlspecialchars($username) ?>" pattern="[A-Za-z0-9_.-]{2,50}" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Email address</label>
            <input name="email" type="email" class="form-control" value="<?= htmlspecialchars($email) ?>" placeholder="you@example.com" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Password</label>
            <input name="password" type="password" class="form-control" autocomplete="new-password" minlength="8" required>
            <div class="form-text">At least 8 characters.</div>
          </div>
          <div class="mb-4">
            <label class="form-label small fw-semibold">Confirm password</label>
            <input name="password_confirm" type="password" class="form-control" autocomplete="new-password" minlength="8" required>
          </div>
          <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>Create account &amp; finish</button>
        </form>
      </div>
    </div>
    <p class="text-center text-body-secondary small mt-3">This page appears only until setup is complete.</p>
  </div>
</body>
</html>
