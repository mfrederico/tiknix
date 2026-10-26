<?php // ui: hand-made — one button page, for a link in mail sent before the service
/** @var string $state moved|ask|done|refused @var string $token @var string $what */
$h = fn($v) => htmlspecialchars((string) $v);
?>
<div class="page-col">
<?php if ($state === 'moved'): ?>
    <?= \app\Ui::page(['title' => 'This link has moved']) ?>
    <?= \app\Ui::notice(['tone' => 'info', 'title' => 'This unsubscribe link cannot be read any more.', 'text' => 'It is from before our messages moved, or it was cut off. To choose what reaches you, sign in and open your message choices.',
        'action' => ['label' => 'Choose what reaches you', 'url' => '/msgpref']]) ?>
<?php elseif ($state === 'ask'): ?>
    <?= \app\Ui::page(['title' => 'Stop these messages?', 'lead' => 'You will no longer get: ' . $what . '.']) ?>
    <form method="post" action="/msgpref/unsubscribe?t=<?= $h(urlencode($token)) ?>"><?= csrf_field() ?>
        <button class="btn btn-primary" type="submit">Stop these messages</button>
    </form>
<?php elseif ($state === 'done'): ?>
    <?= \app\Ui::page(['title' => 'Done', 'lead' => 'You will no longer get: ' . $what . '. You can turn it back on in your message choices.']) ?>
<?php else: ?>
    <?= \app\Ui::page(['title' => 'Not changed', 'lead' => $what]) ?>
    <?= \app\Ui::notice(['tone' => 'warning', 'text' => 'Sign in to choose what reaches you.', 'action' => ['label' => 'Choose what reaches you', 'url' => '/msgpref']]) ?>
<?php endif; ?>
</div>
