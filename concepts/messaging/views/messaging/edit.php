<?php
/** @var array $c @var bool $system @var bool $locked @var array $form @var array $problems @var ?array $preview @var ?string $triggerLabel */
$vars = ['{first_name}', '{unsubscribe_url}', '{prefs_url}'];
if ($system) $vars[] = '{headline}';
$list = fn(array $extra) => 'You can use ' . implode(', ', array_merge($vars, $extra)) . ', and what the audience offers (like {community.name}). Write {name|a default} to say what to show when it is empty.';
?>
<?php if ($locked): ?>
<?= \app\Ui::page(['title' => 'This message has gone out', 'back' => ['label' => 'Message', 'url' => '/messaging/view?id=' . (int) $c['id']]]) ?>
<?= \app\Ui::notice(['tone' => 'info', 'title' => 'It cannot be changed.', 'text' => 'It was sent as written. Here it is.']) ?>
<?= \app\Ui::detail(['title' => 'Message', 'items' => ['Subject' => $c['subject'], 'Email' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars($c['body_email']) . '</div>'),
    'Text' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars($c['body_text']) . '</div>')]]) ?>
<?php return; endif; ?>
<?= \app\Ui::page(['title' => 'Edit this message', 'back' => ['label' => 'Message', 'url' => '/messaging/view?id=' . (int) $c['id']],
    'more' => [['label' => 'Send me a test', 'post' => '/messaging/test?id=' . (int) $c['id'], 'fields' => [], 'icon' => 'send']]]) ?>
<?php if ($preview !== null): ?>
<?= \app\Ui::notice(['tone' => 'warning', 'title' => $preview['mail_ready'] ? 'The test was not sent.' : 'No mail is connected for this site in the service, so nothing was sent.', 'text' => 'This is the email as it would have gone out.']) ?>
<?= \app\Ui::detail(['title' => 'Test email', 'items' => ['Subject' => $preview['subject'],
    'Email' => \app\Ui::raw('<iframe sandbox title="Test email" style="width:100%;height:420px;border:1px solid #dee2e6" srcdoc="' . htmlspecialchars($preview['html'], ENT_QUOTES) . '"></iframe>'),
    'Text' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars($preview['text']) . '</div>')]]) ?>
<?php endif; ?>
<?php
$fields = [
    ['name' => 'subject', 'label' => 'Subject', 'required' => true, 'help' => $list([])],
    ['name' => 'body_email', 'label' => 'Message (email)', 'type' => 'textarea', 'rows' => 10, 'required' => true, 'help' => 'HTML is allowed. ' . $list($system ? ['{content_html}'] : [])],
    ['name' => 'body_text', 'label' => 'Message (text version)', 'type' => 'textarea', 'rows' => 8, 'more' => true, 'help' => 'Plain text. Left empty, it is made from the email. ' . $list($system ? ['{content_text}'] : [])],
];
if ($system) {
    if ($triggerLabel !== null) $fields[] = ['name' => 'trigger_days', 'label' => $triggerLabel, 'type' => 'number', 'min' => 1, 'required' => true, 'width' => 6];
    $fields[] = ['name' => 'cadence', 'label' => 'How often', 'type' => 'radio', 'options' => ['once' => 'Once', 'every_n_days' => 'Every N days']];
    $fields[] = ['name' => 'cadence_days', 'label' => 'N (days between messages)', 'type' => 'number', 'min' => 1, 'width' => 6, 'help' => 'Used when it repeats.'];
    $fields[] = ['name' => 'status', 'label' => 'Status', 'type' => 'radio', 'options' => ['active' => 'Active', 'paused' => 'Paused'], 'help' => 'A paused message is not sent to anyone.'];
}
?>
<?= \app\Ui::form(['action' => '/messaging/edit?id=' . (int) $c['id'], 'submit' => 'Save this message', 'cancel' => '/messaging/view?id=' . (int) $c['id'],
    'values' => $form, 'problems' => $problems, 'fields' => $fields]) ?>
