<?php
/** @var \RedBeanPHP\OODBBean $m @var string $where @var string $composeUrl @var array $stats @var array $links */
?>
<?= \app\Ui::page(['title' => (string) $m->subject, 'back' => ['label' => 'Messages to ' . $where, 'url' => $composeUrl]]) ?>
<?= \app\Ui::detail(['title' => 'What came of it', 'items' => [
    'Status' => ucfirst((string) $m->status),
    ((string) $m->status === 'scheduled' ? 'Goes out' : 'Sent') => (string) ($m->sentAt ?: $m->scheduledAt),
    'Sent' => $stats['sent'], 'Delivered' => $stats['delivered'], 'Clicked' => $stats['clicked'], 'Acted' => $stats['acted'], 'Replies' => $stats['replies'],
]]) ?>
<?= \app\Ui::detail(['title' => 'Message', 'items' => ['Text' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars((string) $m->bodyText) . '</div>')]]) ?>
<?= \app\Ui::table(['title' => 'Links', 'rows' => array_map(fn($l) => (object) $l, $links),
    'columns' => [['label' => 'Link', 'value' => 'url'], ['label' => 'Clicks', 'value' => 'clicks']],
    'empty' => ['title' => 'No links counted yet', 'text' => 'Links appear here once the message has gone out.']]) ?>
