<?php
/**
 * 50_AskNote.php — the one line worth keeping from an answer given in Ask (app\AskTriage), and
 * when its person put it in the project's notebook. Declared TEXT, as seed 49's columns are.
 */
$have = array_column(\RedBeanPHP\R::getAll('PRAGMA table_info(promptlog)'), 'name');
foreach (['ask_note' => 'TEXT', 'ask_noted_at' => 'TEXT'] as $col => $type) {
    if (in_array($col, $have, true)) continue;
    \RedBeanPHP\R::exec("ALTER TABLE promptlog ADD COLUMN {$col} {$type}");
    echo "  promptlog: {$col} added\n";
}
