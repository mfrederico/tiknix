<?php
/**
 * ProjectQuota — how many projects an account is responsible for, and how many it may have.
 *
 * ONE function, called by every gate. Not a copy per call site: the first divergence
 * between five copies of a quota rule is a free tier that leaks, and it leaks silently.
 *
 * The counting rule (decided in BILLING_PLAN.md, D1):
 *
 *   1. Projects you own.
 *   2. Projects shared into a team YOU own — they are your responsibility, capped at
 *      PRO_CAP, so collaborators you invite do not each need their own subscription.
 *   3. Projects shared into a team you merely belong to, WHEN that team's owner is on the
 *      free tier. This is the anti-abuse clause: two free accounts swapping projects both
 *      count both projects, so neither stays free.
 *
 * Rule 3 is deliberately narrow. Counting every shared project against every member who
 * can see it — the first draft of this rule — was measured against the live database and
 * billed people who own nothing: one account owning zero projects counted four, purely
 * from team membership. It also made *accepting an invitation* the thing that costs money,
 * which means anyone could raise a stranger's bill by inviting them to a team.
 *
 * @package app
 */

namespace app;

class ProjectQuota {

    /** Projects included at no charge. */
    public const FREE_CAP = 1;

    /** Projects included in the paid plan. */
    public const PRO_CAP = 10;

    /**
     * Projects counting against this account.
     *
     * Throws rather than returning a number it cannot stand behind. `lib/Invite.php`
     * already learned this one: a quota check that treats a failed query as "0 used" is
     * not a quota, it is an outage that hands out free projects.
     *
     * @throws \RuntimeException if the count cannot be established
     */
    public static function countFor(int $memberId): int {
        if ($memberId <= 0) throw new \RuntimeException('ProjectQuota: refusing to count for member id ' . $memberId);

        $sql = "SELECT COUNT(DISTINCT i.id)
                FROM instance i
                LEFT JOIN instance_team it   ON it.instance_id = i.id
                LEFT JOIN team        t      ON t.id = it.team_id
                LEFT JOIN member      owner  ON owner.id = t.owner_id
                LEFT JOIN teammember  tm     ON tm.team_id = t.id AND tm.member_id = ?
                WHERE (i.status IS NULL OR i.status != 'deleted')
                  AND (    i.member_id = ?
                        OR t.owner_id  = ?
                        OR (tm.member_id = ? AND COALESCE(owner.plan_tier, 'free') = 'free') )";

        try {
            // A member with no tier yet has no plan, which is exactly what 'free' means —
            // this is a defined value for an absent one, not a guess covering an error.
            return (int) Bean::getCell($sql, [$memberId, $memberId, $memberId, $memberId]);
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('ProjectQuota: count failed — refusing to assume zero', [
                'member' => $memberId, 'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Could not establish the project count for member ' . $memberId, 0, $e);
        }
    }

    /** How many projects this account may hold. */
    public static function capFor(int $memberId): int {
        $member = Bean::load('member', $memberId);
        if (!$member->id) throw new \RuntimeException('ProjectQuota: no such member ' . $memberId);

        // A grandfathered account carries its own cap, set when it was migrated.
        $cap = (int) ($member->planProjectCap ?? 0);
        if ($cap > 0) return $cap;

        return self::tierOf($memberId) === 'pro' ? self::PRO_CAP : self::FREE_CAP;
    }

    /** 'free' | 'pro' | 'legacy' — what the account is on right now. */
    public static function tierOf(int $memberId): string {
        $member = Bean::load('member', $memberId);
        if (!$member->id) throw new \RuntimeException('ProjectQuota: no such member ' . $memberId);
        $tier = trim((string) ($member->planTier ?? ''));
        return $tier !== '' ? $tier : 'free';
    }

    /**
     * Is this account over what it may hold? Read-only — nothing in phase 1 acts on it.
     */
    public static function isOverCap(int $memberId): bool {
        return self::countFor($memberId) > self::capFor($memberId);
    }

    /**
     * Does this account need the paid plan, i.e. does it hold more than the free tier
     * allows? This is the single number the billing service is told about — see
     * Billing::usage and conf/rates/tiknix.php on the billing side.
     */
    public static function needsPaidPlan(int $memberId): bool {
        return self::countFor($memberId) > self::FREE_CAP;
    }

    /**
     * Everything a UI or an invoice needs, in one query pass.
     *
     * @return array{count:int, cap:int, tier:string, needs_paid:bool, over:bool}
     */
    public static function snapshot(int $memberId): array {
        $count = self::countFor($memberId);
        $cap   = self::capFor($memberId);
        return [
            'count'      => $count,
            'cap'        => $cap,
            'tier'       => self::tierOf($memberId),
            'needs_paid' => $count > self::FREE_CAP,
            'over'       => $count > $cap,
        ];
    }
}
