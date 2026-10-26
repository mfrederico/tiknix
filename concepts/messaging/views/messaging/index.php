<?php
/** @var array $rows @var array $categories @var array $suppressed @var array $settings @var array $sites @var array $providers @var string[] $have @var string $serviceUrl */
$wired = [];
foreach ($providers as $key => [$label, $what]) $wired[$label] = in_array($key, $have, true) ? 'Connected — ' . $what : 'Not connected — ' . $what;
$h = fn($v) => htmlspecialchars((string) $v);
?>
<?= \app\Ui::page(['title' => 'Messages', 'lead' => 'Everything owners and the platform send to members, and what came of it. Sent by the messaging service with this app\'s own accounts.',
    'more' => [['label' => 'The service', 'url' => $serviceUrl . '/messaging', 'icon' => 'box-arrow-up-right']]]) ?>
<?php if (!$sites): ?>
<?= \app\Ui::notice(['tone' => 'warning', 'title' => 'No site has a mail connection in the service yet.', 'text' => 'Share this app\'s Mailgun connection from a terminal: php scripts/clitool.php --connection-share=mailgun --with=messaging --site=<host>. Nothing is sent until then.']) ?>
<?php endif; ?>
<?= \app\Ui::table(['title' => 'Every message', 'rows' => $rows, 'count' => 'message',
    'columns' => [
        ['label' => 'Message', 'value' => 'subject', 'sub' => 'where', 'url' => fn($r) => '/messaging/view?id=' . $r->id],
        ['label' => 'Status', 'value' => 'status', 'as' => 'badge', 'tones' => ['sent' => 'success', 'scheduled' => 'info', 'active' => 'info', 'sending' => 'warning', 'paused' => 'secondary', 'draft' => 'secondary']],
        ['label' => 'Sent', 'value' => 'sent'],
        ['label' => 'Delivered', 'value' => 'delivered', 'quiet' => true],
        ['label' => 'Clicked', 'value' => 'clicked', 'quiet' => true],
        ['label' => 'Acted', 'value' => 'acted', 'quiet' => true],
        ['label' => 'Replies', 'value' => 'replies', 'quiet' => true],
    ],
    'empty' => ['title' => 'Nothing sent yet', 'text' => 'Messages appear here when an owner sends one or the platform\'s own run.']]) ?>
<?php foreach ($sites as $s): $items = ['Sends as' => $s['from_name'] !== '' ? $s['from_name'] : '(the project\'s name)', 'Replies to' => $s['reply_domain'] !== '' ? 'reply-…@' . $s['reply_domain'] : 'not tracked (no reply domain)',
    'Mail connection' => in_array('mailgun', $s['connections_live'], true) ? ($s['signing_key_present'] ? 'Mailgun, with its webhook signing key' : 'Mailgun — NO webhook signing key: delivery events and replies are refused until the connection is shared again with it') : 'none shared']; ?>
<?= \app\Ui::detail(['title' => 'Site ' . $s['host'], 'items' => $items + (isset($s['webhook_url']) ? ['Mailgun calls' => \app\Ui::raw('<code class="user-select-all">' . $h($s['webhook_url']) . '</code> — paste it into Mailgun\'s webhooks and the reply route')] : [])]) ?>
<?= \app\Ui::form(['action' => '/messaging/site', 'submit' => 'Save for ' . $s['host'], 'hidden' => ['host' => $s['host']], 'values' => ['from_name' => $s['from_name'], 'reply_domain' => $s['reply_domain']],
    'fields' => [['name' => 'from_name', 'label' => 'From name', 'width' => 6, 'help' => 'Empty: the project\'s name.'],
                 ['name' => 'reply_domain', 'label' => 'Reply domain', 'width' => 6, 'help' => 'Replies come back to reply-…@ here, through a Mailgun route to the address above. Empty: replies go to the from-address, untracked.']]]) ?>
<?php endforeach; ?>
<?= \app\Ui::table(['title' => 'Kinds of message and their defaults', 'rows' => $categories, 'count' => 'kind',
    'columns' => [
        ['label' => 'Kind', 'value' => 'label', 'sub' => 'description'],
        ['label' => 'For a new member', 'value' => 'default'],
        ['label' => 'Choice', 'value' => 'optout', 'quiet' => true],
        ['label' => 'Channels', 'value' => 'channels', 'quiet' => true],
    ],
    'empty' => ['title' => 'No kinds of message', 'text' => 'Transactional and reminders exist in every tenant; the app registers its own (Providers categories).']]) ?>
<?= \app\Ui::detail(['title' => 'What this app has handed the plugin', 'items' => $wired]) ?>
<?= \app\Ui::form(['title' => 'How much an owner may send', 'action' => '/messaging/cap', 'submit' => 'Save the limit', 'values' => ['cap' => (int) $settings['weekly_cap']],
    'fields' => [['name' => 'cap', 'label' => 'Messages per audience each week', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 50, 'help' => 'Counted over the last 7 days, per audience.']]]) ?>
<?= \app\Ui::form(['title' => 'Texts from owners', 'action' => '/messaging/texting', 'submit' => 'Save', 'values' => ['owners_may_text' => $settings['owners_may_text'] ? '1' : '0', 'text_link_base' => $settings['text_link_base']],
    'lead' => !in_array('phone', $have, true) ? 'This app has not connected phone numbers (the phone provider), so nobody can ask for texts.' : 'Only members who asked for texts and confirmed their phone are texted. Texting through the service is not switched on yet: texts are recorded, not sent.',
    'fields' => [['name' => 'owners_may_text', 'label' => 'Owners may text members', 'type' => 'radio', 'options' => ['0' => 'No', '1' => 'Yes'], 'help' => 'Reminders text those who asked for them either way.'],
        ['name' => 'text_link_base', 'label' => 'Short address for links in texts', 'help' => 'A text may be one part (160 characters) and carries two links, so a long address does not fit. A short one that reaches the service, like https://go.example.com, or empty.']]]) ?>
<?= \app\Ui::table(['title' => 'Addresses that are not mailed (' . count($suppressed) . ')', 'rows' => $suppressed, 'count' => 'address',
    'columns' => [
        ['label' => 'Address', 'value' => 'email'],
        ['label' => 'Why', 'value' => 'reason', 'as' => 'badge', 'tones' => ['bounced' => 'warning', 'complained' => 'danger', 'unsubscribed_all' => 'secondary', 'by_app' => 'secondary']],
        ['label' => 'Since', 'value' => 'at', 'as' => 'date', 'quiet' => true],
    ],
    'actions' => fn($r) => $r->global ? [] : [['label' => 'Remove', 'post' => '/messaging/unsuppress', 'fields' => ['id' => $r->id], 'confirm' => 'Remove ' . $r->email . '? It can be mailed again, even if it bounced.', 'danger' => true]],
    'empty' => ['title' => 'No suppressed addresses', 'text' => 'Bounces, complaints and unsubscribe-from-all land here. A complaint is kept for every project and cannot be removed here.']]) ?>
