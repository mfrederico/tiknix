<?php // ui: hand-made — one button page
/** @var string $state @var string $token @var string $what */
$h = fn($v) => htmlspecialchars((string) $v);
?>
<div class="page-col">
<?php if ($state === 'invalid'): ?>
    <?= \app\Ui::page(['title' => 'This link does not work']) ?>
    <?= \app\Ui::notice(['tone' => 'warning', 'title' => 'We could not read this link.', 'text' => 'It may be cut off or changed. Use the link in the message again, or change your choices in Settings.']) ?>
<?php elseif ($state === 'ask'): ?>
    <?= \app\Ui::page(['title' => 'Stop these messages?', 'lead' => 'You will no longer get: ' . $what . '.']) ?>
    <form method="post" action="/msgpref/unsubscribe?t=<?= $h(urlencode($token)) ?>"><?= csrf_field() ?>
        <button class="btn btn-primary" type="submit">Stop these messages</button>
    </form>
<?php elseif ($state === 'done'): ?>
    <?= \app\Ui::page(['title' => 'Done', 'lead' => 'You will no longer get: ' . $what . '. You can turn it back on in Settings.']) ?>
<?php else: ?>
    <?= \app\Ui::page(['title' => 'Already off', 'lead' => $what . ' is already switched off for you.']) ?>
<?php endif; ?>
</div>
