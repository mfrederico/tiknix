<?php
/**
 * An emailed link's page. Opening it changes nothing (mail scanners and link previews fetch links, and a link works once):
 * the button does it. In a real browser the page presses the button itself, so the person never has to; something that only
 * fetches the page does not run that, and the button stays for a browser without script.
 * @var string $token @var bool $valid @var bool $join
 */
?>
<?php if ($valid): ?>
<?= \app\Ui::page(['title' => $join ? 'Thanks for signing up!' : 'Welcome back', 'lead' => $join ? 'Your email is confirmed. Next we make your free account and take you to your project.' : 'Your email is confirmed.']) ?>
<form method="post" action="/signin/go" class="mt-2" id="signin-go"><?= csrf_field() ?><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <p class="small text-body-secondary mb-2" id="signin-wait" hidden><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?= $join ? 'Making your account…' : 'Signing you in…' ?></p>
    <button type="submit" class="btn btn-primary btn-lg" id="signin-btn"><i class="bi bi-<?= $join ? 'person-check' : 'box-arrow-in-right' ?> me-1"></i><?= $join ? 'I&rsquo;m ready!' : 'Sign me in' ?></button></form>
<script>
(function () {
  // A person opened this in a browser: carry on for them. Once only, so going Back does not try a used link again.
  var f = document.getElementById('signin-go'), key = 'signin-go-' + f.querySelector('[name=token]').value.slice(0, 12);
  try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, '1'); } catch (e) {}
  document.getElementById('signin-btn').hidden = true; document.getElementById('signin-wait').hidden = false;
  f.submit();
})();
</script>
<?php else: ?>
<?= \app\Ui::page(['title' => 'This link has run out', 'lead' => 'A link works once, for 15 minutes. Send yourself a new one.',
    'primary' => ['label' => 'Send a new link', 'url' => '/signin', 'icon' => 'envelope']]) ?>
<?php endif; ?>
