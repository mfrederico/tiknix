<?php
/**
 * TaskReview — Plan and Fan, step three: a second reading of a risky task's work.
 *
 * When a task the plan scored Medium risk or above (PlanRank, risk >= 3) merges, the app's own
 * agent reads what it changed — read-only, in the app as it now runs — against the checks the
 * task promised, while the plan's next tasks build. What it finds becomes one fix task in the
 * same plan, which what waited on the reviewed task now waits on too. One round: a fix is not
 * reviewed again (that is the browser check's job, after the plan).
 *
 * Stored on the task as review_json: {state, run, started, finished, problems[], fix, error}.
 *   state  running | passed | found | failed
 */

namespace app;

class TaskReview {
    /** The lowest risk that is read a second time (PlanRank::LABELS[3] = Medium). */
    public const MIN_RISK = 3;
    /** How much of the change the reviewer is handed; the rest it reads from the files. */
    public const DIFF_CHARS = 40000;
    public const TIMEOUT = 300;
    private const VERDICT_RE = '/^\s*REVIEW:\s*(PASS|PROBLEMS)\b/mi';

    /** Is this merged task one to review? Scored at MIN_RISK or above, and not itself a review's fix. */
    public static function wanted(array $rank, bool $isFix): bool {
        return !$isFix && (int) ($rank['risk'] ?? 0) >= self::MIN_RISK;
    }

    /** The container run id of a task's review (TenantRun: lowercase, digits, hyphens). */
    public static function runId(int $planId, int $taskId): string {
        return 'review-p' . $planId . '-t' . $taskId;
    }

    /** Whether it is on in this install: aibuilder.ini [plan] review_risky (on unless set false). */
    public static function enabled(array $ini): bool {
        return filter_var($ini['plan']['review_risky'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * What the reviewer is asked. $checks are the task's own (its `verify`); $diff is the merge's
     * change (git diff HEAD^1 of the merge commit), cut at DIFF_CHARS with the cut said.
     */
    public static function brief(string $title, string $description, array $checks, array $rank, string $stat, string $diff): string {
        $checks = array_values(array_filter(array_map('trim', array_map('strval', $checks))));
        $checkMd = $checks ? implode("\n", array_map(fn($c) => '- ' . $c, $checks)) : '- (the task named no checks: judge it by what it was asked to do)';
        $why = array_values(array_filter(array_map('strval', (array) ($rank['why'] ?? []))));
        $risk = PlanRank::LABELS[(int) ($rank['risk'] ?? 0)] ?? '?';
        $cut = '';
        if (mb_strlen($diff) > self::DIFF_CHARS) {
            $diff = mb_substr($diff, 0, self::DIFF_CHARS);
            $cut = "\n\n(The change is longer than this; the rest is in the files themselves — read them.)";
        }
        $whyMd = $why ? "\nIt was scored {$risk} risk because: " . implode('; ', $why) . '.' : "\nIt was scored {$risk} risk.";
        return <<<MD
You are reviewing ONE task's work in this app, which has just been merged and is live. You are a
second reader: you change nothing. Read the change below and, where you need more, the files as
they are now (Read, Grep). Other tasks may have merged since; judge only this one's change.

## The task
{$title}

{$description}
{$whyMd}

## What it promised (its checks)
{$checkMd}

## What it changed
```
{$stat}
```

```diff
{$diff}
```{$cut}

## What to look for
A real defect only — something that makes one of its checks false, loses or corrupts data, lets the
wrong person see or change something, or breaks a page that worked: wrong arithmetic, an update that
can run twice, a missing permission row or seed, a route that is never reached, a form whose value is
never saved. Not style, not naming, not what you would have done differently. If you are not sure it
is wrong, it is not a problem.

## Answer
Your FIRST line is exactly `REVIEW: PASS` or `REVIEW: PROBLEMS`. After PROBLEMS, at most five lines,
each `- path/to/file.php:LINE — what is wrong, and which check it breaks`, one sentence each. Nothing else.
MD;
    }

    /**
     * The reviewer's answer read: ['verdict' => 'pass'|'problems', 'problems' => string[]].
     * An answer without the verdict line is not a pass — it throws, and the review is recorded as failed.
     */
    public static function parse(string $text): array {
        if (!preg_match(self::VERDICT_RE, $text, $m)) throw new \UnexpectedValueException('the reviewer did not answer REVIEW: PASS or REVIEW: PROBLEMS: ' . mb_substr(trim($text), 0, 200));
        if (strtoupper($m[1]) === 'PASS') return ['verdict' => 'pass', 'problems' => []];
        $after = substr($text, strpos($text, $m[0]) + strlen($m[0]));
        $problems = [];
        foreach (preg_split('/\R/', $after) as $line) {
            if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $p)) $problems[] = trim($p[1]);
            if (count($problems) >= 5) break;
        }
        if (!$problems) throw new \UnexpectedValueException('the reviewer answered REVIEW: PROBLEMS and named none');
        return ['verdict' => 'problems', 'problems' => $problems];
    }

    /** The fix task the problems become: [title, description, verify]. */
    public static function fixTask(int $taskId, string $title, array $problems, array $checks): array {
        $list = implode("\n", array_map(fn($p) => '- ' . $p, $problems));
        $desc = "A second reading of task #{$taskId} (\"{$title}\"), after it merged, found this wrong:\n\n{$list}\n\n"
              . "Fix exactly these, in the code as it is now. If one of them is not actually wrong, say so in your Handoff with the reason and leave it.";
        return ['title' => 'Fix what the review of #' . $taskId . ' found', 'description' => $desc,
                'verify' => array_values(array_merge(array_map(fn($p) => 'Fixed: ' . $p, $problems), array_map('strval', $checks)))];
    }
}
