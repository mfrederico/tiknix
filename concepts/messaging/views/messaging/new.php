<?php
/** @var array $form @var array $problems @var array $categories (code => label) @var array $templates (EmailTemplates::all()) */
$help = 'You can use {first_name|there}, {unsubscribe_url}, {prefs_url} and what the audience offers (like {community.name}). {name|a default} says what to show when it is empty.';
?>
<?= \app\Ui::page(['title' => 'A new message', 'lead' => 'First when it goes out, then the words. It is saved paused: send yourself a test from its page, then turn it on.',
    'back' => ['label' => 'Messages', 'url' => '/messaging']]) ?>
<?= \app\Ui::form(['action' => '/messaging/new', 'submit' => 'Save this message', 'cancel' => '/messaging', 'values' => $form, 'problems' => $problems,
    'fields' => [
        ['name' => 'when', 'section' => 'When it goes out', 'label' => 'Trigger', 'type' => 'radio', 'required' => true, 'options' => [
            'date' => 'On a date', 'repeat' => 'Repeats every N days', 'inactive' => 'After N days without signing in', 'joined' => 'N days after joining', 'custom' => 'Custom — a pipeline decides'],
            'help' => 'Custom: a pipeline is the trigger and the condition (a schedule and a query, a purchase, a moment the app reports) and its messaging step sends this message to whom it names — once per person, only to those with this kind on.'],
        ['name' => 'scheduled_at', 'label' => 'Goes out at', 'type' => 'datetime', 'width' => 6],
        ['name' => 'cadence_days', 'label' => 'Every N days', 'type' => 'number', 'min' => 1, 'width' => 6, 'help' => 'Each person gets it again this many days after their last copy.'],
        ['name' => 'trigger_days', 'label' => 'N days', 'type' => 'number', 'min' => 1, 'width' => 6],
        ['name' => 'code', 'label' => 'Code', 'width' => 6, 'help' => 'The name a pipeline\'s messaging step uses for this message, like welcome_1.'],
        ['name' => 'category', 'section' => 'The words', 'label' => 'Kind of message', 'type' => 'select', 'options' => $categories, 'required' => true, 'help' => 'Members can switch each kind off in their settings.'],
        ['name' => 'subject', 'label' => 'Subject', 'required' => true, 'help' => $help],
        ['name' => 'body_email', 'label' => 'Message', 'type' => 'textarea', 'rows' => 10, 'required' => true, 'help' => 'HTML is allowed. ' . $help . ' A Custom message may also use what its pipeline hands over.'],
        ['name' => 'body_text', 'label' => 'Text version', 'type' => 'textarea', 'rows' => 6, 'help' => 'Plain text, for mail readers that show no HTML. Left empty, the service makes one from the HTML.'],
        ['name' => 'body_sms', 'label' => 'Text message', 'type' => 'textarea', 'rows' => 2, 'help' => 'For members who asked for texts: one part, at most 144 characters before the STOP line. Empty: no text goes out.'],
    ]]) ?>
<?php include __DIR__ . '/_composer.php'; ?>
<script>
// one follow-up field per trigger: show the chosen one, keep the others out of the way
(function () {
  var map = <?= json_encode(\app\concepts\messaging\Campaigns::WHEN) ?>;
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
