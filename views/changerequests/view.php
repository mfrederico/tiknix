<?php
// ui: hand-made — the screenshots are a row of pictures
/** @var object $cr changerequest  @var string $project  @var string $decidedBy */
$id = (int) $cr->id;
$status = (string) $cr->status;
$shots = json_decode((string) $cr->shotsJson, true) ?: [];
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
<?php if ($shots): ?>
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
