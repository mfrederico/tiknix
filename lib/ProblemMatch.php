<?php
/**
 * ProblemMatch — whether two reports describe the same problem, in different words.
 *
 * The check after a build runs one checker per user level at the same time, and each writes what
 * it found in its own words: "/friends shows no 6-character meet code", "No 6-character meet code
 * or code entry on /friends", "/friends has no 6-character meet code and no field to enter one".
 * Filed as they came, inresonance got eleven "problems" for three (2026-10-20), and the firehose
 * showed the same few over and over. Reports are compared by the words that carry meaning
 * (KnowledgeBase::words: folded, stop-words out) — the share of the smaller report's words the two
 * have in common. No model runs for it: same words, same answer, every time.
 */
namespace app;

class ProblemMatch {

    /** How much of the shorter report the other must share to be the same problem. */
    public const SAME = 0.6;

    /** 0..1: the share of the smaller set of meaningful words that both reports contain. */
    public static function overlap(string $a, string $b): float {
        $wa = array_unique(KnowledgeBase::words($a));
        $wb = array_unique(KnowledgeBase::words($b));
        if (!$wa || !$wb) return 0.0;
        return count(array_intersect($wa, $wb)) / min(count($wa), count($wb));
    }

    public static function same(string $a, string $b): bool {
        return self::overlap($a, $b) >= self::SAME;
    }

    /**
     * Failures from several checkers made one list: a failure that repeats one already kept is
     * folded into it — its level added, its screenshots kept — instead of standing as another.
     * @param array<int,array> $failures  each: label, message, level, url, screens, …
     */
    public static function fold(array $failures): array {
        $kept = [];
        foreach ($failures as $f) {
            $text = trim((string) ($f['label'] ?? '') . ' ' . (string) ($f['message'] ?? ''));
            foreach ($kept as $i => $k) {
                if (!self::same((string) ($k['label'] ?? ''), (string) ($f['label'] ?? '')) && !self::same($k['_text'], $text)) continue;
                $levels = array_values(array_unique(array_filter(array_merge(explode(', ', (string) ($k['level'] ?? '')), [(string) ($f['level'] ?? '')]))));
                $kept[$i]['level'] = implode(', ', $levels);
                $kept[$i]['screens'] = array_values(array_unique(array_merge((array) ($k['screens'] ?? []), (array) ($f['screens'] ?? []))));
                if (mb_strlen((string) ($f['message'] ?? '')) > mb_strlen((string) ($k['message'] ?? ''))) $kept[$i]['message'] = (string) $f['message'];
                continue 2;
            }
            $f['_text'] = $text;
            $kept[] = $f;
        }
        return array_map(function ($k) { unset($k['_text']); return $k; }, $kept);
    }
}
