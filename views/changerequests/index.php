<?php
/** @var array $rows changerequest beans  @var string $status  @var array $projects instance id => name */
$labels = \app\SiteChanges::LABELS;
$more = [];
foreach (['' => 'All', 'new' => 'New', 'building' => 'Being built', 'done' => 'Done', 'declined' => 'Not going ahead'] as $k => $l) {
    if ($k !== $status) $more[] = ['label' => 'Show ' . strtolower($l), 'url' => '/changerequests' . ($k !== '' ? '?status=' . $k : '')];
}
?>
<?= \app\Ui::page(['title' => 'Change requests' . ($status !== '' ? ': ' . strtolower($labels[$status]) : ''),
                   'lead' => 'Changes people asked for on your projects\' sites. Answer them, build them, or say why not.', 'more' => $more]) ?>
<?= \app\Ui::table(['rows' => $rows, 'count' => 'request',
    'columns' => [
        ['label' => 'Request', 'value' => fn($r) => mb_strimwidth((string) strtok((string) $r->said, "\n"), 0, 90, '…'),
         'sub' => fn($r) => $r->fromName . ' on ' . $r->site, 'url' => fn($r) => '/changerequests/view?id=' . (int) $r->id],
        ['label' => 'Project', 'value' => fn($r) => $projects[(int) $r->instanceId] ?? '', 'quiet' => true],
        ['label' => 'Where it is', 'value' => 'status', 'as' => 'badge', 'labels' => $labels,
         'tones' => ['new' => 'info', 'building' => 'warning', 'done' => 'success', 'declined' => 'neutral']],
        ['label' => 'Asked', 'value' => 'created_at', 'as' => 'date'],
    ],
    'search' => ['value' => $q, 'placeholder' => 'Search what was asked, who, or the site', 'keep' => ['status' => $status], 'action' => '/changerequests'],
    'bulk' => ['post' => '/changerequests/bulk', 'value' => fn($r) => (int) $r->id, 'noun' => 'request',
               'fields' => ['back' => '/changerequests?' . http_build_query(array_filter(['status' => $status, 'q' => $q]))],
               'actions' => [['label' => 'Mark done', 'value' => 'done', 'icon' => 'check2',
                              'confirm' => 'Mark the selected requests done? Each person who asked is emailed that theirs is live.']]],
    'empty' => $q !== '' ? ['title' => 'None match', 'text' => 'No request mentions “' . $q . '”.'] : ['title' => $status !== '' ? 'None ' . strtolower($labels[$status]) : 'No change requests yet',
                'text' => 'When someone on one of your projects\' sites asks for a change (Edit Member → "Ask for changes" lets them), it arrives here and by email.']]) ?>
