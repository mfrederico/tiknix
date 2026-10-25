<?php
/** @var ?\RedBeanPHP\OODBBean $m @var object $row @var array $stats @var array $links @var bool $unavailable */
if (!$m): ?>
<?= \app\Ui::page(['title' => 'Not found', 'back' => ['label' => 'Messages', 'url' => '/messaging']]) ?>
<?php return; endif; ?>
<?= \app\Ui::page(['title' => (string) $m->subject, 'back' => ['label' => 'Messages', 'url' => '/messaging'],
    'primary' => ['label' => 'Edit this message', 'url' => '/messaging/edit?id=' . (int) $m->id, 'icon' => 'pencil']]) ?>
<?php if ($unavailable): ?>
<?= \app\Ui::notice(['tone' => 'warning', 'title' => 'Unavailable.', 'text' => 'This app has not handed the plugin anything to put in the digest (the digest provider), so it never sends.']) ?>
<?php endif; ?>
<?= \app\Ui::detail(['title' => 'What came of it', 'items' => [
    'To' => $row->where, 'Kind' => $row->category, 'Status' => ucfirst((string) $m->status), 'Scheduled for' => (string) $m->scheduledAt, 'Sent' => $stats['sent'],
    'Delivered' => $stats['delivered'], 'Clicked' => $stats['clicked'], 'Acted' => $stats['acted'], 'Replies' => $stats['replies'],
]]) ?>
<?= \app\Ui::detail(['title' => 'Message', 'items' => ['Text' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars((string) $m->bodyText) . '</div>')]]) ?>
<?= \app\Ui::table(['title' => 'Links', 'rows' => array_map(fn($l) => (object) $l, $links),
    'columns' => [['label' => 'Link', 'value' => 'url'], ['label' => 'Clicks', 'value' => 'clicks']],
    'empty' => ['title' => 'No links counted yet', 'text' => 'Links appear here once the message has gone out.']]) ?>
