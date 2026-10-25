<?php
/** @var \RedBeanPHP\OODBBean $m @var bool $system @var bool $locked @var array $form @var array $problems @var ?array $preview @var ?string $triggerLabel @var array $vars */
$list = fn(array $v) => 'You can use ' . implode(', ', array_map(fn($x) => '{' . $x . '}', $v)) . '. Write {name|a default} to say what to show when it is empty.';
?>
<?php if ($locked): ?>
<?= \app\Ui::page(['title' => 'This message has gone out', 'back' => ['label' => 'Message', 'url' => '/messaging/view?id=' . (int) $m->id]]) ?>
<?= \app\Ui::notice(['tone' => 'info', 'title' => 'It cannot be changed.', 'text' => 'It was sent as written. Here it is.']) ?>
<?= \app\Ui::detail(['title' => 'Message', 'items' => ['Subject' => (string) $m->subject, 'Email' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars((string) $m->bodyEmail) . '</div>'),
    'Text' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars((string) $m->bodyText) . '</div>')]]) ?>
<?php return; endif; ?>
<?= \app\Ui::page(['title' => 'Edit this message', 'back' => ['label' => 'Message', 'url' => '/messaging/view?id=' . (int) $m->id],
    'more' => [['label' => 'Send me a test', 'post' => '/messaging/test?id=' . (int) $m->id, 'fields' => [], 'icon' => 'send']]]) ?>
<?php if ($preview !== null): ?>
<?= \app\Ui::notice(['tone' => 'warning', 'title' => $preview['mail_ready'] ? 'The test was not sent.' : 'No mail is connected, so nothing was sent.', 'text' => 'This is the email as ' . htmlspecialchars($preview['subject']) . ' would reach you.']) ?>
<?= \app\Ui::detail(['title' => 'Test email', 'items' => ['Subject' => $preview['subject'],
    'Email' => \app\Ui::raw('<iframe sandbox title="Test email" style="width:100%;height:420px;border:1px solid #dee2e6" srcdoc="' . htmlspecialchars($preview['html'], ENT_QUOTES) . '"></iframe>'),
    'Text' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars($preview['text']) . '</div>')]]) ?>
<?php endif; ?>
<?php
$fields = [
    ['name' => 'subject', 'label' => 'Subject', 'required' => true, 'help' => $list($vars['subject'])],
    ['name' => 'body_email', 'label' => 'Message (email)', 'type' => 'textarea', 'rows' => 10, 'required' => true, 'help' => 'HTML is allowed. ' . $list($vars['body_email'])],
    ['name' => 'body_text', 'label' => 'Message (text version)', 'type' => 'textarea', 'rows' => 8, 'more' => true, 'help' => 'Plain text. Left empty, it is made from the email. ' . $list($vars['body_text'])],
];
if ($system) {
    if ($triggerLabel !== null) $fields[] = ['name' => 'trigger_days', 'label' => $triggerLabel, 'type' => 'number', 'min' => 1, 'required' => true, 'width' => 6];
    $fields[] = ['name' => 'cadence', 'label' => 'How often', 'type' => 'radio', 'options' => ['once' => 'Once', 'every_n_days' => 'Every N days']];
    $fields[] = ['name' => 'cadence_days', 'label' => 'N (days between messages)', 'type' => 'number', 'min' => 1, 'width' => 6, 'help' => 'Used when it repeats.'];
    $fields[] = ['name' => 'status', 'label' => 'Status', 'type' => 'radio', 'options' => ['active' => 'Active', 'paused' => 'Paused'], 'help' => 'A paused message is not sent to anyone.'];
}
?>
<?= \app\Ui::form(['action' => '/messaging/edit?id=' . (int) $m->id, 'submit' => 'Save this message', 'cancel' => '/messaging/view?id=' . (int) $m->id,
    'values' => $form, 'problems' => $problems, 'fields' => $fields]) ?>
