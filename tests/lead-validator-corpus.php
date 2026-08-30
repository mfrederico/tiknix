<?php
/**
 * Corpus test for LeadValidator.
 *
 * The point of this file is the MUST-PASS list. A consonant heuristic that has not
 * been run against real Polish, Welsh, Vietnamese, Slovak and Armenian names is just
 * a guess about which humans are allowed to fill in a form. Anyone tuning
 * MAX_CONSONANT_RUN should run this and see who they are about to exclude.
 *
 *   php tests/lead-validator-corpus.php
 */

require __DIR__ . '/../lib/LeadValidator.php';

use app\LeadValidator;

/** Real names that MUST be accepted. */
$mustPass = [
    'Krzysztof', 'Grzegorz', 'Brzęczyszczykiewicz', 'Wojciech', 'Szczepan',
    'Nguyen', 'Ng', 'Vu', 'Tran', 'Pham',
    'Llywelyn', 'Llŷr', 'Gwynfor', 'Rhys', 'Myfanwy',
    'Mkhitaryan', 'Hovhannisyan',
    'Zdeněk', 'Škoda', 'Dvořák', 'Sedláček',
    "O'Brien", 'Smith-Jones', 'St. John', 'van der Berg', 'Ó Súilleabháin',
    'Müller', 'Ångström', 'Þórsdóttir', 'Björk',
    'Anne-Marie', 'Jean-Luc', 'Mary Jane', 'José', 'Renée', 'Zoë',
    'Wu', 'Li', 'Xu', 'Ba', 'Al',
];

/** Junk that MUST be rejected. */
$mustFail = [
    'asdfgh', 'qwrtzp', 'zxcvbnm', 'hjklmn', 'sdfghjkl',
    'xkcdqz', 'bcdfgh', 'ffffff', 'ttttttt',
    'test123', 'user@name', '<script>', 'http://spam.co', 'Name!!',
    '', '   ',
];

$emailsPass = [
    'first.last@gmail.com', 'jane@example.com', 'bob@company.co.uk',
    'a.b@sub.domain.co.nz', 'no-dots@example.org', 'x_y@example.com',
];
$emailsFail = [
    'first.middle.last@gmail.com', 'j.o.h.n@gmail.com', 'a.b.c.d@example.com',
    'notanemail', 'missing@tld', '',
];

$fails = 0;
$line  = function (bool $ok, string $what, ?string $got) use (&$fails) {
    if (!$ok) $fails++;
    printf("  %s  %-24s %s\n", $ok ? ' ok ' : 'FAIL', $what, $got ?? '(accepted)');
};

echo "\nNames that must be ACCEPTED (real people):\n";
foreach ($mustPass as $n) { $p = LeadValidator::nameProblem($n); $line($p === null, $n, $p); }

echo "\nNames that must be REJECTED (bots and mashes):\n";
foreach ($mustFail as $n) { $p = LeadValidator::nameProblem($n); $line($p !== null, $n === '' ? '(empty)' : $n, $p); }

echo "\nEmails that must be ACCEPTED:\n";
foreach ($emailsPass as $e) { $p = LeadValidator::emailProblem($e); $line($p === null, $e, $p); }

echo "\nEmails that must be REJECTED:\n";
foreach ($emailsFail as $e) { $p = LeadValidator::emailProblem($e); $line($p !== null, $e === '' ? '(empty)' : $e, $p); }

/**
 * Email corroboration: a matching address may rescue the consonant-run rule, and
 * must NOT be able to rescue the vowel or keyboard-walk rules — otherwise the whole
 * filter is defeated by mailing "asdfgh" from asdfgh@gmail.com.
 */
echo "\nEmail corroboration:\n";
$corroboration = [
    // [name, email, must be accepted?, what this case is guarding]
    ['Brzczmyk', 'brzczmyk@example.com',  true,  'odd name, email vouches for it'],
    ['Brzczmyk', 'unrelated@example.com', false, 'same name, no corroboration'],
    ['asdfgh',   'asdfgh@example.com',    false, 'mash cannot buy its way in (keyboard walk)'],
    ['qwrtzp',   'qwrtzp@example.com',    false, 'mash cannot buy its way in (no vowels)'],
    ['Ångström', 'unrelated@example.com', true,  'real name passes without any help'],
];
foreach ($corroboration as [$n, $e, $shouldPass, $why]) {
    $p  = LeadValidator::nameProblem($n, $e);
    $ok = $shouldPass ? ($p === null) : ($p !== null);
    $line($ok, substr($n . ' + ' . $e, 0, 24), $why . ($p ? " [{$p}]" : ' [accepted]'));
}

echo "\n" . ($fails === 0 ? "All corpus cases behaved as intended.\n\n" : "$fails case(s) did NOT behave as intended.\n\n");
exit($fails === 0 ? 0 : 1);
