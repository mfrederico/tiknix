<?php
use app\concepts\consent\{Banner, Groups, Inventory};
$inUse = Banner::inUse($services);
$rows = array_map(fn($s) => (object) $s, array_filter(Inventory::merged($services), fn($s) => $s['group'] !== ''));
$values = [];
$fields = [];
foreach ($inUse as $g => $names) {
    $fields[] = ['name' => 'group_' . $g, 'label' => Groups::name($g), 'type' => 'switch', 'help' => Groups::line($g) . ' ' . implode(', ', $names) . '.'];
    $values['group_' . $g] = in_array($g, $last, true) ? 1 : 0;
}
echo \app\Ui::page(['title' => 'Cookies and outside services', 'lead' => 'What this site keeps in your browser, who else your browser talks to here, and your choices.']);
if ($fields) echo \app\Ui::form(['action' => '/consent/choose', 'submit' => 'Save my choices', 'values' => $values, 'hidden' => ['how' => 'pick'], 'fields' => $fields,
    'lead' => $current === null ? 'You have not chosen yet. Nothing below runs until you say yes.' : 'Change these any time.']);
else echo \app\Ui::notice(['title' => 'Nothing to choose.', 'text' => 'This site uses only what it needs to work, so there is nothing to say yes to.']);
echo \app\Ui::table(['title' => 'Cookies from this site', 'rows' => [(object) ['n' => $sessionCookie, 'w' => 'Keeps you signed in, and keeps forms safe. Needed.'], (object) ['n' => \app\concepts\consent\Choice::COOKIE, 'w' => 'Remembers your choices here, for six months. Needed.']],
    'columns' => [['label' => 'Cookie', 'value' => 'n'], ['label' => 'What it does', 'value' => 'w']], 'empty' => ['title' => 'None']]);
foreach (Groups::ALL as $key => [$name, $line]) {
    $here = array_values(array_filter($rows, fn($s) => $s->group === $key));
    if ($here) echo \app\Ui::table(['title' => $name, 'rows' => $here, 'columns' => [['label' => 'Service', 'value' => 'name', 'sub' => 'hosts'], ['label' => 'What it does', 'value' => 'purpose']], 'empty' => ['title' => 'None']]);
}
