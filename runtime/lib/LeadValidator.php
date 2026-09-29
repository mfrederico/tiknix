<?php
/**
 * LeadValidator — rejects junk submissions on the lead form before they become leads.
 *
 * This exists to stop bot and keyboard-mash signups ("asdfgh", "qwrtzxv") and
 * throwaway dot-alias addresses. It is a HEURISTIC, not a definition of what a name
 * is, and it is deliberately tuned to let hard real-world names through:
 * Krzysztof, Brzęczyszczykiewicz, Nguyen, Ng, Llywelyn, Mkhitaryan and Zdeněk all
 * pass (see tests/lead-validator-corpus.php, which asserts exactly that).
 *
 * Validate the RAW request value, never the sanitized one: Control::sanitize()
 * runs htmlspecialchars, so O'Brien arrives as "O&#039;Brien" — digits, a hash and
 * a semicolon that no name rule should ever have to reason about.
 *
 * @package app
 */

namespace app;

class LeadValidator {

    /**
     * Longest run of consecutive consonants tolerated inside one word.
     *
     * 6, and the corpus is why. The obvious-looking value is 5, but "Ångström"
     * carries a five-consonant run ("ngstr") and is a real surname — a limit of 5
     * rejects it. Almost every keyboard mash is caught by the vowel rule below
     * rather than by this one ("qwrtzp", "zxcvbnm", "hjklmn" and "xkcdqz" contain
     * no vowel at all), so raising this costs far less than it looks: of the mashes
     * in the corpus only "asdfgh" survives it, and KEYBOARD_WALK_LEN catches that.
     */
    public const MAX_CONSONANT_RUN = 6;

    /**
     * Consecutive keys along one keyboard row that mark a submission as a mash.
     *
     * This is what actually distinguishes "asdfgh" from "Ångström" — both are
     * consonant-dense, but only one is a straight line across the keyboard. Real
     * names do reach three ("Werner" walks w-e-r), so the bar is four.
     */
    public const KEYBOARD_WALK_LEN = 4;

    /** Keyboard rows, for the walk check. */
    private const ROWS = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm'];

    /**
     * How closely the email local part must echo a name to vouch for it (0-1, as
     * 1 - levenshtein/length). 0.7 lets "angstrom" vouch for "Ångström" and
     * "jsmith" for "Smith", while unrelated words stay well below it.
     */
    public const EMAIL_MATCH_RATIO = 0.7;

    /** Below this length a word is exempt from the vowel rule — "Ng" and "Wu" are real. */
    public const VOWEL_EXEMPT_LENGTH = 4;

    /** Dots allowed in the local part (before the @). More than this reads as alias spam. */
    public const MAX_LOCAL_DOTS = 1;

    /**
     * Vowels, including the accented forms, plus 'y' — without 'y' every Welsh and
     * Polish name in the corpus becomes one long consonant run.
     */
    private const VOWELS = 'aeiouyàáâãäåæāăąèéêëēĕėęěìíîïĩīĭįıòóôõöøōŏőùúûüũūŭůűųýÿŷæœ';

    /**
     * Short reasons this submission looks generated, for `lead.spam_reason`. Empty
     * means nothing looked wrong.
     *
     * These FLAG a lead, they do not refuse it — see Index::dolead for why that
     * distinction is the whole basis on which content rules are allowed here at all.
     * Keep the strings terse and stable; they are read in a database column and
     * grouped over, not shown to the visitor.
     *
     * @return string[]
     */
    public static function signals(string $first, string $last, string $email): array {
        $signals = [];
        foreach ([['first name', $first], ['last name', $last]] as [$label, $value]) {
            // A blank name is not a spam signal — dolead already rejects it outright,
            // and calling it spam would bury an ordinary typo among the bots.
            if (trim($value) === '') continue;
            if (self::nameProblem($value, $email) !== null) $signals[] = $label;
        }
        $emailProblem = trim($email) === '' ? null : self::emailProblem($email);
        if ($emailProblem !== null && str_contains($emailProblem, 'dots')) $signals[] = 'email dots';

        return $signals ? ['looks generated: ' . implode(', ', $signals)] : [];
    }

    /**
     * Check a whole submission. Returns a list of human-readable problems, empty
     * when the submission is acceptable.
     *
     * @return string[]
     */
    public static function check(string $first, string $last, string $email): array {
        $errors = [];
        foreach ([['First name', $first], ['Last name', $last]] as [$label, $value]) {
            $problem = self::nameProblem($value, $email);
            if ($problem !== null) $errors[] = $label . ' ' . $problem;
        }
        $problem = self::emailProblem($email);
        if ($problem !== null) $errors[] = 'Email address ' . $problem;
        return $errors;
    }

    /**
     * What is wrong with this name, or null if nothing is.
     *
     * Pass the submitted email to enable corroboration: an unusual name whose
     * spelling is echoed in the email local part is far more likely to be a real
     * person than a bot. See emailCorroborates() for what that can and cannot rescue.
     */
    public static function nameProblem(string $name, string $email = ''): ?string {
        $name = trim($name);
        if ($name === '') return 'is required.';

        // Letters, spaces and the punctuation that genuinely appears in names
        // (O'Brien, Smith-Jones, St. John). Anything else — digits, @, /, <, : —
        // is a bot filling a field, not a person.
        if (preg_match('/[^\p{L}\p{M}\s\'’\-.]/u', $name)) {
            return 'contains characters that are not part of a name.';
        }

        foreach (preg_split('/[\s\'’\-.]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $lower  = mb_strtolower($word, 'UTF-8');
            $chars  = preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY);
            $len    = count($chars);
            $vowels = 0;
            $run    = 0;
            $maxRun = 0;

            foreach ($chars as $ch) {
                // Combining accents belong to the letter before them and are neither.
                if (preg_match('/\p{M}/u', $ch)) continue;
                if (mb_strpos(self::VOWELS, $ch, 0, 'UTF-8') !== false) {
                    $vowels++;
                    $run = 0;
                } else {
                    $run++;
                    if ($run > $maxRun) $maxRun = $run;
                }
            }

            // The consonant-run rule is the one that guesses hardest about language, so
            // it is the one an independent field is allowed to overrule.
            if ($maxRun >= self::MAX_CONSONANT_RUN && !self::emailCorroborates($word, $email)) {
                return 'has too many consonants in a row to be a real name.';
            }
            if ($vowels === 0 && $len >= self::VOWEL_EXEMPT_LENGTH) {
                return 'does not contain any vowels.';
            }
            if (self::isKeyboardWalk($lower)) {
                return 'looks like keys typed straight off the keyboard.';
            }
        }

        return null;
    }

    /**
     * Does the email local part echo this word closely enough to vouch for it?
     *
     * A real person's address usually carries their name — skrlj@, m.angstrom@,
     * nguyenv@ — while a bot pairing a random name with a random mailbox has no such
     * correlation. So a close match is decent evidence that an odd-looking name is a
     * real one.
     *
     * IT IS ONLY EVER USED TO ACCEPT, NEVER TO REJECT, and that asymmetry is the
     * whole point. Enormous numbers of legitimate people use an address that looks
     * nothing like their name — nicknames, handles, a shared family mailbox, a work
     * alias — so a LOW score means nothing at all and must never cost someone their
     * submission.
     *
     * It also cannot rescue everything, deliberately. Waiving a check on a matching
     * email is exploitable by the obvious trick of submitting "asdfgh" with
     * asdfgh@gmail.com, so only the consonant-run rule consults this. The vowel and
     * keyboard-walk rules stay absolute, and between them they catch every mash in
     * the corpus — including that one.
     */
    private static function emailCorroborates(string $word, string $email): bool {
        $at = mb_strrpos($email, '@', 0, 'UTF-8');
        if ($at === false) return false;

        $local = strtolower(preg_replace('/[^a-z]/i', '', mb_substr($email, 0, $at, 'UTF-8')));
        $word  = strtolower(preg_replace('/[^a-z]/i', '', $word));
        if ($local === '' || $word === '' || mb_strlen($word) < 3) return false;

        if (str_contains($local, $word) || str_contains($word, $local)) return true;

        $distance = levenshtein($word, $local);
        $longest  = max(strlen($word), strlen($local));
        return $longest > 0 && (1 - $distance / $longest) >= self::EMAIL_MATCH_RATIO;
    }

    /**
     * Does this word contain a run of KEYBOARD_WALK_LEN keys that are neighbours on
     * one keyboard row, in either direction? "asdfgh" does; "Ångström" does not.
     *
     * Only plain a-z is considered — an accented letter is not on a QWERTY row, and
     * treating it as a gap is correct rather than a limitation.
     */
    private static function isKeyboardWalk(string $word): bool {
        $ascii = preg_replace('/[^a-z]/', '', $word);
        if (strlen($ascii) < self::KEYBOARD_WALK_LEN) return false;

        $run = 1;
        for ($i = 1, $n = strlen($ascii); $i < $n; $i++) {
            $step = false;
            foreach (self::ROWS as $row) {
                $a = strpos($row, $ascii[$i - 1]);
                $b = strpos($row, $ascii[$i]);
                if ($a !== false && $b !== false && abs($a - $b) === 1) { $step = true; break; }
            }
            $run = $step ? $run + 1 : 1;
            if ($run >= self::KEYBOARD_WALK_LEN) return true;
        }
        return false;
    }

    /**
     * What is wrong with this email address, or null if nothing is.
     */
    public static function emailProblem(string $email): ?string {
        $email = trim($email);
        if ($email === '') return 'is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'is not a valid email address.';

        // Count dots in the LOCAL PART only. Counting them across the whole address
        // would reject first.last@gmail.com (two dots) and every @company.co.uk
        // address on earth (two before the local part is even considered).
        $at    = mb_strrpos($email, '@', 0, 'UTF-8');
        $local = mb_substr($email, 0, $at, 'UTF-8');
        if (mb_substr_count($local, '.') > self::MAX_LOCAL_DOTS) {
            return 'has too many dots before the @ sign.';
        }

        return null;
    }
}
