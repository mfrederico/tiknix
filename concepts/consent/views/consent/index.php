<?php
$groups = \app\concepts\consent\Groups::ALL;
$enforce = $mode === 'enforce';
$rows = array_map(fn($s) => (object) $s, \app\concepts\consent\Inventory::merged($services, true));
$unsorted = array_filter($rows, fn($s) => $s->group === '');
$sorted = array_filter($rows, fn($s) => $s->group !== '');
$from = ['connection' => 'This site uses it', 'owner' => 'You sorted it', 'known' => 'Recognised', 'seen' => 'Seen on a page'];
$moves = fn($s) => $s->from === 'connection' ? [] : array_values(array_map(fn($k) => ['label' => 'Move to ' . $groups[$k][0], 'post' => '/consent/sort', 'fields' => ['host' => $s->host, 'group' => $k]], array_filter(array_keys($groups), fn($k) => $k !== $s->group)));
$columns = [['label' => 'Service', 'value' => 'name', 'sub' => 'hosts'],
            ['label' => 'What it does', 'value' => fn($s) => $s->advice !== '' ? $s->advice : ($s->purpose !== '' ? $s->purpose : 'Tried to load on ' . ($s->page ?: 'a page'))],
            ['label' => 'How we know', 'value' => fn($s) => $from[$s->from], 'quiet' => true]];
echo \app\Ui::page(['title' => 'Cookie consent',
    'lead' => $enforce ? 'Visitors are asked. Outside services wait for their answer.' : 'Watching. Nothing is blocked yet, and no one is asked.',
    'primary' => $enforce ? ['label' => 'Stop asking visitors', 'post' => '/consent/mode', 'fields' => ['mode' => 'watch'], 'confirm' => 'Stop asking? Outside services will load for everyone without a yes.']
                          : ['label' => 'Start asking visitors', 'post' => '/consent/mode', 'fields' => ['mode' => 'enforce'], 'confirm' => 'Start asking? Anything not sorted as Needed is blocked until a visitor says yes, and unsorted services are blocked for everyone.'],
    'beside' => ['label' => 'Scan my site', 'icon' => 'search', 'post' => '/consent/scan'],
    'more' => [['label' => 'See the visitor\'s page', 'url' => '/consent/cookies'], ['label' => 'Ask everyone again', 'post' => '/consent/askagain', 'confirm' => 'Ask every visitor again on their next visit?']]]);
if ($unsorted) echo \app\Ui::notice(['tone' => 'warning', 'title' => count($unsorted) . ' to sort.', 'text' => 'Say what each one is for. ' . ($enforce ? 'They are blocked until you do.' : 'They will be blocked when you start asking.')]);
elseif (!$enforce) echo \app\Ui::notice(['title' => 'How this works.', 'text' => 'Browse your site for a while. Every outside service a page loads appears here. When the list looks complete, start asking.']);
if ($scan !== null) include __DIR__ . '/_scan.php';
if ($unsorted) echo \app\Ui::table(['title' => 'Needs sorting', 'rows' => array_values($unsorted), 'columns' => $columns, 'actions' => $moves,
    'empty' => ['title' => 'Nothing to sort']]);
foreach ($groups as $key => [$name, $line]) {
    echo \app\Ui::table(['title' => $name, 'rows' => array_values(array_filter($sorted, fn($s) => $s->group === $key)), 'columns' => $columns, 'actions' => $moves,
        'empty' => ['title' => 'None in this group', 'text' => $line]]);
}
echo \app\Ui::detail(['title' => 'Proof', 'items' => ['Answers kept' => (string) $proofs, 'Kept for' => 'The life of the answer plus a year. No names or addresses.']]);
