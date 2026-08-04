#!/usr/bin/env php
<?php
/**
 * Check what monday.com actually returns, before anything is built on top of it.
 *
 * The connector's queries are written against a pinned API version and a
 * documented shape. This proves that shape against a real account rather than
 * against the documentation, which is the difference between "should work" and
 * "works" — and it prints the columns your boards actually use, which is what
 * decides how an item gets turned into work.
 *
 * The token is read from the environment or from a stored connection, never from
 * an argument: arguments are visible in `ps` to every user on the box and land in
 * shell history.
 *
 *   MONDAY_TOKEN=... php scripts/monday-probe.php            # before connecting
 *   php scripts/monday-probe.php --connection=12             # after connecting
 *   php scripts/monday-probe.php --connection=12 --board=123 # items on one board
 */

if (php_sapi_name() !== 'cli') { die("CLI only\n"); }

require_once __DIR__ . '/../bootstrap.php';
$app = new app\Bootstrap('conf/config.ini');

use app\Bean;
use app\services\connectors\ConnectorRegistry;

$opts  = getopt('', ['connection:', 'board:', 'items:', 'instance:']);
$token = (string) (getenv('MONDAY_TOKEN') ?: '');

if ($token === '' && isset($opts['connection'])) {
    // Connections live in their instance's own store, so a bare id is ambiguous --
    // id 5 exists in several files and means a different account in each. Pass
    // --instance=<id> to say which, or run this from inside the instance itself.
    $cid = (int) $opts['connection'];
    $iid = (int) ($opts['instance'] ?? 0);

    $reader = function () use ($cid) {
        $c = Bean::load('connections', $cid);
        if (!$c->id || (string) $c->connectorType !== 'monday') return '';
        return \app\ConnectionStore::ownToken($c);
    };

    $token = (string) ($iid > 0
        ? \app\ConnectionStore::withInstall($iid, $reader, '')
        : \app\ConnectionStore::withOwnDb($reader, ''));

    if ($token === '') {
        fwrite(STDERR, "  no usable monday connection with id $cid"
            . ($iid > 0 ? " on instance $iid" : " on this install (pass --instance=<id>)") . "\n");
        exit(1);
    }
}

if ($token === '') {
    fwrite(STDERR, "  No token. Either:\n"
        . "    MONDAY_TOKEN=... php scripts/monday-probe.php\n"
        . "  or connect monday.com on /connections and pass --connection=<id>.\n");
    exit(1);
}

/** @var \app\services\connectors\MondayConnector $monday */
$monday = ConnectorRegistry::get('monday');

// ---- 1. who is this token? ------------------------------------------------------
echo "\n  who the token belongs to\n  " . str_repeat('-', 60) . "\n";
try {
    $who = $monday->validateApiKey($token);
    foreach (['external_name' => 'account', 'external_eid' => 'account id', 'external_url' => 'url'] as $k => $label) {
        printf("  %-12s %s\n", $label, $who[$k]);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "  FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

// ---- 2. boards ------------------------------------------------------------------
echo "\n  boards this token can see\n  " . str_repeat('-', 60) . "\n";
try {
    $boards = $monday->boards($token, 25);
    if (!$boards) echo "  (none — the token may be scoped to a different workspace)\n";
    foreach ($boards as $b) {
        printf("  %-12s %-34s %4d items  %s\n",
            $b['id'], mb_strimwidth($b['name'], 0, 33, '…'), $b['items_count'], $b['workspace']);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "  FAILED listing boards: " . $e->getMessage() . "\n");
    exit(1);
}

// ---- 3. items on one board -------------------------------------------------------
$boardId = (string) ($opts['board'] ?? ($boards[0]['id'] ?? ''));
if ($boardId === '') { echo "\n  no board to read items from\n"; exit(0); }

$limit = (int) ($opts['items'] ?? 10);
echo "\n  items on board $boardId (first $limit)\n  " . str_repeat('-', 60) . "\n";
try {
    $page = $monday->items($token, $boardId, $limit);
    if (!$page['items']) echo "  (no items)\n";

    foreach ($page['items'] as $it) {
        printf("  %-12s %-40s [%s]\n", $it['id'], mb_strimwidth($it['name'], 0, 39, '…'), $it['group']);
    }

    // The useful part: which columns your boards actually populate. This is what a
    // decomposition step would be handed, so it is worth seeing before designing it.
    echo "\n  columns in use across those items\n  " . str_repeat('-', 60) . "\n";
    $seen = [];
    foreach ($page['items'] as $it) {
        foreach ($it['fields'] as $col => $val) {
            $seen[$col] ??= ['n' => 0, 'sample' => $val];
            $seen[$col]['n']++;
        }
    }
    if (!$seen) echo "  (items carry no populated columns — names only)\n";
    arsort($seen);
    foreach ($seen as $col => $info) {
        printf("  %-26s %2d/%d items   e.g. %s\n",
            $col, $info['n'], count($page['items']),
            mb_strimwidth(str_replace("\n", ' ', $info['sample']), 0, 40, '…'));
    }

    echo "\n  next page cursor: " . ($page['cursor'] !== '' ? 'yes' : 'none') . "\n";

    // One item in full, so the update/comment shape is visible too.
    if ($page['items']) {
        $full = $monday->item($token, $page['items'][0]['id']);
        echo "\n  one item in full (" . ($full['name'] ?? '?') . ")\n  " . str_repeat('-', 60) . "\n";
        printf("  board=%s group=%s state=%s\n", $full['board'], $full['group'], $full['state']);
        printf("  fields: %d, updates: %d\n", count($full['fields']), count($full['updates']));
        foreach (array_slice($full['updates'], 0, 2) as $u) {
            printf("    update by %-16s %s\n", $u['author'], mb_strimwidth($u['body'], 0, 44, '…'));
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "  FAILED reading items: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n  ok — the connector's queries match this account.\n\n";
