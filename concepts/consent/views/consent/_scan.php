<?php
// What the last scan found (Scan::latest). Vars: $scan, $enforce
if ($scan['state'] === 'running') { echo \app\Ui::notice(['title' => 'Scanning your site now.', 'text' => 'It started at ' . $scan['at'] . ' and takes a few minutes. Reload this page to see what it found.']); return; }
if ($scan['state'] === 'stale') { echo \app\Ui::notice(['tone' => 'danger', 'title' => 'The last scan did not finish.', 'text' => 'It started at ' . $scan['at'] . ' and never came back. Try it again.']); return; }
if ($scan['state'] === 'failed') { echo \app\Ui::notice(['tone' => 'danger', 'title' => 'The last scan failed.', 'text' => $scan['error']]); return; }
$obj = fn(array $rows) => array_map(fn($r) => (object) $r, $rows);
$clean = !$scan['waiting'] && !$scan['cookies'] && !$scan['broken'];
echo \app\Ui::notice(['tone' => $clean ? 'success' : 'warning', 'title' => $clean ? 'The scan found nothing to fix.' : 'The scan found things to look at.',
    'text' => $scan['pages'] . ' page views checked on ' . $scan['at'] . ', as a visitor and as a new member, before answering the cookie question. Pages only an admin or a member with content can reach are not included.']);
if ($scan['waiting']) echo \app\Ui::table(['title' => $enforce ? 'Ran before any answer. It should not have.' : 'Runs before any answer today. It will wait once you start asking.', 'rows' => $obj($scan['waiting']),
    'columns' => [['label' => 'Service', 'value' => 'name', 'sub' => 'host'], ['label' => 'Where', 'value' => 'page', 'sub' => 'who']], 'empty' => ['title' => 'None']]);
if ($scan['cookies']) echo \app\Ui::table(['title' => 'Cookies set before any answer. The browser rule cannot stop these: remove them, or start them only after a yes.', 'rows' => $obj($scan['cookies']),
    'columns' => [['label' => 'Cookie', 'value' => 'name', 'sub' => 'domain'], ['label' => 'Where', 'value' => 'page', 'sub' => 'who']], 'empty' => ['title' => 'None']]);
if ($scan['broken']) echo \app\Ui::table(['title' => 'Pages that answered with an error', 'rows' => $obj($scan['broken']),
    'columns' => [['label' => 'Page', 'value' => 'path', 'sub' => 'who'], ['label' => 'Error', 'value' => 'status']], 'empty' => ['title' => 'None']]);
if ($scan['storage']) echo \app\Ui::table(['title' => 'Kept in the browser by this site. Fine if the site needs it to work.', 'rows' => $obj($scan['storage']),
    'columns' => [['label' => 'Name', 'value' => 'key', 'sub' => 'kind'], ['label' => 'Where', 'value' => 'page', 'sub' => 'who']], 'empty' => ['title' => 'None']]);
