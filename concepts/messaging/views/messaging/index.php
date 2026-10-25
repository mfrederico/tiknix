<?php
/** @var array $rows @var array $categories @var array $suppressed @var int $suppressedTotal @var int $cap @var bool $keySet @var bool $ownersText @var bool $textsOn @var string $textBase
 *  @var string $replyDomain @var string $siteDomain @var array $providers @var string[] $have @var bool $digestUnavailable */
$wired = [];
foreach ($providers as $key => [$label, $what]) $wired[$label] = in_array($key, $have, true) ? 'Connected — ' . $what : 'Not connected — ' . $what;
?>
<?= \app\Ui::page(['title' => 'Messages', 'lead' => 'Everything owners and the platform send to members, and what came of it.']) ?>
<?php if ($digestUnavailable): ?>
<?= \app\Ui::notice(['tone' => 'warning', 'title' => 'The weekly digest is unavailable.', 'text' => 'This app has not handed the plugin anything to put in it (the digest provider), so it never sends.']) ?>
<?php endif; ?>
<?= \app\Ui::table(['title' => 'Every message', 'rows' => $rows, 'count' => 'message',
    'columns' => [
        ['label' => 'Message', 'value' => 'subject', 'sub' => 'where', 'url' => fn($r) => '/messaging/view?id=' . $r->id],
        ['label' => 'Status', 'value' => 'status', 'as' => 'badge', 'tones' => ['sent' => 'success', 'scheduled' => 'info', 'active' => 'info', 'sending' => 'warning']],
        ['label' => 'Sent', 'value' => 'sent'],
        ['label' => 'Delivered', 'value' => 'delivered', 'quiet' => true],
        ['label' => 'Clicked', 'value' => 'clicked', 'quiet' => true],
        ['label' => 'Acted', 'value' => 'acted', 'quiet' => true],
        ['label' => 'Replies', 'value' => 'replies', 'quiet' => true],
    ],
    'empty' => ['title' => 'Nothing sent yet', 'text' => 'Messages appear here when an owner sends one or the platform\'s own run.']]) ?>
<?= \app\Ui::table(['title' => 'Kinds of message and their defaults', 'rows' => $categories, 'count' => 'kind',
    'columns' => [
        ['label' => 'Kind', 'value' => 'label', 'sub' => 'description'],
        ['label' => 'For a new member', 'value' => 'default'],
        ['label' => 'Choice', 'value' => 'optout', 'quiet' => true],
        ['label' => 'Channels', 'value' => 'channels', 'quiet' => true],
    ],
    'empty' => ['title' => 'No kinds of message', 'text' => 'They are made by the plugin\'s seeds (03_MessagingCategories) and the app\'s.']]) ?>
<?= \app\Ui::detail(['title' => 'What this app has handed the plugin', 'items' => $wired]) ?>
<?= \app\Ui::form(['title' => 'How much an owner may send', 'action' => '/messaging/cap', 'submit' => 'Save the limit', 'values' => ['cap' => $cap],
    'fields' => [['name' => 'cap', 'label' => 'Messages per audience each week', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 50, 'help' => 'Counted over the last 7 days, per audience.']]]) ?>
<?= \app\Ui::form(['title' => 'Texts from owners', 'action' => '/messaging/texting', 'submit' => 'Save', 'values' => ['owners_may_text' => $ownersText ? '1' : '0', 'text_link_base' => $textBase],
    'lead' => !in_array('phone', $have, true) ? 'This app has not connected phone numbers (the phone provider), so nobody can ask for texts.' : ($textsOn ? 'Only members who asked for texts and confirmed their phone are texted. Each text is paid for by the character.' : 'No text number is connected (Connections → Quo), so nothing can be texted yet.'),
    'fields' => [['name' => 'owners_may_text', 'label' => 'Owners may text members', 'type' => 'radio', 'options' => ['0' => 'No', '1' => 'Yes'], 'help' => 'Reminders text those who asked for them either way.'],
        ['name' => 'text_link_base', 'label' => 'Short address for links in texts', 'help' => 'A text may be one part (160 characters) and carries two links, so the site\'s own address is usually too long. A short address that reaches this app, like https://short.example. Empty uses the site address, and a text that does not fit is not sent.']]]) ?>
<?= \app\Ui::form(['title' => 'Mail domains', 'action' => '/messaging/domains', 'submit' => 'Save the domains', 'values' => ['reply_domain' => $replyDomain, 'site_domain' => $siteDomain],
    'lead' => $replyDomain === '' ? 'The reply domain is not set: nothing can be sent until it is.' : 'Replies to a message come back to reply-…@ the reply domain.',
    'fields' => [['name' => 'reply_domain', 'label' => 'Reply domain', 'required' => true, 'help' => 'The domain your Mailgun route forwards to /webhook/mailgun, like example.com.'],
        ['name' => 'site_domain', 'label' => 'Sending site', 'more' => true, 'help' => 'Only for an install with several sites: the one whose Mailgun connection sends these messages.']]]) ?>
<?= \app\Ui::form(['title' => 'Mailgun webhook key', 'action' => '/messaging/webhookkey', 'submit' => 'Save the key', 'values' => [],
    'lead' => $keySet ? 'The key is set.' : 'The key is not set: delivery events and replies are refused until it is.',
    'fields' => [['name' => 'key', 'label' => 'New key', 'type' => 'password', 'autocomplete' => 'new-password', 'help' => 'The same value as ?key= in the Mailgun route\'s address. It is never shown again.']]]) ?>
<?= \app\Ui::table(['title' => 'Addresses that are not mailed (' . $suppressedTotal . ')', 'rows' => $suppressed, 'count' => 'address',
    'columns' => [
        ['label' => 'Address', 'value' => 'email'],
        ['label' => 'Why', 'value' => 'reason', 'as' => 'badge', 'tones' => ['bounced' => 'warning', 'complained' => 'danger', 'unsubscribed_all' => 'secondary']],
        ['label' => 'Since', 'value' => 'at', 'as' => 'date', 'quiet' => true],
    ],
    'actions' => fn($r) => [['label' => 'Remove', 'post' => '/messaging/unsuppress', 'fields' => ['id' => $r->id], 'confirm' => 'Remove ' . $r->email . '? It can be mailed again, even if it bounced.', 'danger' => true]],
    'empty' => ['title' => 'No suppressed addresses', 'text' => 'Bounces, complaints and unsubscribe-from-all land here.']]) ?>
