<?php
/**
 * 00_BrowserdriveSchema.php — the session queue's tables and indexes, typed, so a request never has to create a column.
 * Idempotent. DDL has no Bean:: equivalent, so Bean::exec is the justified use here.
 *   drivesession  a session: where it starts, who opened it, which runner page plays it, its state and why it ended
 *   drivestep     one command of a session (drivesession_id), its result as JSON
 */

use \app\Bean;

$schema = [
    'drivesession' => ['path' => 'TEXT', 'note' => 'TEXT', 'status' => 'TEXT', 'opened_by' => 'INTEGER', 'runner' => 'INTEGER', 'steps' => 'INTEGER',
                       'opened_at' => 'TEXT', 'claimed_at' => 'TEXT', 'seen_at' => 'TEXT', 'closed_at' => 'TEXT', 'why' => 'TEXT'],
    'drivestep'    => ['drivesession_id' => 'INTEGER', 'n' => 'INTEGER', 'cmd' => 'TEXT', 'status' => 'TEXT', 'result' => 'TEXT',
                       'queued_at' => 'TEXT', 'sent_at' => 'TEXT', 'done_at' => 'TEXT'],
];
foreach ($schema as $table => $columns) {
    if (!in_array($table, Bean::inspect(), true)) {
        $defs = ['`id` INTEGER PRIMARY KEY AUTOINCREMENT'];
        foreach ($columns as $col => $type) $defs[] = "`{$col}` {$type}";
        Bean::exec("CREATE TABLE `{$table}` (" . implode(', ', $defs) . ')');
        echo "  created    {$table}\n";
        continue;
    }
    $existing = Bean::inspect($table);
    $added = [];
    foreach ($columns as $col => $type) {
        if (array_key_exists($col, $existing)) continue;
        Bean::exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$type}");
        $added[] = $col;
    }
    echo $added ? "  updated    {$table} +" . implode(',', $added) . "\n" : "  unchanged  {$table}\n";
}
Bean::exec('CREATE INDEX IF NOT EXISTS `index_drivesession_status` ON `drivesession` (`status`)');
Bean::exec('CREATE INDEX IF NOT EXISTS `index_drivestep_session` ON `drivestep` (`drivesession_id`, `status`)');
