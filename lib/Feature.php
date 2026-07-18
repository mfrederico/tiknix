<?php
/**
 * Feature — per-member feature flags.
 *
 * Flags are stored as `feature.<key>` rows in the existing member-scoped
 * `settings` table (one row per member+flag; value '1' = on). Each flag carries a
 * minimum privilege LEVEL: it is only OFFERED to, and only usable by, members at
 * or above that level (lower number = higher privilege, per LEVELS). So the
 * `ecommerce` flag (min_level 50) is available to ADMIN and ROOT, never to a
 * plain MEMBER — an admin toggles it for an eligible member on the Edit Member
 * page, and the left-nav "Ecommerce" tab appears for members who have it on.
 *
 * isEnabled() re-checks eligibility on every read, so a demotion silently revokes
 * the flag without any cleanup pass.
 */

namespace app;

use app\Bean;

class Feature {

    /** Flag catalog: key => ['label', 'blurb', 'min_level']. */
    public const CATALOG = [
        'ecommerce' => [
            'label'     => 'Ecommerce',
            'blurb'     => 'Product catalog, inventory (including serialized units), and Stripe checkout tools.',
            'min_level' => 50, // ADMIN and above (ROOT)
        ],
    ];

    private static function settingKey(string $flag): string {
        return 'feature.' . $flag;
    }

    public static function exists(string $flag): bool {
        return isset(self::CATALOG[$flag]);
    }

    /** A member at $level is eligible for $flag when their level is at least its min_level. */
    public static function eligible(string $flag, int $level): bool {
        return self::exists($flag) && $level <= (int) self::CATALOG[$flag]['min_level'];
    }

    /** Catalog entries a member at $level may be offered (used to render toggles). */
    public static function catalogForLevel(int $level): array {
        $out = [];
        foreach (self::CATALOG as $key => $meta) {
            if ($level <= (int) $meta['min_level']) $out[$key] = $meta;
        }
        return $out;
    }

    /**
     * Is $flag enabled for a member? Requires BOTH the stored '1' AND that the
     * member is still eligible for their level, so a demotion revokes access.
     *
     * @param int|null $memberId defaults to the current member
     * @param int|null $level    the member's level (avoids a reload when known)
     */
    public static function isEnabled(string $flag, $memberId = null, ?int $level = null): bool {
        if (!self::exists($flag)) return false;
        if ($memberId === null) {
            $m = \Flight::getMember();
            $memberId = (int) ($m->id ?? 0);
            if ($level === null) $level = (int) ($m->level ?? 101);
        }
        $memberId = (int) $memberId;
        if ($memberId <= 0) return false;
        if ($level !== null && !self::eligible($flag, (int) $level)) return false;
        return self::stored($memberId, $flag) === '1';
    }

    /** Turn a flag on or off for a member. No-op for unknown flags. */
    public static function setEnabled(string $flag, bool $on, int $memberId): void {
        if (!self::exists($flag) || $memberId <= 0) return;
        $row = Bean::findOne('settings', 'member_id = ? AND setting_key = ?',
            [$memberId, self::settingKey($flag)]);
        $now = date('Y-m-d H:i:s');
        if ($on) {
            if (!$row || !$row->id) {
                $row = Bean::dispense('settings');
                $row->memberId   = $memberId;
                $row->settingKey = self::settingKey($flag);
                $row->createdAt  = $now;
            }
            $row->settingValue = '1';
            $row->updatedAt    = $now;
            Bean::store($row);
        } elseif ($row && $row->id) {
            Bean::trash($row);
        }
        unset($_SESSION['member_features'][$memberId]); // bust the per-request cache
    }

    /** Stored value for member+flag, cached per member for the request/session. */
    private static function stored(int $memberId, string $flag): ?string {
        if (!isset($_SESSION['member_features'][$memberId])) {
            $cache = [];
            foreach (Bean::find('settings',
                "member_id = ? AND setting_key LIKE 'feature.%'", [$memberId]) as $r) {
                $cache[(string) $r->settingKey] = (string) $r->settingValue;
            }
            $_SESSION['member_features'][$memberId] = $cache;
        }
        return $_SESSION['member_features'][$memberId][self::settingKey($flag)] ?? null;
    }
}
