<?php
/**
 * 03_MessagingCategories.php — the two kinds of message every install has: transactional (receipts, sign-in codes; always on) and
 * reminders (for something a member said they would attend; they may switch it off, and texts are opt-in). An app registers its own
 * kinds with Model_Msgcategory::register() in a seed of its own. Idempotent: each is brought in line with these words.
 */
foreach ([
    ['transactional', 'Transactional', 'Receipts, sign-in codes and security — always on', true, false],
    ['reminders', 'Reminders', 'Reminders for things you said you would attend', true, true],
] as [$code, $label, $desc, $on, $out]) {
    \Model_Msgcategory::register($code, $label, $desc, $on, $out);
    echo "  msgcategory $code ready\n";
}
