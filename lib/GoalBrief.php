<?php
/**
 * GoalBrief — the Builder's create form asks two things besides the goal itself: WHO IT IS FOR
 * and HOW WE WILL KNOW IT WORKED. This turns the answers into part of the goal text, and reads
 * them back out of one.
 *
 * Why text, and not fields of their own: the goal is the one thing every path already carries.
 * It is what the planner reads, what a single task's agent is handed, what the prompt log
 * keeps, what a queued retry, "continue to the next phase" and a re-plan after a failed audit
 * start from. Answers stored beside the goal reached none of those (the form's access level
 * and acceptance criteria were ignored by "Decompose" for exactly that reason). Written into
 * the goal they reach all of them, and a person reading the plan sees what was asked.
 *
 * split() is compose()'s inverse, so "Reuse" on an earlier goal puts the answers back in the
 * form instead of leaving them glued to the description to be appended a second time.
 */
namespace app;

class GoalBrief {

    /**
     * Who a build is for → the level its new pages are seeded at. 'none' is a real answer
     * ("this adds no pages"), not a missing one: nothing is written about access.
     */
    public const AUDIENCES = [
        'anyone'  => ['level' => 101, 'name' => 'PUBLIC', 'says' => 'Anyone visiting the site, signed in or not.'],
        'members' => ['level' => 100, 'name' => 'MEMBER', 'says' => 'Signed-in members only.'],
        'admins'  => ['level' => 50,  'name' => 'ADMIN',  'says' => 'Admins only.'],
        'none'    => ['level' => null, 'name' => '', 'says' => ''],
    ];

    private const WHO  = '## Who it is for';
    private const DONE = '## How we will know it worked';

    public static function isAudience(string $key): bool { return isset(self::AUDIENCES[$key]); }

    /** The permission level for an audience (null for 'none'). An unknown key is a caller's bug. */
    public static function level(string $audience): ?int {
        if (!self::isAudience($audience)) throw new \InvalidArgumentException("not an audience: '{$audience}' — it is one of " . implode(', ', array_keys(self::AUDIENCES)));
        return self::AUDIENCES[$audience]['level'];
    }

    /** The goal with the answers written under it. */
    public static function compose(string $goal, string $audience, string $acceptance = ''): string {
        $level = self::level($audience);
        $out = trim($goal);
        if ($level !== null) {
            $name = self::AUDIENCES[$audience]['name'];
            $out .= "\n\n" . self::WHO . "\n" . self::AUDIENCES[$audience]['says']
                 . " New pages and endpoints are seeded at {$name} (authcontrol level {$level}),"
                 . ' unless the goal above names a different audience for a particular page.';
        }
        $acceptance = trim($acceptance);
        if ($acceptance !== '') $out .= "\n\n" . self::DONE . "\n" . $acceptance;
        return $out;
    }

    /**
     * A composed goal taken apart again. Text that never went through compose() comes back
     * whole as the goal, with no audience ('') — the form then asks, as it does for a new one.
     *
     * @return array{goal:string,audience:string,acceptance:string}
     */
    public static function split(string $body): array {
        $goal = trim($body);
        $acceptance = '';
        $audience = '';
        // Only a section at the END is ours: a goal document may well have headings of its own.
        $d = strrpos($goal, "\n\n" . self::DONE . "\n");
        if ($d !== false) {
            $acceptance = trim(substr($goal, $d + strlen("\n\n" . self::DONE . "\n")));
            $goal = rtrim(substr($goal, 0, $d));
        }
        $w = strrpos($goal, "\n\n" . self::WHO . "\n");
        if ($w !== false) {
            $said = trim(substr($goal, $w + strlen("\n\n" . self::WHO . "\n")));
            foreach (self::AUDIENCES as $key => $a) {
                if ($a['says'] !== '' && str_starts_with($said, $a['says']) && !str_contains($said, "\n")) { $audience = $key; break; }
            }
            if ($audience !== '') $goal = rtrim(substr($goal, 0, $w));
        }
        if ($audience === '' && $acceptance !== '') $audience = 'none';
        return ['goal' => $goal, 'audience' => $audience, 'acceptance' => $acceptance];
    }
}
