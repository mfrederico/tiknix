<?php
/** @var ?string $scope @var string $label @var string $audienceLine @var ?int $used @var int $cap @var array $form @var array $problems @var array $abouts @var array $categories
 *  @var array $rows @var ?string $notice @var mixed $test @var bool $mayText @var ?string $backUrl */
$action = '/messaging/compose' . ($scope !== null ? '?scope=' . urlencode($scope) : '');
$tones = ['sent' => 'success', 'scheduled' => 'info', 'sending' => 'warning', 'draft' => 'secondary'];
$page = ['title' => 'Messages to ' . $label, 'lead' => $audienceLine];
if ($backUrl !== null) $page['back'] = ['label' => $scope !== null ? $label : 'Messages', 'url' => $backUrl];
?>
<?= \app\Ui::page($page) ?>
<?php if ($notice): ?><?= \app\Ui::notice(['tone' => 'info', 'text' => $notice]) ?><?php endif; ?>
<?= \app\Ui::form(['action' => $action, 'submit' => 'Send to members', 'lead' => $used === null ? 'There is no weekly limit on messages to everyone.' : $used . ' of ' . $cap . ' this week',
    'values' => $form, 'problems' => $problems, 'extra' => $test,
    'fields' => array_merge($categories ? [
        ['name' => 'category', 'label' => 'Kind of message', 'type' => 'select', 'options' => $categories, 'required' => true, 'help' => 'Members can switch each kind off in their settings.'],
        ] : [], $abouts ? [
        ['name' => 'about', 'label' => 'About', 'type' => 'select', 'options' => $abouts,
         'help' => 'Pick one, then "Fill in from the choice above" writes the subject and message for you. Sending it also schedules its reminder, where there is one.'],
        ] : [], [
        ['name' => 'subject', 'label' => 'Subject'],
        ['name' => 'body', 'label' => 'Message', 'type' => 'textarea', 'rows' => 8,
         'help' => 'You can use {first_name|friend}, and whatever this audience offers, like {community.name}. An unsubscribe link is added.'],
        ], $mayText ? [
        ['name' => 'body_sms', 'label' => 'Text message (optional)', 'type' => 'textarea', 'rows' => 3,
         'help' => 'Only members who asked for texts and confirmed their phone get it, and each text is paid for. Leave it empty to send no text. One part only (160 characters); "Reply STOP or:" and a stop link are added, so keep it short.'],
        ] : [], [
        ['name' => 'send', 'label' => 'When', 'type' => 'radio', 'options' => ['now' => 'Now', 'later' => 'At a time']],
        ['name' => 'scheduled_at', 'label' => 'Send it at', 'type' => 'datetime', 'more' => true],
    ])]) ?>
<?= \app\Ui::table(['title' => 'Sent and scheduled', 'rows' => $rows, 'count' => 'message',
    'columns' => [
        ['label' => 'Message', 'value' => 'subject', 'url' => fn($r) => '/messaging/message?id=' . $r->id],
        ['label' => 'Status', 'value' => 'status', 'as' => 'badge', 'tones' => $tones],
        ['label' => 'Sent', 'value' => 'sent'],
        ['label' => 'Delivered', 'value' => 'delivered', 'quiet' => true],
        ['label' => 'Clicked', 'value' => 'clicked', 'quiet' => true],
        ['label' => 'Acted', 'value' => 'acted', 'quiet' => true],
        ['label' => 'Replies', 'value' => 'replies', 'quiet' => true],
    ],
    'empty' => ['title' => 'No messages yet', 'text' => 'What you send to this audience, and what came of it, is listed here.']]) ?>
