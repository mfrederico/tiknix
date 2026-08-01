#!/usr/bin/env php
<?php
/**
 * Communications Phase 1 — give every existing thread a participant row.
 *
 * Existing threads are email conversations with a single owner. Each becomes a thread
 * with exactly one participant, at a read position that preserves what the old
 * unread_count said: a thread showing unread keeps one message waiting, a thread showing
 * none is fully read. Nobody's bell should move because of this script.
 *
 * Also stamps emailthread.kind = 'email' so later code can tell an email conversation
 * from a room without guessing.
 *
 * Idempotent. Run with --dry-run to see what it would do.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use \RedBeanPHP\R as R;
use \app\Bean;
use \app\ThreadMembers;

$dry = in_array('--dry-run', $argv, true);
echo $dry ? "DRY RUN — nothing will be written\n\n" : "";

// The message columns Phase 1 adds. These MUST exist before anything reads them:
// RedBean's fluid mode swallows a query against a missing column and returns NULL rather
// than raising, so a feature that depends on one appears to work and quietly answers
// zero. Writing a real value is what creates the column.
//
//   sender_member_id — which ACCOUNT sent it (0 = not from an account, e.g. inbound email)
//   transport        — how it travelled ('email' now; 'inapp' from Phase 2)
$needCols = (int) R::getCell("SELECT COUNT(*) FROM notify") > 0
    && !in_array('sender_member_id', array_map(
        fn($c) => $c['name'], R::getAll("PRAGMA table_info(notify)")), true);

if ($needCols && !$dry) {
    foreach (R::findAll('notify') as $n) {
        $n->senderMemberId = 0;        // pre-existing traffic came from an address, not an account
        $n->transport      = 'email';
        R::store($n);
    }
    echo "stamped sender_member_id + transport on " . R::count('notify') . " messages\n\n";
} elseif ($needCols) {
    echo "would stamp sender_member_id + transport on " . R::count('notify') . " messages\n\n";
}

$threads = R::findAll('emailthread', 'ORDER BY id');
printf("%d threads\n\n", count($threads));

$stats = ['participants' => 0, 'kind' => 0, 'skipped_no_owner' => 0, 'already' => 0];

foreach ($threads as $t) {
    $id    = (int) $t->id;
    $owner = (int) $t->ownerMemberId;

    if ($owner <= 0) {
        // A thread nobody owns cannot be given a participant. Say so rather than
        // inventing one — these are visible to ROOT and would otherwise vanish quietly
        // the moment the thread list starts reading participants.
        printf("  #%-4d SKIP  no owner_member_id (subject: %s)\n", $id, mb_substr((string)$t->subject, 0, 48));
        $stats['skipped_no_owner']++;
        continue;
    }

    $existing = Bean::findOne('threadmember', 'thread_id = ? AND member_id = ?', [$id, $owner]);
    if ($existing && $existing->id) {
        $stats['already']++;
    } elseif (!$dry) {
        ThreadMembers::ensure($id, [$owner], ThreadMembers::ROLE_OWNER);

        // Preserve the badge exactly. unread_count > 0 means "something is waiting", so
        // rewind the read mark to just before the newest message; otherwise mark all read.
        if ((int) $t->unreadCount > 0) {
            ThreadMembers::markUnread($id, $owner);
        } else {
            ThreadMembers::markRead($id, $owner);
        }
        $stats['participants']++;
    } else {
        $stats['participants']++;
    }

    if (empty($t->kind)) {
        if (!$dry) { $t->kind = 'email'; R::store($t); }
        $stats['kind']++;
    }
}

echo "\n";
printf("  participants created : %d\n", $stats['participants']);
printf("  already present      : %d\n", $stats['already']);
printf("  kind='email' stamped  : %d\n", $stats['kind']);
printf("  skipped (no owner)   : %d\n", $stats['skipped_no_owner']);

if ($dry) { echo "\nDRY RUN — nothing written.\n"; exit(0); }

// The whole point of the backfill is that no badge moves. Prove it rather than assert it.
echo "\nVerifying the bell is unchanged for every owner:\n";
$owners = array_values(array_unique(array_map('intval', R::getCol(
    'SELECT owner_member_id FROM emailthread WHERE owner_member_id > 0'
))));
$bad = 0;
foreach ($owners as $mid) {
    $old = (int) R::getCell(
        'SELECT COUNT(*) FROM emailthread WHERE owner_member_id = ? AND unread_count > 0', [$mid]);
    $new = ThreadMembers::unreadThreadCount($mid);
    $ok  = $old === $new;
    if (!$ok) $bad++;
    printf("  member %-4d  old=%-3d new=%-3d  %s\n", $mid, $old, $new, $ok ? 'ok' : '*** MISMATCH ***');
}
echo $bad === 0
    ? "\nAll bells match. Phase 1 backfill complete.\n"
    : "\n$bad member(s) MISMATCHED — do not move the read path over until this is understood.\n";
exit($bad === 0 ? 0 : 1);
