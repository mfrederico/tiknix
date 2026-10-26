#!/usr/bin/env php
<?php
/**
 * messaging-import.php — the one-time move of this app's messaging rows (the tables the 1.0.0 plugin kept:
 * msgcategory, msgpref, msgsuppression, msgcampaign, msgsend, msgevent) into the messaging service, for the
 * host this process serves — each database once:
 *
 *     php scripts/messaging-import.php                              the main host
 *     TIKNIX_HOST=<other host> php scripts/messaging-import.php   a host with a database of its own
 *
 * Members, kinds and audiences are mirrored first (what the hourly run does), then the choices, the suppression
 * list, the campaigns and their ledger go over as they were (POST /api/v1/import), so "sent once" and the counts
 * stay true. Idempotent: run again, nothing is made twice. Nothing is deleted here; the old tables stay until the
 * old unsubscribe links in sent mail no longer matter.
 */

chdir(dirname(__DIR__));   // the host's own config (conf/hosts/<host>.ini) is chosen from "conf/config.ini", as the pipelines boot it
require 'vendor/autoload.php';
require \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap('conf/config.ini');

use app\Bean;
use app\concepts\messaging\Client;
use app\concepts\messaging\ServiceException;
use app\concepts\messaging\Sync;

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "cli only\n"); exit(2); }
if (!\app\Concepts::has('messaging')) { fwrite(STDERR, "the messaging plugin is not installed\n"); exit(2); }
foreach (['msgcategory', 'msgpref', 'msgsuppression', 'msgcampaign', 'msgsend', 'msgevent'] as $t) {
    if (!in_array($t, Bean::inspect(), true)) { fwrite(STDERR, "no table {$t}: nothing of the old messaging to import for this host\n"); exit(2); }
}

$host = Client::site();
echo "messaging import for {$host} -> " . Client::url() . "\n";
try {
    echo '  categories: ' . Sync::categories() . " kept in line\n";
    $c = Sync::contacts();
    echo "  contacts: {$c['created']} new, {$c['updated']} brought up to date\n";
    echo '  audiences: ' . Sync::audiences() . " mirrored\n";

    $catById = [];
    foreach (Bean::getAll('SELECT id, code FROM msgcategory') as $r) $catById[(int) $r['id']] = (string) $r['code'];
    $senders = [];
    foreach (Bean::getAll('SELECT id, email, first_name, username FROM member') as $r) $senders[(int) $r['id']] = ['eid' => (string) $r['id'], 'email' => (string) $r['email'], 'name' => trim((string) $r['first_name']) ?: (string) $r['username']];

    $doc = ['categories' => [], 'prefs' => [], 'suppressions' => [], 'campaigns' => [], 'sends' => [], 'events' => []];
    foreach (Bean::getAll('SELECT code, label, description, default_on, can_opt_out, channels FROM msgcategory ORDER BY id') as $r) {
        $doc['categories'][] = ['code' => $r['code'], 'label' => $r['label'], 'description' => $r['description'], 'default_on' => (int) $r['default_on'] === 1, 'can_opt_out' => (int) $r['can_opt_out'] === 1, 'channels' => array_map('trim', explode(',', (string) $r['channels']))];
    }
    foreach (Bean::getAll('SELECT member_id, category_ref, channel, enabled, consented_at FROM msgpref') as $r) {
        if (!isset($catById[(int) $r['category_ref']])) continue;
        $doc['prefs'][] = ['contact' => (string) $r['member_id'], 'category' => $catById[(int) $r['category_ref']], 'channel' => (string) $r['channel'], 'enabled' => (int) $r['enabled'] === 1, 'consented_at' => (string) ($r['consented_at'] ?? '')];
    }
    foreach (Bean::getAll('SELECT email, reason, at FROM msgsuppression') as $r) $doc['suppressions'][] = ['email' => $r['email'], 'reason' => $r['reason'], 'at' => (string) $r['at']];
    foreach (Bean::getAll('SELECT * FROM msgcampaign ORDER BY id') as $r) {
        $doc['campaigns'][] = ['ref' => (string) $r['id'], 'kind' => $r['kind'], 'category' => $catById[(int) $r['category_ref']] ?? '', 'scope' => (string) ($r['scope_key'] ?? ''), 'about' => (string) ($r['about_key'] ?? ''),
            'subject' => $r['subject'], 'body_email' => (string) $r['body_email'], 'body_text' => (string) ($r['body_text'] ?? ''), 'body_sms' => (string) ($r['body_sms'] ?? ''),
            'trigger_code' => (string) ($r['trigger_code'] ?? ''), 'trigger_days' => (int) ($r['trigger_days'] ?? 0), 'cadence' => $r['cadence'], 'cadence_days' => (int) ($r['cadence_days'] ?? 0),
            'status' => $r['status'], 'scheduled_at' => (string) ($r['scheduled_at'] ?? ''), 'sent_at' => (string) ($r['sent_at'] ?? ''), 'created_at' => (string) ($r['created_at'] ?? ''),
            'sender' => $senders[(int) $r['sender_id']] ?? ['eid' => (string) $r['sender_id'], 'email' => '', 'name' => '']];
    }
    foreach (Bean::getAll('SELECT * FROM msgsend ORDER BY id') as $r) {
        $doc['sends'][] = ['campaign' => (string) $r['msgcampaign_id'], 'contact' => (string) $r['member_id'], 'channel' => $r['channel'], 'token' => $r['token'], 'provider_eid' => (string) ($r['provider_eid'] ?? ''),
            'links' => (string) ($r['links'] ?? '[]'), 'sent_at' => (string) ($r['sent_at'] ?? ''), 'delivered_at' => (string) ($r['delivered_at'] ?? ''), 'bounced_at' => (string) ($r['bounced_at'] ?? ''),
            'complained_at' => (string) ($r['complained_at'] ?? ''), 'first_click_at' => (string) ($r['first_click_at'] ?? ''), 'clicks' => (int) ($r['clicks'] ?? 0), 'acted_at' => (string) ($r['acted_at'] ?? ''), 'created_at' => (string) ($r['created_at'] ?? '')];
    }
    foreach (Bean::getAll('SELECT s.token, e.kind, e.at, e.meta FROM msgevent e JOIN msgsend s ON s.id = e.msgsend_id ORDER BY e.id') as $r) {
        $doc['events'][] = ['token' => $r['token'], 'kind' => $r['kind'], 'at' => (string) $r['at'], 'meta' => (string) ($r['meta'] ?? '{}')];
    }
    foreach ($doc as $k => $rows) echo "  {$k}: " . count($rows) . " to import\n";

    $r = Client::post('import', $doc)['imported'];
    foreach ($r as $k => $v) {
        echo "  imported {$k}: {$v['made']} made, {$v['kept']} kept" . ($v['skipped'] ? ', ' . count($v['skipped']) . ' skipped' : '') . "\n";
        foreach ($v['skipped'] as $s) echo "    skipped: {$s}\n";
    }
    foreach (Sync::system() as $l) echo "  system: {$l}\n";   // after the import: the digest and nudges that came over are kept, not made again
} catch (ServiceException $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
echo "done\n";
