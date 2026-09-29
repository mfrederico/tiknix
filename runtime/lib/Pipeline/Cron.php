<?php
/**
 * Pipeline\Cron — a tiny 5-field cron matcher (min hour dom mon dow) for the
 * fake-cron tick. Supports `*`, lists `a,b`, ranges `a-b`, and steps `*​/n` / `a-b/n`.
 * "Does this expression fire at $when?" — the tick runs each minute and asks this.
 */

namespace app\Pipeline;

class Cron {

    /** True if the 5-field cron expression fires at the given timestamp (default now). */
    public static function due(string $expr, ?int $when = null): bool {
        $when = $when ?? time();
        $f = preg_split('/\s+/', trim($expr));
        if (count($f) !== 5) return false;
        [$min, $hour, $dom, $mon, $dow] = $f;
        $t = getdate($when);
        return self::field($min, (int) $t['minutes'], 0, 59)
            && self::field($hour, (int) $t['hours'], 0, 23)
            && self::field($dom, (int) $t['mday'], 1, 31)
            && self::field($mon, (int) $t['mon'], 1, 12)
            && self::field($dow, (int) $t['wday'], 0, 6);   // 0 = Sunday
    }

    /** Does one field match the given value? */
    private static function field(string $spec, int $val, int $lo, int $hi): bool {
        foreach (explode(',', $spec) as $part) {
            $step = 1;
            if (strpos($part, '/') !== false) { [$part, $s] = explode('/', $part, 2); $step = max(1, (int) $s); }
            if ($part === '*' || $part === '') { $rlo = $lo; $rhi = $hi; }
            elseif (strpos($part, '-') !== false) { [$a, $b] = explode('-', $part, 2); $rlo = (int) $a; $rhi = (int) $b; }
            else { if ((int) $part === $val) return true; continue; }
            if ($val < $rlo || $val > $rhi) continue;
            if (($val - $rlo) % $step === 0) return true;
        }
        return false;
    }
}
