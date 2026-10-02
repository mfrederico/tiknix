<?php
/**
 * ToolUse — which options of a tool people actually use, so the ones nobody touches can go.
 *
 * One row per use of a tool ("builder.create" = one submit of the Builder's create form), with
 * the CHOICES made: which path, which answers, which optional fields were filled in. Never what
 * was typed — a goal or a file path is the member's, and the prompt log is private to them
 * (app\PromptLog); a choice is a small word or a yes/no, and record() refuses anything else.
 *
 * Kept in CORE's database even when a sidecar records it (app\CoreDb): the question "is anyone
 * using Tags?" spans every project, and a project's workbench.db only knows its own.
 *
 * A use that cannot be recorded is said (ERROR in the log, false returned) and does not stop
 * the action it describes: the member asked for a build, not for a statistic.
 */
namespace app;

class ToolUse {

    /** A choice's value: yes/no, a number, or a short word. Not prose. */
    private static function isChoice($v): bool {
        return is_bool($v) || is_int($v) || (is_string($v) && preg_match('/^[a-z0-9_.:-]{0,40}$/i', $v));
    }

    /**
     * @param array<string,bool|int|string> $choices option => what was chosen
     * @param int $instanceId the project it was used on, 0 when the tool is not about one
     */
    public static function record(string $tool, int $memberId, array $choices, int $instanceId = 0): bool {
        if (!preg_match('/^[a-z][a-z0-9_.]{1,60}$/', $tool)) throw new \InvalidArgumentException("not a tool name: '{$tool}'");
        foreach ($choices as $k => $v) {
            if (!self::isChoice($v)) throw new \InvalidArgumentException("ToolUse records choices, not text: '{$k}' of {$tool} is not a yes/no, a number or a short word");
        }
        ksort($choices);
        $ok = CoreDb::with(function () use ($tool, $memberId, $choices, $instanceId) {
            $row = Bean::dispense('tooluse');
            $row->tool        = $tool;
            $row->memberRef   = $memberId;
            $row->instanceRef = $instanceId;
            $row->choices     = json_encode((object) $choices);
            $row->createdAt   = date('Y-m-d H:i:s');
            Bean::store($row);
            return true;
        }, false);
        if (!$ok) error_log("ERROR ToolUse::record({$tool}): not recorded — " . (CoreDb::lastError() ?: 'core database unreachable'));
        return (bool) $ok;
    }

    /**
     * How a tool was used over the last $days: the number of uses and of people, and for each
     * option how often each value was chosen (most chosen first).
     *
     * A row whose choices do not parse is a fault in the data and stops the count with its id —
     * a summary that skipped it would under-report an option into being deleted.
     *
     * @param array<int,array<string,mixed>> $rows tooluse rows (tool, member_ref, choices)
     * @return array{uses:int,people:int,options:array<string,array<string,int>>}
     */
    public static function tally(array $rows): array {
        $options = [];
        $people = [];
        foreach ($rows as $r) {
            $c = json_decode((string) ($r['choices'] ?? ''), true);
            if (!is_array($c)) throw new \RuntimeException('tooluse row ' . (int) ($r['id'] ?? 0) . ' has choices that are not JSON');
            $people[(int) ($r['member_ref'] ?? 0)] = true;
            foreach ($c as $k => $v) {
                $v = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
                $options[$k][$v] = ($options[$k][$v] ?? 0) + 1;
            }
        }
        ksort($options);
        foreach ($options as &$vals) arsort($vals);
        return ['uses' => count($rows), 'people' => count($people), 'options' => $options];
    }

    /** tally() of one tool's rows in core's database. No table yet means no use yet. */
    public static function summary(string $tool, int $days = 90): array {
        $rows = CoreDb::with(function () use ($tool, $days) {
            if (!in_array('tooluse', Bean::inspect(), true)) return [];
            return Bean::getAll('SELECT id, member_ref, choices FROM tooluse WHERE tool = ? AND created_at >= ?',
                [$tool, date('Y-m-d H:i:s', time() - $days * 86400)]);
        }, null);
        if ($rows === null) throw new \RuntimeException('ToolUse::summary: ' . (CoreDb::lastError() ?: 'core database unreachable'));
        return self::tally($rows);
    }
}
