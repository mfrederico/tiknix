<?php
/** What on this install nobody opens — app\PageUse::report(). */
$by = $report['by'];
$page = fn(array $r) => '/' . $r['control'] . ($r['method'] === 'index' ? '' : '/' . ($r['method'] === '*' ? '…' : $r['method']));
$cols = [
    ['label' => 'Page', 'value' => $page, 'sub' => fn($r) => $r['description'] !== '' ? $r['description'] : null],
    ['label' => 'Why it is here', 'value' => 'why'],
    ['label' => 'Who can open it', 'value' => fn($r) => \app\PageUse::audience($r['level']), 'as' => 'badge', 'tones' => ['Anyone' => 'warning', 'Members' => 'info', 'Admins' => 'secondary', 'Root' => 'secondary']],
    ['label' => 'Opened', 'value' => 'hits', 'as' => 'number'],
    ['label' => 'In a menu', 'value' => fn($r) => $r['linked'], 'as' => 'bool', 'quiet' => true],
];
$none = fn(string $t) => ['title' => $t, 'text' => 'Nothing to do here.'];
?>
<?= \app\Ui::page(['title' => 'What is used', 'lead' => 'Pages and rules on this install that nobody opens — what could be folded away or removed.',
                   'more' => [['label' => 'Change a rule on Permissions', 'url' => '/admin/permissions']]]) ?>
<?= \app\Ui::notice(['tone' => 'info', 'title' => 'How to read this',
    'text' => 'Each number is how many times a permitted request used that rule, ever — ' . number_format($report['hits']) . ' across ' . (int) $report['rules'] . ' rules. '
            . 'It is not per person and not recent, and it counts machines as well as people: a page that refreshes itself, a timer or a crawler each count like a visit. '
            . '"Never" and "the page is gone" can be trusted. A large number cannot be read as "people use this".']) ?>
<?= \app\Ui::table(['title' => 'The page is gone', 'count' => 'rule', 'rows' => $by['gone'], 'columns' => $cols, 'empty' => $none('No leftover rules')]) ?>
<?= \app\Ui::table(['title' => 'Never opened', 'count' => 'page', 'rows' => $by['never'], 'columns' => $cols, 'empty' => $none('Every page has been opened at least once')]) ?>
<?= \app\Ui::table(['title' => 'Rarely opened — less than once a week', 'count' => 'page', 'rows' => $by['rare'], 'columns' => $cols, 'empty' => $none('Nothing is rarely opened')]) ?>
<?= \app\Ui::table(['title' => 'Cannot be told apart — one count for several pages', 'count' => 'rule', 'rows' => $by['unclear'], 'columns' => $cols, 'empty' => $none('Every rule counts one page')]) ?>
<?= \app\Ui::table(['title' => 'Served from somewhere else — not this install\'s own pages', 'count' => 'rule', 'rows' => $by['elsewhere'], 'columns' => $cols, 'empty' => $none('Every counted rule has its page here')]) ?>
<?= \app\Ui::table(['title' => 'Too new to say', 'count' => 'rule', 'rows' => $by['new'], 'columns' => $cols, 'empty' => $none('No new rules')]) ?>
<?= \app\Ui::table(['title' => 'In use', 'count' => 'rule', 'rows' => array_slice($by['used'], 0, 25), 'columns' => $cols, 'empty' => $none('Nothing counted yet')]) ?>
