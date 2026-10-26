<?php
/** @var array $c @var string $where @var string $composeUrl @var array $links @var array $recipients @var ?array $preview */
$s = $c['stats'];
?>
<?= \app\Ui::page(['title' => $c['subject'], 'back' => ['label' => 'Messages to ' . $where, 'url' => $composeUrl]]) ?>
<?= \app\Ui::detail(['title' => 'What came of it', 'items' => [
    'Status' => ucfirst($c['status']),
    ($c['status'] === 'scheduled' ? 'Goes out' : 'Sent') => $c['sent_at'] !== '' ? $c['sent_at'] : $c['scheduled_at'],
    'Sent' => $s['sent'], 'Delivered' => $s['delivered'], 'Clicked' => $s['clicked'], 'Acted' => $s['acted'], 'Replies' => $s['replies'],
]]) ?>
<?= \app\Ui::detail(['title' => 'Message', 'items' => ['Text' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars($c['body_text']) . '</div>')]]) ?>
<?= \app\Ui::table(['title' => 'Links', 'rows' => array_map(fn($l) => (object) $l, $links),
    'columns' => [['label' => 'Link', 'value' => 'url'], ['label' => 'Clicks', 'value' => 'clicks']],
    'empty' => ['title' => 'No links counted yet', 'text' => 'Links appear here once the message has gone out.']]) ?>
<?php include __DIR__ . '/_copy.php'; ?>
