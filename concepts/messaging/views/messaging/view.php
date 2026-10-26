<?php
/** @var ?array $c @var object $row @var array $links @var array $recipients @var ?array $preview @var bool $unavailable @var ?array $audience @var array $feeders @var array $reactors @var bool $custom @var bool $on */
if (!$c): ?>
<?= \app\Ui::page(['title' => 'Not found', 'back' => ['label' => 'Messages', 'url' => '/messaging']]) ?>
<?php return; endif; ?>
<?php
$more = [['label' => 'Send me a test', 'post' => '/messaging/test?id=' . (int) $c['id'], 'fields' => [], 'icon' => 'send']];
if (!$c['locked']) {
    $more[] = $on ? ['label' => 'Pause it', 'post' => '/messaging/status?id=' . (int) $c['id'], 'fields' => ['on' => '0'], 'icon' => 'pause-fill', 'confirm' => 'Pause this message? Nothing more goes out until it is turned on again.']
                  : ['label' => 'Turn it on', 'post' => '/messaging/status?id=' . (int) $c['id'], 'fields' => ['on' => '1'], 'icon' => 'play-fill', 'confirm' => $custom ? 'Turn it on? Its pipelines may then send it.' : 'Turn it on? It goes out as set, to everyone its rule names.'];
}
?>
<?= \app\Ui::page(['title' => $c['subject'], 'back' => ['label' => 'Messages', 'url' => '/messaging'],
    'lead' => $on ? 'On.' : 'Paused: nothing goes out until it is turned on. Send yourself a test first.',
    'primary' => ['label' => 'Edit this message', 'url' => '/messaging/edit?id=' . (int) $c['id'], 'icon' => 'pencil'], 'more' => $more]) ?>
<?php if ($unavailable): ?>
<?= \app\Ui::notice(['tone' => 'warning', 'title' => 'Unavailable.', 'text' => 'This app has not handed the plugin anything to put in the digest (the digest provider), so it never sends.']) ?>
<?php endif; ?>
<?= \app\Ui::detail(['title' => 'What came of it', 'items' => [
    'To' => $row->where, 'Kind' => $row->category, 'Status' => ucfirst($c['status']), 'Scheduled for' => $c['scheduled_at'], 'Recipients' => $c['stats']['recipients'], 'Sent' => $row->sent,
    'Delivered' => $row->delivered, 'Clicked' => $row->clicked, 'Acted' => $row->acted, 'Replies' => $row->replies,
]]) ?>
<?php if ($custom): ?>
<?= \app\Ui::table(['title' => 'Pipelines that send it', 'rows' => array_map(fn($f) => (object) $f, $feeders), 'count' => 'pipeline',
    'columns' => [['label' => 'Pipeline', 'value' => 'name', 'sub' => 'slug', 'url' => fn($r) => '/pipelines'], ['label' => 'Runs', 'value' => 'trigger']],
    'empty' => ['title' => 'No pipeline sends it yet', 'text' => 'A pipeline with a messaging step whose message is the code ' . $c['code'] . ' sends it to whom the run names. Start one from a starter flow on the Pipelines page.',
                'action' => ['label' => 'Pipelines', 'url' => '/pipelines']]]) ?>
<?php elseif ($audience !== null): ?>
<?= \app\Ui::detail(['title' => 'Who would get it', 'items' => ['Right now' => $audience['count'] . ' ' . ($audience['count'] === 1 ? 'person' : 'people') . ($audience['texts'] > 0 ? ', ' . $audience['texts'] . ' by text too' : ''),
    'How' => $row->where . ' — those with this kind on, who have not had it']]) ?>
<?php endif; ?>
<?= \app\Ui::table(['title' => 'What reacts to it', 'rows' => array_map(fn($r) => (object) $r, $reactors), 'count' => 'pipeline',
    'columns' => [['label' => 'Pipeline', 'value' => 'name', 'sub' => 'slug', 'url' => fn($r) => '/pipelines'], ['label' => 'Starts when', 'value' => 'events'], ['label' => 'Applies to', 'value' => 'scope', 'quiet' => true]],
    'empty' => ['title' => 'Nothing reacts to it yet', 'text' => 'A pipeline whose trigger is a message event — delivered, opened, clicked, replied, unsubscribed, bounced, complained — starts for each one. "Follow up a message nobody opened" is a starter flow.',
                'action' => ['label' => 'Pipelines', 'url' => '/pipelines']]]) ?>
<?= \app\Ui::detail(['title' => 'Message', 'items' => ['Text' => \app\Ui::raw('<div class="text-break" style="white-space:pre-wrap">' . htmlspecialchars($c['body_text'] !== '' ? $c['body_text'] : strip_tags($c['body_email'])) . '</div>')]]) ?>
<?= \app\Ui::table(['title' => 'Links', 'rows' => array_map(fn($l) => (object) $l, $links),
    'columns' => [['label' => 'Link', 'value' => 'url'], ['label' => 'Clicks', 'value' => 'clicks']],
    'empty' => ['title' => 'No links counted yet', 'text' => 'Links appear here once the message has gone out.']]) ?>
<?php include __DIR__ . '/_copy.php'; ?>
