<?php
/**
 * change-requests.php — the open change requests of Tiknix itself (notes its ROOT made on tiknix.com with the Notes button,
 * lib/PlatformChanges.php), for the agent in this terminal: each one as the Builder would be briefed (SiteChanges::brief:
 * what was asked, each note's page, selector, HTML, picture and attached files), with where to mark it done.
 *
 *   php scripts/change-requests.php            the open ones (new, being built)
 *   php scripts/change-requests.php --all      every one, newest first
 *   php scripts/change-requests.php --id=12    one
 */
require __DIR__ . '/../vendor/autoload.php';
require_once \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap();

use app\Bean;

$o = getopt('', ['all', 'id:']);
$inst = \app\PlatformChanges::project();
$where = 'instance_id = ?'; $bind = [(int) $inst->id];
if (isset($o['id'])) { $where .= ' AND id = ?'; $bind[] = (int) $o['id']; }
elseif (!isset($o['all'])) $where .= " AND status IN ('new', 'building')";
$rows = Bean::find('changerequest', $where . ' ORDER BY id DESC', $bind);
if (!$rows) { echo "No " . (isset($o['all']) ? '' : 'open ') . "change requests for Tiknix itself.\n"; exit(0); }
$base = rtrim((string) Flight::get('app.baseurl'), '/');
foreach ($rows as $cr) {
    echo str_repeat('=', 100), "\n#{$cr->id} · " . (\app\SiteChanges::LABELS[(string) $cr->status] ?? $cr->status) . " · {$cr->createdAt} · {$base}/changerequests/view?id={$cr->id}\n", str_repeat('=', 100), "\n";
    echo \app\SiteChanges::brief($cr), "\n";
}
echo count($rows), " request(s). Mark one done on its page when it is (the person who asked is told).\n";
