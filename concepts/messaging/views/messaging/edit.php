<?php
/** @var array $c @var bool $system @var bool $locked @var array $form @var array $problems @var ?array $preview @var ?string $appTrigger @var array $feeders */
$vars = ['{first_name}', '{unsubscribe_url}', '{prefs_url}'];
if ($system) $vars[] = '{headline}';
$list = fn(array $extra) => 'You can use ' . implode(', ', array_merge($vars, $extra)) . ', what the audience offers (like {community.name}) and, for a Custom message, what its pipeline hands over. {name|a default} says what to show when it is empty.';
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
    ['name' => 'body_email', 'label' => 'Message', 'type' => 'textarea', 'rows' => 10, 'required' => true, 'help' => 'HTML is allowed. ' . $list($system ? ['{content_html}'] : [])],
    ['name' => 'body_text', 'label' => 'Text version', 'type' => 'textarea', 'rows' => 6, 'help' => 'Plain text, for mail readers that show no HTML. Left empty, the service makes one from the HTML. ' . $list($system ? ['{content_text}'] : [])],
    ['name' => 'body_sms', 'label' => 'Text message', 'type' => 'textarea', 'rows' => 2, 'help' => 'For members who asked for texts: one part, at most 144 characters before the STOP line. Empty: no text goes out.'],
];
if ($form['when'] === 'digest') {
    $fields[] = ['name' => 'on', 'section' => 'When it goes out', 'label' => 'The weekly digest: Sunday morning, to each member who turned it on, with their own news', 'type' => 'radio', 'required' => true, 'options' => ['1' => 'On', '0' => 'Paused']];
} elseif ($form['when'] === 'app') {
    $fields[] = ['name' => 'trigger_days', 'section' => 'When it goes out', 'label' => (string) $appTrigger, 'type' => 'number', 'min' => 1, 'required' => true, 'width' => 6, 'help' => 'A rule of this app\'s own code decides who qualifies; this is its number of days.'];
    $fields[] = ['name' => 'on', 'label' => 'Status', 'type' => 'radio', 'required' => true, 'options' => ['1' => 'On', '0' => 'Paused'], 'width' => 6];
} else {
    $fields[] = ['name' => 'when', 'section' => 'When it goes out', 'label' => 'Trigger', 'type' => 'radio', 'required' => true, 'options' => [
        'date' => 'On a date', 'repeat' => 'Repeats every N days', 'inactive' => 'After N days without signing in', 'joined' => 'N days after joining', 'custom' => 'Custom — a pipeline decides'],
        'help' => 'Custom: a pipeline is the trigger and the condition (a schedule and a query, a purchase from Stripe, a moment the app reports) and its messaging step sends this message to whom it names — once per person, only to those with this kind on.'];
    $fields[] = ['name' => 'scheduled_at', 'label' => 'Goes out at', 'type' => 'datetime', 'width' => 6];
    $fields[] = ['name' => 'cadence_days', 'label' => 'Every N days', 'type' => 'number', 'min' => 1, 'width' => 6, 'help' => 'Each person gets it again this many days after their last copy.'];
    $fields[] = ['name' => 'trigger_days', 'label' => 'N days', 'type' => 'number', 'min' => 1, 'width' => 6];
    $fields[] = ['name' => 'code', 'label' => 'Code', 'width' => 6, 'help' => 'The name a pipeline\'s messaging step uses for this message, like no_friends.'];
    $fields[] = ['name' => 'on', 'label' => 'Status', 'type' => 'radio', 'required' => true, 'options' => ['1' => 'On — goes out as set', '0' => 'Paused (or a draft)']];
}
?>
<?= \app\Ui::form(['action' => '/messaging/edit?id=' . (int) $c['id'], 'submit' => 'Save this message', 'cancel' => '/messaging/view?id=' . (int) $c['id'],
    'values' => $form, 'problems' => $problems, 'fields' => $fields]) ?>
<?php if ($form['when'] === 'custom'): ?>
<?= \app\Ui::table(['title' => 'Pipelines that send it', 'rows' => array_map(fn($f) => (object) $f, $feeders), 'count' => 'pipeline',
    'columns' => [['label' => 'Pipeline', 'value' => 'name', 'sub' => 'slug', 'url' => fn($r) => '/pipelines'], ['label' => 'Runs', 'value' => 'trigger']],
    'empty' => ['title' => 'No pipeline names it yet', 'text' => 'A pipeline with a "messaging" step whose campaign is this message\'s code sends it. Make one on the Pipelines page.',
                'action' => ['label' => 'Pipelines', 'url' => '/pipelines']]]) ?>
<?php endif; ?>
<?php include __DIR__ . '/_composer.php'; ?>
<script>
// one follow-up field per trigger: show the chosen one, keep the others out of the way
(function () {
  var map = {date: 'scheduled_at', repeat: 'cadence_days', inactive: 'trigger_days', joined: 'trigger_days', custom: 'code'};
  var radios = document.querySelectorAll('input[name="when"]'); if (!radios.length) return;
  function box(name) { var el = document.querySelector('[name="' + name + '"]'); return el ? (el.closest('.col-md-6, .col-12, .col-6, .ui-form-field, .mb-3') || el.parentElement) : null; }
  function show() {
    var chosen = (document.querySelector('input[name="when"]:checked') || {}).value;
    ['scheduled_at', 'cadence_days', 'trigger_days', 'code'].forEach(function (n) { var b = box(n); if (b) b.hidden = map[chosen] !== n; });
  }
  radios.forEach(function (r) { r.addEventListener('change', show); });
  show();
})();
</script>
