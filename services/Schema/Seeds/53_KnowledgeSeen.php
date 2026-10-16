<?php
use RedBeanPHP\R;
/**
 * 53_KnowledgeSeen.php — how often the same lesson came up (app\KnowledgeBase::propose).
 *
 *   seen     INTEGER  times a lesson was offered: 1 when it was first written, +1 each time a
 *                     project's agent wrote the same thing down again (before 2026-10-16 a repeat
 *                     was dropped, so nobody could tell a one-off from a mistake every task makes)
 *   seen_in  TEXT     the projects it came from, one slug per line
 *   ruled_seen INTEGER  `seen` when it was made a rule (KnowledgeBase::markRuled), else NULL
 *
 * Declared, not left to the first store: an INTEGER and a TEXT from the start (a later widen
 * rebuilds a SQLite table and empties it). Existing rows start at 1.
 */
$cols = R::inspect('kbentry');
if (!isset($cols['seen']))    { R::exec('ALTER TABLE kbentry ADD COLUMN seen INTEGER'); echo "  kbentry.seen: added\n"; }
if (!isset($cols['seen_in'])) { R::exec('ALTER TABLE kbentry ADD COLUMN seen_in TEXT'); echo "  kbentry.seen_in: added\n"; }
// What `seen` was when the lesson was written into the agents' guidelines: it is offered as a rule
// again only if it comes up RULE_AFTER more times after that (the rule is not working).
if (!isset($cols['ruled_seen'])) { R::exec('ALTER TABLE kbentry ADD COLUMN ruled_seen INTEGER'); echo "  kbentry.ruled_seen: added\n"; }
$n = R::exec('UPDATE kbentry SET seen = 1 WHERE seen IS NULL');
echo "  kbentry.seen: {$n} row(s) started at 1\n";

// An entry written from several candidates (the 2026-10-16 review: "merged from notebook
// candidates #29, #97, #113") has come up once for each of them, in their projects.
$merged = 0;
foreach (R::getAll("SELECT id, source_note FROM kbentry WHERE seen = 1 AND source_note LIKE 'merged from notebook candidates #%'") as $e) {
    if (!preg_match_all('/#(\d+)/', (string) $e['source_note'], $m)) continue;
    $ids = array_map('intval', $m[1]);
    $in = [];
    foreach (R::getCol('SELECT source_note FROM kbentry WHERE id IN (' . R::genSlots($ids) . ')', $ids) as $note) {
        $p = trim((string) preg_split('/\s+—\s+/u', (string) $note, 2)[0]);
        if ($p !== '' && !in_array($p, $in, true)) $in[] = $p;
    }
    R::exec('UPDATE kbentry SET seen = ?, seen_in = ? WHERE id = ?', [count($ids), implode("\n", $in), (int) $e['id']]);
    $merged++;
}
echo "  kbentry.seen: {$merged} merged entr" . ($merged === 1 ? 'y' : 'ies') . " counted from their candidates\n";
