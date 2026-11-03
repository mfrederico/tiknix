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
<?= \app\Ui::notice(['tone' => 'info', 'title' => 'No AI connected yet.', 'text' => 'Pick who your AI is with, then paste its key. We test it on the spot, and from then on your projects build with it. Using a Claude subscription instead of a key? Sign in with it on a project\'s AI agents page.']) ?>
<?php endif; ?>

<?php if ($chosen && $projects): ?>
<?= \app\Ui::table(['title' => 'Your projects', 'rows' => $projects, 'count' => 'project',
    'columns' => [['label' => 'Project', 'value' => 'name'],
                  ['label' => 'Builds with', 'value' => fn($p) => $p['yours'] ? 'Your AI' : ($p['ready'] ? 'Its own AI' : ($p['reported'] ? 'No AI' : 'Not known yet')),
                   'as' => 'badge', 'tones' => ['Your AI' => 'success', 'Its own AI' => 'secondary', 'No AI' => 'danger', 'Not known yet' => 'secondary']]],
    'actions' => fn($p) => $p['yours'] ? [] : [['label' => 'Use Your AI here', 'post' => '/yourai/apply', 'fields' => ['id' => $p['id']], 'icon' => 'plug']],
    'empty' => ['title' => 'No projects yet', 'text' => 'A project you make builds with Your AI from the start.', 'action' => ['label' => 'Create a project', 'url' => '/projects?new=1']]]) ?>
<?php endif; ?>

<?php if ($next !== ''): /* on the way to building a plan: say what comes after, and that it can wait */ ?>
<?= \app\Ui::notice(['tone' => 'neutral', 'title' => 'Next: build your project.', 'text' => $chosen ? 'Your AI is connected.' : 'Connect your AI first and the build starts with it. You can also do this later.',
    'action' => ['label' => $chosen ? 'Build my project' : 'Skip for now', 'url' => $next]]) ?>
<?php endif; ?>
<?php $__first = (string) (($form['preset'] ?? '') ?: array_key_first($providers)); /* a provider is chosen first: the key comes from that provider */ ?>
<?= \app\Ui::form(['action' => '/yourai/save', 'submit' => 'Connect and test', 'title' => $chosen ? 'Connect a different AI' : 'Connect your AI',
    'values' => ['preset' => $__first] + $form, 'hidden' => ['next' => $next],
    'fields' => [
        ['name' => 'preset', 'label' => 'Who is your AI with?', 'type' => 'select', 'options' => $opts, 'required' => true,
         'help' => 'The company you have an AI account with. Pick it first; the key comes from them.'],
        ['name' => 'api_key', 'label' => 'Your API key', 'type' => 'password', 'required' => true, 'autocomplete' => 'off',
         'help' => 'Copy it from your account with them, and paste it here.'],
        ['name' => 'endpoint', 'label' => 'Your model server\'s address', 'type' => 'url', 'more' => true, 'placeholder' => 'https://models.example.com',
         'help' => 'Only for your own model server: a public address serving the Anthropic Messages API.'],
        ['name' => 'model', 'label' => 'Model', 'more' => true, 'help' => 'Leave empty for the provider\'s usual model. Your own server needs the name of the model it serves.'],
    ]]) ?>
<?php $__keys = array_map(fn($p) => ['label' => $p['label'], 'keys' => $p['keys']], $providers); ?>
<p class="small mb-2" id="yourai-getkey-line"<?= ($providers[$__first]['keys'] ?? '') === '' ? ' hidden' : '' ?>><i class="bi bi-key me-1"></i>Where to get your key: <a id="yourai-getkey" href="<?= htmlspecialchars((string) ($providers[$__first]['keys'] ?? '')) ?>" target="_blank" rel="noopener"><span><?= htmlspecialchars((string) ($providers[$__first]['label'] ?? '')) ?></span> API keys <i class="bi bi-box-arrow-up-right"></i></a></p>
<p class="small text-body-secondary mt-n2 mb-4">Your key is stored encrypted and never shown again.</p>
<script type="application/json" id="yourai-providers"><?= json_encode($__keys, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

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
// The provider comes first: its "get a key" link follows it, and your own server opens its address field. A pasted key whose
// first letters say another provider (MemberAi::detect) switches to it, and says so, so a key is never sent to the wrong one.
(function () {
  var key = document.querySelector('[name="api_key"]'), sel = document.querySelector('select[name="preset"]');
  var info = JSON.parse(document.getElementById('yourai-providers').textContent), line = document.getElementById('yourai-getkey-line'), link = document.getElementById('yourai-getkey');
  if (!key || !sel) return;
  // The "where to get your key" link sits right under the provider: picking one shows where its key comes from.
  sel.parentNode.appendChild(line); line.classList.add('mt-2');
  var note = document.createElement('div'); note.className = 'form-text text-success'; note.hidden = true; key.closest('.mb-3, .col-12, div').appendChild(note);
  function follow() {
    var p = info[sel.value] || {}; line.hidden = !p.keys; if (p.keys) { link.href = p.keys; link.querySelector('span').textContent = p.label; }
    if (sel.value === 'custom') { var d = sel.closest('form').querySelector('details'); if (d) d.open = true; }
  }
  sel.addEventListener('change', function () { note.hidden = true; follow(); });
  key.addEventListener('input', function () {
    var v = key.value.trim(), p = v.indexOf('sk-ant-') === 0 ? 'anthropic' : v.indexOf('sk-or-') === 0 ? 'openrouter' : '';
    if (p && sel.value !== p && info[p]) { sel.value = p; follow(); note.textContent = 'That is ' + (/^[aeiou]/i.test(info[p].label) ? 'an ' : 'a ') + info[p].label + ' key, so the provider is set to ' + info[p].label + '.'; note.hidden = false; }
  });
  follow();
})();
</script>
