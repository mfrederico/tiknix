<?php
/**
 * 01_ConsentSchema.php — typed tables for the list of outside services and the proof of answers, so a page view or a
 * report never has to create one. Idempotent. DDL has no Bean:: equivalent, so Bean::exec is the justified use here.
 */

use \app\Bean;

$schema = [
    'consenthost'   => ['host' => 'TEXT', 'kind' => 'TEXT', 'name' => 'TEXT', 'purpose' => 'TEXT', 'page' => 'TEXT', 'directive' => 'TEXT', 'hits' => 'INTEGER', 'first_seen' => 'TEXT', 'last_seen' => 'TEXT'],
    'consentscan'   => ['status' => 'TEXT', 'started_at' => 'TEXT', 'finished_at' => 'TEXT', 'error' => 'TEXT', 'result' => 'TEXT'],
    'consentrecord' => ['rid' => 'TEXT', 'choices' => 'TEXT', 'how' => 'TEXT', 'version' => 'INTEGER', 'member_ref' => 'INTEGER', 'at' => 'TEXT'],
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
Bean::exec('CREATE UNIQUE INDEX IF NOT EXISTS `unique_consenthost_host` ON `consenthost` (`host`)');
Bean::exec('CREATE INDEX IF NOT EXISTS `index_consentrecord_at` ON `consentrecord` (`at`)');
