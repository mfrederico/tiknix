<?php
/**
 * The knowledge base's desk (controls/Knowledge.php). Described with \app\Ui; the row of states
 * and the answer cards are the two hand-made parts.
 * ui: hand-made — the state tabs and "what an agent would get" cards are not a list, form or record
 */
use app\Ui;

echo Ui::page(['eyebrow' => 'Platform', 'title' => 'Knowledge base',
    'lead' => "What every project's agent is told when it asks about Tiknix — before it asks its user about support. Nothing is handed out until you publish it.",
    'primary' => ['label' => 'Write an entry', 'url' => '/knowledge/edit', 'icon' => 'plus-lg']]);
?>
<ul class="nav nav-pills mb-3 gap-1" aria-label="Entries by state">
    <?php foreach ($labels as $key => $label): ?>
        <li class="nav-item"><a class="nav-link py-1<?= $key === $show ? ' active' : '' ?>" <?= $key === $show ? 'aria-current="page"' : '' ?> href="/knowledge?show=<?= htmlspecialchars($key) ?>">
            <?= htmlspecialchars($label) ?> <span class="ui-mono small"><?= (int) $counts[$key] ?></span></a></li>
    <?php endforeach; ?>
</ul>
<?php
$move = fn($r, string $to, string $label, string $icon) => ['label' => $label, 'post' => '/knowledge/status', 'fields' => ['id' => (int) $r->id, 'to' => $to, 'back' => $show], 'icon' => $icon];
$empty = [
    'candidate' => ['icon' => 'inbox', 'title' => 'Nothing is waiting for you', 'text' => "Candidates arrive from lessons projects' agents write down about the platform, and from tickets you turn into entries below."],
    'published' => ['icon' => 'journal-bookmark', 'title' => 'Nothing is published yet', 'text' => 'Agents that ask get "nothing known" until there is.', 'action' => ['label' => 'Write an entry', 'url' => '/knowledge/edit', 'icon' => 'plus-lg']],
    'retired'   => ['icon' => 'archive', 'title' => 'Nothing has been retired', 'text' => 'Retire an entry when what it describes has been fixed for good.'],
    'dismissed' => ['icon' => 'slash-circle', 'title' => 'Nothing has been dismissed', 'text' => 'A dismissed candidate is kept so the same thing is not offered to you twice.'],
][$show];

echo Ui::table([
    'rows' => $rows, 'count' => $show === 'candidate' ? 'candidate' : 'entry', 'empty' => $empty,
    'columns' => array_values(array_filter([
        ['label' => 'What is seen', 'value' => 'title', 'sub' => fn($r) => (string) $r->fix, 'url' => fn($r) => '/knowledge/edit?id=' . (int) $r->id],
        ['label' => 'From', 'value' => 'source', 'sub' => fn($r) => (string) ($r->sourceNote ?? ''),
         'labels' => ['seed' => 'Known incident', 'support' => 'A support ticket', 'notebook' => "A project's notebook", 'manual' => 'Written here']],
        $show === 'published' ? ['label' => 'Times given', 'value' => fn($r) => (int) ($r->hits ?? 0), 'as' => 'number'] : null,
        $show === 'published' ? ['label' => 'Last confirmed', 'value' => fn($r) => (string) ($r->reviewedAt ?? ''), 'as' => 'date', 'quiet' => true]
                              : ['label' => 'Added', 'value' => fn($r) => (string) ($r->createdAt ?? ''), 'as' => 'date', 'quiet' => true],
    ])),
    'actions' => fn($r) => array_values(array_filter([
        $show === 'candidate' ? ['label' => 'Review', 'url' => '/knowledge/edit?id=' . (int) $r->id, 'icon' => 'pencil'] : ['label' => 'Edit', 'url' => '/knowledge/edit?id=' . (int) $r->id, 'icon' => 'pencil'],
        $show === 'candidate' ? $move($r, 'dismissed', 'Dismiss', 'slash-circle') : null,
        $show === 'published' ? $move($r, 'retired', 'Retire', 'archive') : null,
        in_array($show, ['retired', 'dismissed'], true) ? $move($r, 'candidate', 'Reconsider', 'arrow-counterclockwise') : null,
        ['label' => 'Delete', 'post' => '/knowledge/delete', 'fields' => ['id' => (int) $r->id, 'back' => $show], 'icon' => 'trash',
         'confirm' => "Delete “{$r->title}” for good? If it came from a project, the same thing can then be offered to you again.", 'danger' => true],
    ])),
]);

// ---- try a question, as an agent would ask it ------------------------------------------------
echo Ui::form(['action' => '/knowledge', 'method' => 'get', 'title' => 'Try a question', 'submit' => 'See what an agent would get',
    'lead' => 'Ask it the way an agent would: what it saw, with the exact error if there was one. This does not count as a time given.',
    'hidden' => ['show' => $show], 'values' => ['q' => $q],
    'fields' => [['name' => 'q', 'label' => 'The question', 'type' => 'textarea', 'rows' => 2, 'required' => true,
                  'placeholder' => 'git push fails with 403 — will the next task see my commit?']]]);
if ($answers !== null):
    if (!$answers):
        echo Ui::notice(['tone' => 'warning', 'title' => 'Nothing would be given.', 'text' => 'No published entry answers that closely enough, so the agent would go on to ask its user about support. If it should have matched, the entry needs the words an agent would use — in its title or "what is seen".']);
    else: foreach ($answers as $a): ?>
        <section class="ui-panel" style="max-width:46rem">
            <div class="ui-panel-header"><h3><a class="ui-row-link" href="/knowledge/edit?id=<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['title']) ?></a></h3>
                <span class="ui-count">match <?= (int) round($a['score'] * 100) ?>%</span></div>
            <div class="ui-panel-body"><p class="mb-2"><?= nl2br(htmlspecialchars($a['fix'])) ?></p>
                <?php if ($a['cause'] !== ''): ?><p class="ui-sub small mb-0"><strong>Why:</strong> <?= htmlspecialchars($a['cause']) ?></p><?php endif; ?></div>
        </section>
    <?php endforeach; endif;
endif;

// ---- tickets a person answered: each is a real question and a real answer --------------------
if ($tickets) {
    echo Ui::table(['rows' => $tickets, 'title' => 'Answered tickets to draw from',
        'columns' => [['label' => 'Ticket', 'value' => 'subject', 'sub' => 'project', 'url' => fn($t) => '/knowledge/edit?ticket=' . (int) $t['id']],
                      ['label' => 'Opened', 'value' => 'at', 'as' => 'date']],
        'actions' => fn($t) => [['label' => 'Draft an entry', 'url' => '/knowledge/edit?ticket=' . (int) $t['id'], 'icon' => 'journal-plus']],
        'empty' => ['title' => 'No answered tickets', 'text' => 'They appear here once support has replied.']]);
}
