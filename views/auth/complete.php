<?php
/**
 * Finish signing up — add a card, then the account is created.
 *
 * The copy has one job beyond instruction: be honest that no account exists yet. Someone
 * who abandons this page has not "half signed up", and telling them so is what stops the
 * support email asking why they cannot log in.
 *
 * Vars: $state ('waiting'|'error'), $error (string), $token (string), $portalUrl (string)
 */
?>
<div class="container py-5" style="max-width: 34rem;">

<?php if ($state === 'waiting'): ?>

  <h1 class="h3 mb-2">One more step</h1>
  <p class="text-muted">
    Add a card and we will create your account. <strong>Your first project is free</strong> —
    the card is on file so you are not interrupted later, and nothing is charged today.
  </p>

  <?php if ($portalUrl !== ''): ?>
    <a class="btn btn-primary btn-lg w-100 my-3" href="<?= htmlspecialchars($portalUrl) ?>">
      Add your card
    </a>
    <p class="small text-muted">
      Card details are entered on ClickSimple, the company behind Tiknix — that is the name
      you will see on your statement. We never see your card number.
    </p>
  <?php else: ?>
    <div class="alert alert-danger my-3">
      We could not open the secure card form. Nothing has been charged and no account was
      created. Please try registering again in a moment.
    </div>
  <?php endif; ?>

  <hr class="my-4">

  <p class="small text-muted mb-2">Already added it?</p>
  <form method="get" action="/auth/complete">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <button class="btn btn-outline-secondary" type="submit">Check again</button>
  </form>

  <p class="small text-muted mt-4 mb-0">
    <strong>No account exists yet.</strong> It is created the moment your card is confirmed.
    If you stop here, nothing is kept beyond <?= (int) \app\SignupFlow::EXPIRES_HOURS ?> hours
    and you can start again with the same email address.
  </p>

<?php else: ?>

  <h1 class="h3 mb-3">We could not finish signing you up</h1>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <p class="text-muted">
    Nothing has been charged and no account was created.
  </p>
  <a class="btn btn-primary" href="/auth/register">Start again</a>
  <a class="btn btn-link" href="/auth/login">I already have an account</a>

<?php endif; ?>
</div>
