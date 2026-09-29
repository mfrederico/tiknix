<?php
/**
 * LeadSpamCheck — heuristic detector for garbage / bot-generated lead names.
 *
 * Real human names keep a sane consonant↔vowel balance; automated form-fillers
 * routinely submit keyboard-mash strings ("Xkgbrtz", "Qwrtplkj") with no vowels
 * and long consonant runs. This flags those so an admin can review or purge them
 * from the Leads view — and it can be reused at ingestion time to reject junk
 * before it is ever stored.
 *
 * Deliberately CONSERVATIVE: it only trips on strong signals so real names
 * (including short or non-English ones) are not caught. 'y' counts as a vowel so
 * names like "Lynn" are safe. Tune via the constants.
 *
 * @package app
 */

namespace app;

class LeadSpamCheck {

    /** Names shorter than this are not judged (too little signal, unfair to flag). */
    private const MIN_LEN = 4;
    /** Below this vowel fraction a part is gibberish (e.g. 1 vowel in 9+ letters). */
    private const MIN_VOWEL_RATIO = 0.12;
    /** This many consonants in a row is a near-certain mash (real names rarely hit 5). */
    private const MAX_CONSONANT_RUN = 5;

    /** 'y' included — treating it as a vowel avoids false positives (Lynn, Wynn, …). */
    private const VOWELS = 'aeiouyàáâäãåèéêëìíîïòóôöõùúûü';

    /**
     * Evaluate a first/last pair.
     *
     * @return array{suspicious:bool, reasons:string[]}
     */
    public static function evaluate(?string $first, ?string $last): array {
        $reasons = [];
        foreach (['First' => $first, 'Last' => $last] as $label => $val) {
            $why = self::checkPart((string)$val);
            if ($why !== '') $reasons[] = $label . ' name ' . $why;
        }
        return ['suspicious' => !empty($reasons), 'reasons' => $reasons];
    }

    /** Convenience boolean for callers that just want yes/no. */
    public static function isSuspicious(?string $first, ?string $last): bool {
        return self::evaluate($first, $last)['suspicious'];
    }

    /**
     * Inspect ONE name part. Returns a short human reason if it looks like
     * gibberish, or '' if it looks like a plausible name.
     */
    private static function checkPart(string $name): string {
        // Unicode letters only, lowercased. Digits/punctuation dropped first.
        $letters = preg_replace('/[^\p{L}]/u', '', $name);
        $lower   = mb_strtolower($letters);
        $len     = mb_strlen($lower);
        if ($len < self::MIN_LEN) return '';   // too short to judge fairly

        $chars   = preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $vowelRe = '/[' . self::VOWELS . ']/u';

        $vowels = 0;
        $run = 0;      // current consonant run
        $maxRun = 0;   // longest consonant run seen
        foreach ($chars as $ch) {
            if (preg_match($vowelRe, $ch)) {
                $vowels++;
                $run = 0;
            } else {
                // Only ASCII/plain consonants extend a run; stray symbols already gone.
                $run++;
                if ($run > $maxRun) $maxRun = $run;
            }
        }

        if ($vowels === 0)                     return 'has no vowels';
        if ($maxRun >= self::MAX_CONSONANT_RUN) return 'has ' . $maxRun . ' consonants in a row';
        if (($vowels / $len) < self::MIN_VOWEL_RATIO) {
            return sprintf('has a very low vowel ratio (%d%%)', (int)round(($vowels / $len) * 100));
        }
        return '';
    }
}
