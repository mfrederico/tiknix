<?php
/** @var array $providers @var array $mine @var int $chosenId @var string $problem @var array $projects @var array $form */
$chosen = null;
foreach ($mine as $c) if ((int) $c->id === $chosenId) $chosen = $c;
$opts = array_map(fn($p) => $p['label'], $providers);
?>
<?= \app\Ui::page(['title' => 'Your AI',
    'lead' => 'Tiknix is a harness utility: it runs the AI you bring. Connect it once and every project you make builds with it. You pay your provider directly; Tiknix never sells you tokens.']) ?>

<?php if ($problem !== ''): ?>
<?= \app\Ui::notice(['tone' => 'danger', 'title' => 'Your AI choice is broken.', 'text' => $problem . ' Connect it again below.']) ?>
<?php elseif ($chosen): ?>
<?= \app\Ui::notice(['tone' => 'success', 'title' => 'Your AI: ' . $chosen->name,
    'text' => 'New projects build with it.' . ($chosen->lastTestAt ? ' It last answered a test on ' . date('j M Y, H:i', strtotime((string) $chosen->lastTestAt)) . '.' : ''),
    'action' => $projects ? null : ['label' => 'Create your first project', 'url' => '/projects?new=1']]) ?>
<?php else: ?>
<?= \app\Ui::notice(['tone' => 'info', 'title' => 'No AI connected yet.', 'text' => 'Paste an API key below. We test it on the spot, and from then on your projects build with it. Using a Claude subscription instead of a key? Sign in with it on a project\'s AI agents page.']) ?>
<?php endif; ?>

<?php if ($chosen && $projects): ?>
<?= \app\Ui::table(['title' => 'Your projects', 'rows' => $projects, 'count' => 'project',
    'columns' => [['label' => 'Project', 'value' => 'name'],
                  ['label' => 'Builds with', 'value' => fn($p) => $p['yours'] ? 'Your AI' : ($p['ready'] ? 'Its own AI' : ($p['reported'] ? 'No AI' : 'Not known yet')),
                   'as' => 'badge', 'tones' => ['Your AI' => 'success', 'Its own AI' => 'secondary', 'No AI' => 'danger', 'Not known yet' => 'secondary']]],
    'actions' => fn($p) => $p['yours'] ? [] : [['label' => 'Use Your AI here', 'post' => '/yourai/apply', 'fields' => ['id' => $p['id']], 'icon' => 'plug']],
    'empty' => ['title' => 'No projects yet', 'text' => 'A project you make builds with Your AI from the start.', 'action' => ['label' => 'Create a project', 'url' => '/projects?new=1']]]) ?>
<?php endif; ?>

<?= \app\Ui::form(['action' => '/yourai/save', 'submit' => 'Connect and test', 'title' => $chosen ? 'Connect a different AI' : 'Connect your AI',
    'values' => $form + ['preset' => ''],
    'fields' => [
        ['name' => 'api_key', 'label' => 'Your API key', 'type' => 'password', 'required' => true, 'autocomplete' => 'off',
         'help' => 'A key that starts with sk-ant- (Anthropic) or sk-or- (OpenRouter) is recognised by itself.'],
        ['name' => 'preset', 'label' => 'Provider', 'type' => 'select', 'options' => $opts,
         'help' => 'Filled in for you when the key says who it is from; pick it for the others.'],
        ['name' => 'endpoint', 'label' => 'Your model server\'s address', 'type' => 'url', 'more' => true, 'placeholder' => 'https://models.example.com',
         'help' => 'Only for your own model server: a public address serving the Anthropic Messages API.'],
        ['name' => 'model', 'label' => 'Model', 'more' => true, 'help' => 'Leave empty for the provider\'s usual model. Your own server needs the name of the model it serves.'],
    ]]) ?>
<p class="small text-body-secondary mt-n2 mb-4">Where to get a key:
<?php $__l = []; foreach ($providers as $p) if ($p['keys'] !== '') $__l[] = '<a href="' . htmlspecialchars($p['keys']) . '" target="_blank" rel="noopener">' . htmlspecialchars($p['label']) . '</a>'; echo implode(' · ', $__l); ?>.
Your key is stored encrypted and never shown again.</p>

<?php if ($mine): ?>
<?= \app\Ui::table(['title' => 'AIs you have connected', 'rows' => $mine, 'count' => 'AI',
    'columns' => [['label' => 'AI', 'value' => 'name', 'sub' => fn($c) => (string) $c->lastTestMsg !== '' ? mb_strimwidth((string) $c->lastTestMsg, 0, 90, '…') : ''],
                  ['label' => 'Status', 'value' => fn($c) => (int) $c->id === $chosenId ? 'Your AI' : ($c->lastTestAt ? ((int) $c->lastTestOk ? 'Answers' : 'Did not answer') : 'Not tested'),
                   'as' => 'badge', 'tones' => ['Your AI' => 'success', 'Answers' => 'secondary', 'Did not answer' => 'danger', 'Not tested' => 'secondary']]],
    'actions' => fn($c) => array_values(array_filter([
        (int) $c->id !== $chosenId && (int) $c->lastTestOk ? ['label' => 'Make this Your AI', 'post' => '/yourai/choose', 'fields' => ['id' => (int) $c->id], 'icon' => 'check2'] : null,
        ['label' => 'Remove', 'post' => '/yourai/remove', 'fields' => ['id' => (int) $c->id], 'danger' => true,
         'confirm' => "Remove {$c->name}? Its key is deleted here. Projects already using it keep it until you change it there."]])),
    'empty' => ['title' => 'None yet', 'text' => 'Connect one above.']]) ?>
<?php endif; ?>

<script>
// A key we recognise picks its provider (MemberAi::detect does the same on the server).
(function () {
  var key = document.querySelector('[name="api_key"]'), sel = document.querySelector('select[name="preset"]');
  if (!key || !sel) return;
  key.addEventListener('input', function () {
    var v = key.value.trim(), p = v.indexOf('sk-ant-') === 0 ? 'anthropic' : v.indexOf('sk-or-') === 0 ? 'openrouter' : '';
    if (p) sel.value = p;
  });
})();
</script>
