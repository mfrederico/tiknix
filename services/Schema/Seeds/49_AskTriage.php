<?php
/**
 * 49_AskTriage.php — what became of something typed into Ask before any plan (app\AskTriage):
 * ask_state (thinking | answer | unsure | failed | build), the answer, and when. Declared as
 * TEXT here, not left to the first store: an answer is long, and a column RedBean typed from a
 * short first value is rebuilt — with the table's rows — when a long one arrives.
 */
$have = array_column(\RedBeanPHP\R::getAll('PRAGMA table_info(promptlog)'), 'name');
foreach (['ask_state' => 'TEXT', 'ask_answer' => 'TEXT', 'ask_at' => 'TEXT'] as $col => $type) {
    if (in_array($col, $have, true)) continue;
    \RedBeanPHP\R::exec("ALTER TABLE promptlog ADD COLUMN {$col} {$type}");
    echo "  promptlog: {$col} added\n";
}
