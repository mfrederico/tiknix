<?php
/**
 * TaskRace — Plan and Fan, step five: a Critical task is built twice, at once, and one is kept whole.
 *
 * Owner's decisions (2026-10-14): ON for Critical tasks (risk 5, effort 3 or more), within the
 * request's spending limit; BOTH attempts finish, then the winner is judged — never merged together
 * (two answers to the same question touch the same files and tables; a merge of them is new code
 * nobody checked). What the loser is still good for: its tests run against the winner, and a test
 * the winner fails becomes one fix task.
 *
 *   the first attempt   the task as planned (its row; what the plan's other tasks wait on)
 *   the second attempt  a row of its own (race_of = the first), told to take a different approach
 *
 * An attempt that finishes waits for its rival ('built'), its work kept on its branch. When both
 * have ended: one built → it is merged; both built → the judge (the app's agent, read-only) picks;
 * neither → the task failed as any task does. Whichever wins, the FIRST attempt's row is the one
 * that reads merged (what waits on the task waits on that row); the second's reads resolved.
 *
 * Stored on the first attempt as race_json: {rival, state: racing|judging|decided, run, winner, why, tests}.
 */

namespace app;

class TaskRace {
    public const MIN_RISK = 5;
    public const MIN_EFFORT = 3;
    public const DIFF_CHARS = 30000;
    public const TIMEOUT = 300;

    /**
     * Does this task race? Critical and at least medium effort, not itself a second attempt or a
     * review's fix, and — when its agent has a spending limit — the request has room for a second
     * attempt on top of the first (what tasks of its effort cost here; app\PlanLearn).
     * Returns '' to race, or why not (said on the task).
     */
    public static function why(array $rank, bool $isRival, bool $isFix, bool $on, int $budget, int $spent, ?int $estimate): string {
        if (!$on) return 'off';
        if ($isRival || $isFix) return 'not a first attempt';
        if ((int) ($rank['risk'] ?? 0) < self::MIN_RISK || (int) ($rank['effort'] ?? 0) < self::MIN_EFFORT) return 'not Critical with effort 3 or more';
        if ($budget > 0) {
            if ($estimate === null) return 'its spending limit is set and there is no record yet of what such a task costs here';
            if ($spent + 2 * $estimate > $budget) return 'two attempts (about ' . RunStats::tokens(2 * $estimate) . ' tokens) would not fit in what is left of its spending limit';
        }
        return '';
    }

    /** Whether it is on in this install: aibuilder.ini [plan] race_critical (on unless set false). */
    public static function enabled(array $ini): bool {
        return filter_var($ini['plan']['race_critical'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /** What the second attempt is told, after its task's own brief. */
    public static function rivalNote(): string {
        return "\n\n## You are the SECOND attempt at this task\n\nAnother agent is building this same task at the same time, in its own copy of the app. "
             . "Only one of the two is kept — the one whose checks pass, and if both pass, the one a reviewer judges better. Where there is a real choice "
             . "of approach (how the data is shaped, where the logic lives), take a different one from the most obvious; where there is not, simply do it well. "
             . "Write tests for what the task promises: they are run against the other attempt too, if it is the one kept.\n";
    }

    /** The judge's brief: the task, its checks, and both attempts' changes. */
    public static function judgeBrief(string $title, string $description, array $checks, array $a, array $b): string {
        $cut = fn(string $d) => mb_strlen($d) > self::DIFF_CHARS ? mb_substr($d, 0, self::DIFF_CHARS) . "\n… (cut here; the rest is on its branch)" : $d;
        $checks = array_values(array_filter(array_map('trim', array_map('strval', $checks))));
        $checkMd = $checks ? implode("\n", array_map(fn($c) => '- ' . $c, $checks)) : '- (none named)';
        return <<<MD
Two agents built the same task, each in its own copy of this app. Exactly one is kept, whole — they are
never combined. You choose which. You change nothing; read the two changes below, and the app's files as
they are now (Read, Grep) where you need to.

## The task
{$title}

{$description}

## What it promised
{$checkMd}

## Attempt A
```
{$a[0]}
```
```diff
{$cut($a[1])}
```

## Attempt B
```
{$b[0]}
```
```diff
{$cut($b[1])}
```

## How to choose
First, which one does what the task promised, correctly — wrong arithmetic, lost data, a missing
permission row or seed, a page that breaks, outweigh everything else. Then which one fits this app (reuses
what is there, in its idiom) and will be easier for the next task to build on. Not length, not style.

## Answer
Your FIRST line is exactly `PICK: A` or `PICK: B`. Then one or two sentences on why. Nothing else.
MD;
    }

    /** The judge's answer: ['pick' => 'A'|'B', 'why' => …]. No verdict line is a fault, not a coin toss. */
    public static function parseJudge(string $text): array {
        if (!preg_match('/^\s*PICK:\s*([AB])\b/mi', $text, $m)) throw new \UnexpectedValueException('the judge did not answer PICK: A or PICK: B: ' . mb_substr(trim($text), 0, 200));
        $why = trim(substr($text, strpos($text, $m[0]) + strlen($m[0])));
        return ['pick' => strtoupper($m[1]), 'why' => mb_substr((string) preg_replace('/\s+/', ' ', $why), 0, 400)];
    }

    /**
     * Who wins without a judge: the attempts' outcomes ('built' = finished with work and waiting;
     * anything else = it did not produce work to keep). Returns 'A', 'B', 'judge' or 'none'.
     */
    public static function outcome(bool $aBuilt, bool $bBuilt): string {
        if ($aBuilt && $bBuilt) return 'judge';
        if ($aBuilt) return 'A';
        if ($bBuilt) return 'B';
        return 'none';
    }

    /**
     * Did the other attempt's tests fail only on names THAT attempt made (a method, class, function or
     * table the kept one does not have)? Then they test its design, not a promise the kept one broke —
     * no fix is owed. A failed assertion, or any other error, is a real failure. PHPUnit's own output.
     */
    public static function onlyItsOwnNames(string $output): bool {
        if (preg_match('/^Failures?:\s*[1-9]|There (?:was|were) \d+ failures?/mi', $output)) return false;   // an assertion did not hold
        if (!preg_match_all('/^\d+\) .+\n(.+)$/m', $output, $m)) return false;
        foreach ($m[1] as $err) {
            if (!preg_match('/Call to undefined (?:method|function)|Class "[^"]+" not found|Interface "[^"]+" not found|no such (?:table|column)|Undefined constant/i', $err)) return false;
        }
        return true;
    }

    /** The fix task for the losing attempt's tests that the kept one fails: [title, description, verify]. */
    public static function testsFix(int $taskId, string $title, string $failures): array {
        return ['title' => 'Pass the other attempt\'s tests for #' . $taskId,
                'description' => "Task #{$taskId} (\"{$title}\") was built twice and one attempt was kept. The other attempt's tests were run against the kept one, and these failed:\n\n```\n"
                    . mb_substr(trim($failures), 0, 4000) . "\n```\n\nThe tests are in `.aibuilder/rival-tests/` in the app. Where a failure is a real gap in what the task promised, fix the code. "
                    . "Where a test only exercises the other attempt's own names (a class or table it made that this one does not have), it is not a gap: say so in your Handoff and leave it.",
                'verify' => ['The other attempt\'s tests that test what the task promised pass against the kept one']];
    }
}
