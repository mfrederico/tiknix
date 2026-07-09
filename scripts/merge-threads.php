<?php
/**
 * merge-threads.php — repair tool: fold split conversations back into one thread.
 *
 * Reparents every notify + notifyattachment row from the source threads onto the
 * target (earliest) thread, recomputes the target's counts/preview, then trashes
 * the now-empty source threads. Bean-only (respects model hooks).
 *
 *   php scripts/merge-threads.php <targetId> <srcId> [<srcId> ...]
 *
 * Example (fold 15 and 16 back into 14):
 *   php scripts/merge-threads.php 14 15 16
 */

require __DIR__ . '/../bootstrap.php';

use app\Bean;
use RedBeanPHP\R;

// Boot the app (registers the autoloader + connects RedBean via the constructor).
new app\Bootstrap(__DIR__ . '/../conf/config.ini');

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "usage: php scripts/merge-threads.php <targetId> <srcId> [<srcId> ...]\n");
    exit(1);
}

$targetId = (int)array_shift($args);
$srcIds   = array_values(array_unique(array_map('intval', $args)));

$target = Bean::load('emailthread', $targetId);
if (!$target->id) {
    fwrite(STDERR, "target thread {$targetId} not found\n");
    exit(1);
}

foreach ($srcIds as $srcId) {
    if ($srcId === $targetId) continue;
    $src = Bean::load('emailthread', $srcId);
    if (!$src->id) {
        echo "skip: thread {$srcId} not found\n";
        continue;
    }

    $moved = 0;
    foreach (Bean::find('notify', 'thread_id = ?', [$srcId]) as $n) {
        $n->threadId = $targetId;
        Bean::store($n);
        $moved++;
    }
    foreach (Bean::find('notifyattachment', 'thread_id = ?', [$srcId]) as $a) {
        $a->threadId = $targetId;
        Bean::store($a);
    }
    R::trash($src);
    echo "merged thread {$srcId} -> {$targetId} ({$moved} messages)\n";
}

// Recompute the target's rollup fields from its (now complete) message set.
$msgs = Bean::find('notify', 'thread_id = ? ORDER BY created_at ASC, id ASC', [$targetId]);
$target->messageCount = count($msgs);
if ($msgs) {
    $last = end($msgs);
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($last->content ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $target->lastPreview   = mb_substr($plain, 0, 220, 'UTF-8');
    $target->lastDirection = $last->direction ?: $target->lastDirection;
    $target->lastMessageAt = $last->sentAt ?: ($last->createdAt ?: $target->lastMessageAt);
}
$target->updatedAt = date('Y-m-d H:i:s');
Bean::store($target);

echo "target thread {$targetId}: {$target->messageCount} messages\n";
