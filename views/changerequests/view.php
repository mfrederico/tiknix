<?php
// ui: hand-made — the screenshots are a row of pictures
/** @var object $cr changerequest  @var string $project  @var string $decidedBy */
$id = (int) $cr->id;
$status = (string) $cr->status;
$shots = json_decode((string) $cr->shotsJson, true) ?: [];
$notes = json_decode((string) ($cr->notesJson ?? '[]'), true) ?: [];   // pointed at on the site (the runtime's public/js/notes.js)
$errors = json_decode((string) $cr->errorsJson, true) ?: [];
$page = (string) $cr->page;
$pageUrl = $page !== '' ? 'https://' . $cr->site . (str_starts_with($page, '/') ? $page : '/' . $page) : '';
$when = fn(string $at) => $at === '' ? '' : date('j M Y, g:i a', strtotime($at));
$talk = (int) $cr->threadRef > 0 ? ['label' => 'Answer ' . $cr->fromName, 'url' => '/communications/thread/' . (int) $cr->threadRef, 'icon' => 'chat-left-text'] : null;
$primary = $status === 'new'
    ? ['label' => 'Build it', 'post' => '/changerequests/build', 'fields' => ['id' => $id], 'icon' => 'hammer',
       'confirm' => 'Send this to the Builder to be planned and built? ' . $cr->fromName . ' is told it is being built.']
    : $talk;
$more = [];
if ($status === 'new' && $talk) $more[] = $talk;
if ($status === 'new') $more[] = ['label' => 'Open in Ask', 'post' => '/changerequests/ask', 'fields' => ['id' => $id], 'icon' => 'chat-square-text',
                                  'confirm' => 'Put this into ' . $project . '\'s Ask? It answers there, or plans it for you to build. ' . $cr->fromName . ' is not told anything yet.'];
if ($status !== 'done') $more[] = ['label' => 'Mark done', 'post' => '/changerequests/done', 'fields' => ['id' => $id], 'icon' => 'check2',
                                   'confirm' => 'Mark this done and tell ' . $cr->fromName . ' it is live?'];
?>
<?= \app\Ui::page(['title' => mb_strimwidth((string) strtok((string) $cr->said, "\n"), 0, 80, '…'), 'eyebrow' => 'Change request · ' . $project,
                   'back' => ['label' => 'Change requests', 'url' => '/changerequests'], 'primary' => $primary, 'more' => $more]) ?>
<?php if ($status === 'building'): ?>
<?= \app\Ui::notice(['tone' => 'info', 'title' => 'Being built.', 'text' => 'It is in ' . $project . '\'s Builder. When the build finishes it is marked done and ' . $cr->fromName . ' is told it is live.']) ?>
<?php endif; ?>
<?= \app\Ui::detail(['items' => [
    'Where it is' => (\app\SiteChanges::LABELS[$status] ?? $status) . ($decidedBy !== '' ? ' — ' . $decidedBy . ', ' . $when((string) $cr->decidedAt) : ''),
    'What they asked' => \app\Ui::raw(nl2br(htmlspecialchars((string) $cr->said))),
    'Asked by' => $cr->fromName . ' <' . $cr->fromEmail . '>' . ($cr->levelName ? ', ' . $cr->levelName . ' on ' . $cr->site : ''),
    'Page' => $pageUrl !== '' ? \app\Ui::raw('<a href="' . htmlspecialchars($pageUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars($pageUrl) . '</a>') : '',
    'Asked' => $when((string) $cr->createdAt),
    'Their browser' => (string) $cr->browser,
    'Errors the site logged before' => $errors ? \app\Ui::raw('<pre class="small mb-0" style="white-space:pre-wrap">' . htmlspecialchars(implode("\n", $errors)) . '</pre>') : 'None in the half hour before',
]]) ?>
<?php if ($notes): ?>
<section class="ui-panel mt-3">
  <div class="ui-panel-header"><h3>Their notes</h3><span class="ui-count"><?= count($notes) ?> pointed at on the site</span></div>
  <div class="ui-panel-body d-grid gap-3">
    <?php foreach ($notes as $i => $n): $e = $n['element'] ?? null; $u = $n['shot'] !== null ? '/changerequests/shot?id=' . $id . '&n=' . ((int) $n['shot'] + 1) : ''; ?>
      <div class="d-flex flex-wrap gap-3 align-items-start">
        <?php if ($u !== ''): ?><a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" class="flex-shrink-0"><img src="<?= htmlspecialchars($u) ?>" alt="What note <?= $i + 1 ?> points at" style="max-width:260px;max-height:170px;border-radius:.4rem;border:1px solid var(--bs-border-color)"></a><?php endif; ?>
        <div style="min-width:0;flex:1 1 280px">
          <div><b><?= $i + 1 ?>.</b> <?= htmlspecialchars($n['text']) ?></div>
          <div class="small ui-sub mt-1"><?= htmlspecialchars($e ? $e['label'] : 'The whole page') ?> on <a href="<?= htmlspecialchars('https://' . $cr->site . $n['page']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($n['page']) ?></a></div>
          <?php if ($e): ?><details class="small mt-1"><summary class="ui-sub">How the builder finds it</summary><div class="ui-mono mt-1" style="overflow-wrap:anywhere"><?= htmlspecialchars($e['code']) ?> · <?= htmlspecialchars($e['size']) ?> on a <?= htmlspecialchars($n['screen']) ?> screen<br><?= htmlspecialchars($e['selector']) ?></div><pre class="mt-1 mb-0 small" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= htmlspecialchars($e['html']) ?></pre></details><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php elseif ($shots): ?>
<section class="ui-panel mt-3">
  <div class="ui-panel-header"><h3>Screenshots</h3></div>
  <div class="ui-panel-body d-flex flex-wrap gap-2">
    <?php foreach ($shots as $i => $s): $u = '/changerequests/shot?id=' . $id . '&n=' . ($i + 1); ?>
      <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($u) ?>" alt="<?= htmlspecialchars((string) $s['name']) ?>" style="width:220px;height:150px;object-fit:cover;border-radius:.4rem;border:1px solid var(--bs-border-color)"></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php if (!in_array($status, ['declined', 'done'], true)): ?>
<div class="mt-3">
<?= \app\Ui::form(['action' => '/changerequests/decline', 'submit' => 'Not going ahead', 'title' => 'Not going ahead?', 'hidden' => ['id' => $id],
    'lead' => $cr->fromName . ' is sent what you write here.',
    'fields' => [['name' => 'reason', 'label' => 'Why not', 'type' => 'textarea', 'rows' => 3, 'required' => true]]]) ?>
</div>
<?php endif; ?>
