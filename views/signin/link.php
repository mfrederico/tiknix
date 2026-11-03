<?php /** @var string $token @var bool $valid @var bool $join */ ?>
<?php if ($valid): ?>
<?= \app\Ui::page(['title' => $join ? 'Create your account' : 'Sign in', 'lead' => $join ? 'Press the button: your email is confirmed and your free account is made. No password needed.' : 'Press the button to sign in. This link works once.']) ?>
<form method="post" action="/signin/go" class="mt-2"><?= csrf_field() ?><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-<?= $join ? 'person-check' : 'box-arrow-in-right' ?> me-1"></i><?= $join ? 'Create my account and continue' : 'Sign in' ?></button></form>
<?php else: ?>
<?= \app\Ui::page(['title' => 'This link has run out', 'lead' => 'A link works once, for 15 minutes. Send yourself a new one.',
    'primary' => ['label' => 'Send a new link', 'url' => '/signin', 'icon' => 'envelope']]) ?>
<?php endif; ?>
