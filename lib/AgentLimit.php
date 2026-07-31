<?php
/**
 * AgentLimit — "the agent account is rate-limited right now, until <time>".
 *
 * A session limit is not one task's problem. While it holds, EVERY decompose, build and
 * terminal session for that member fails the same way, each leaving its own small error
 * somewhere different — so the person sees five unrelated failures instead of one cause.
 * It is also the one failure a retry cannot fix: retrying before the reset simply burns
 * the retry budget.
 *
 * So it is recorded once, per member, with its reset time, and shown as a banner on every
 * build surface until it expires. Stored in core's member-scoped `settings` table (the
 * same place Feature keeps its flags) rather than a new table — it is one string per
 * member with a natural expiry.
 */

namespace app;

use RedBeanPHP\R;

class AgentLimit {

    private const KEY = 'agent.limit_until';
    private const MSG = 'agent.limit_message';

    /**
     * Does this engine output mean "rate limited", and until when?
     *
     * Matched on the engine's own wording, deliberately narrowly: a false positive would
     * put a scary banner in front of someone whose agent is working fine. Returns null
     * when the text is any other kind of failure.
     */
    public static function parse(string $text): ?array {
        if (!preg_match('/(session limit|usage limit|rate limit(?:ed)?|too many requests)/i', $text)) return null;

        // "resets 3pm (America/New_York)" / "resets at 15:00" — the engine states a local
        // wall-clock time. Interpret it in the stated zone when given, else the server's.
        $until = 0;
        if (preg_match('/resets?\s+(?:at\s+)?([0-9]{1,2}(?::[0-9]{2})?\s*(?:am|pm)?)\s*(?:\(([^)]+)\))?/i', $text, $m)) {
            $tz = null;
            try { if (!empty($m[2])) $tz = new \DateTimeZone(trim($m[2])); } catch (\Throwable $e) { $tz = null; }
            try {
                $d = new \DateTime(trim($m[1]), $tz ?: null);
                // A reset that already passed today means tomorrow.
                if ($d->getTimestamp() < time()) $d->modify('+1 day');
                $until = $d->getTimestamp();
            } catch (\Throwable $e) { $until = 0; }
        }
        // No parseable time: assume an hour, so the banner clears itself rather than
        // sticking around forever on a guess.
        if ($until <= 0) $until = time() + 3600;

        return ['until' => $until, 'message' => trim(mb_substr($text, 0, 240))];
    }

    /** Record a limit for a member. Returns the reset timestamp, or 0 if not a limit. */
    public static function note(int $memberId, string $text): int {
        $hit = self::parse($text);
        if ($memberId <= 0 || !$hit) return 0;
        CoreDb::with(function () use ($memberId, $hit) {
            self::put($memberId, self::KEY, (string) $hit['until']);
            self::put($memberId, self::MSG, $hit['message']);
            return null;
        });
        return $hit['until'];
    }

    /** Active limit for a member, or null. Expired entries answer null and clear. */
    public static function active(int $memberId): ?array {
        if ($memberId <= 0) return null;
        return CoreDb::with(function () use ($memberId) {
            $until = (int) self::get($memberId, self::KEY);
            if ($until <= 0) return null;
            if ($until <= time()) { self::clear($memberId); return null; }
            // NO reformatted reset time. The engine said "resets 3pm (America/New_York)";
            // rendering that timestamp in the server's zone would show "7:00pm" and leave
            // someone comparing it against a clock that says something else. Its words are
            // already correct and already carry the zone — the parsed timestamp is used
            // only to decide when to stop showing the banner.
            return [
                'until'   => $until,
                'minutes' => (int) max(1, ceil(($until - time()) / 60)),
                'message' => (string) self::get($memberId, self::MSG),
            ];
        }, null);
    }

    public static function clear(int $memberId): void {
        if ($memberId <= 0) return;
        CoreDb::with(function () use ($memberId) {
            foreach ([self::KEY, self::MSG] as $k) {
                $row = R::findOne('settings', 'member_id = ? AND setting_key = ?', [$memberId, $k]);
                if ($row && $row->id) R::trash($row);
            }
            return null;
        });
    }

    private static function put(int $memberId, string $key, string $val): void {
        $row = R::findOne('settings', 'member_id = ? AND setting_key = ?', [$memberId, $key]);
        if (!$row || !$row->id) {
            $row = R::dispense('settings');
            $row->memberId   = $memberId;
            $row->settingKey = $key;
            $row->createdAt  = date('Y-m-d H:i:s');
        }
        $row->settingValue = $val;
        $row->updatedAt    = date('Y-m-d H:i:s');
        R::store($row);
    }

    private static function get(int $memberId, string $key): string {
        $row = R::findOne('settings', 'member_id = ? AND setting_key = ?', [$memberId, $key]);
        return $row && $row->id ? (string) $row->settingValue : '';
    }
}
