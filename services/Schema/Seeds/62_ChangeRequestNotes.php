<?php
/**
 * 62_ChangeRequestNotes.php — a change request may carry notes pointed at things on its site's pages (the runtime's
 * public/js/notes.js): changerequest.notes_json, TEXT, '[]' for a request without them. Also the route that sends a
 * request to its project's Ask (Changerequests::ask) — covered by changerequests::* (61), said here for the record.
 */
$cols = \app\Bean::inspect('changerequest');
if (!$cols) throw new \RuntimeException('62_ChangeRequestNotes: there is no changerequest table (61_ChangeRequests makes it).');
if (!isset($cols['notes_json'])) {
    \app\Bean::exec("ALTER TABLE changerequest ADD COLUMN notes_json TEXT DEFAULT '[]'");
    echo "  changerequest: + notes_json\n";
} else {
    echo "  changerequest: notes_json unchanged\n";
}
