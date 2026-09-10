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
     * Does this account need the paid plan? This is the single number the billing service
     * is told about — see Billing::usage and conf/rates/tiknix.php on the billing side.
     *
     * A `legacy` account is FALSE regardless of how many projects it holds. Grandfathered
     * means covered, not billed-then-refunded.
     *
     * That clause is not a nicety. Without it a grandfathered account reported pro_plan=1,
     * and the plan's answer — a matching 100% discount on the tenant — is a row somebody
     * has to remember to add. The first grandfathered account to get a tenant would have
     * been invoiced $499 for projects it was explicitly promised it could keep, and the
     * invoice would have looked entirely correct on the way out. The saved discount stays
     * worth having as a second layer; it is not worth depending on.
     *
     * The "$499 − $499" line on /billing is computed locally from the tier, so reporting
     * zero here costs the customer nothing in visibility.
     */
    public static function needsPaidPlan(int $memberId): bool {
        if (self::tierOf($memberId) === 'legacy') return false;
        return self::countFor($memberId) > self::FREE_CAP;
    }

    /**
     * Is cap enforcement switched on? Default OFF, so an install that says nothing keeps
     * behaving exactly as it did before phase 4.
     */
    public static function enforcementEnabled(): bool {
        $v = \Flight::get('billing.enforce_project_cap');
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    /**
     * Does this instance already count against this member? Used to work out whether an
     * action ADDS to somebody's total or merely re-states what they already carry.
     */
    public static function countsFor(int $memberId, int $instanceId): bool {
        $sql = "SELECT COUNT(*)
                FROM instance i
                LEFT JOIN instance_team it   ON it.instance_id = i.id
                LEFT JOIN team        t      ON t.id = it.team_id
                LEFT JOIN member      owner  ON owner.id = t.owner_id
                LEFT JOIN teammember  tm     ON tm.team_id = t.id AND tm.member_id = ?
                WHERE i.id = ?
                  AND (i.status IS NULL OR i.status != 'deleted')
                  AND (    i.member_id = ?
                        OR t.owner_id  = ?
                        OR (tm.member_id = ? AND COALESCE(owner.plan_tier, 'free') = 'free') )";
        return (int) Bean::getCell($sql, [$memberId, $instanceId, $memberId, $memberId, $memberId]) > 0;
    }

    /**
     * The one gate. Returns null when the action may proceed, or a refusal in the shape
     * ProvisionService already returns.
     *
     * `$adding` is how many projects this action would ADD to that member's total — 0 when
     * it only re-states something they already carry, which is why sharing a project into
     * your own team is free rather than counted twice.
     *
     * Three things this deliberately does NOT do:
     *
     *  - It does not pass when the count fails. countFor throws, and that propagates. A
     *    quota that treats an outage as "you're fine" is not a quota, and lib/Invite.php
     *    already learned that lesson the expensive way.
     *  - It does not refuse silently. The message names the number, the cap, and what to
     *    do, because a person hitting this is trying to give us money.
     *  - It does not enforce anything while the flag is off.
     */
    public static function refusalFor(int $memberId, int $adding = 1, string $what = 'project'): ?array {
        if (!self::enforcementEnabled()) return null;

        $count = self::countFor($memberId);
        $cap   = self::capFor($memberId);
        if ($count + $adding <= $cap) return null;

        $tier = self::tierOf($memberId);
        $msg  = $tier === 'free'
            ? "Your free plan covers {$cap} project" . ($cap === 1 ? '' : 's') . " and you have {$count}. "
              . "Adding another needs the paid plan — see /billing."
            : "Your plan covers {$cap} projects and you have {$count}. See /billing to add more.";

        return ['ok' => false, 'error' => $msg, 'code' => 402];   // 402 Payment Required
    }

    /**
     * Would sharing this instance into this team push somebody over their cap?
     *
     * Checked on the RECEIVING side, because sharing is the one action whose cost lands on
     * a person who is not the one performing it. Under the agreed rule the project counts
     * against the TEAM'S OWNER, so that is who is asked; and when the owner is on the free
     * tier the anti-abuse clause spreads it to every member of the team, so they are asked
     * too.
     */
    public static function refusalForShare(int $instanceId, int $teamId): ?array {
        if (!self::enforcementEnabled()) return null;

        $team = Bean::load('team', $teamId);
        if (!$team->id) return null;                    // share() reports the missing team itself

        $affected = [(int) $team->ownerId];
        if (self::tierOf((int) $team->ownerId) === 'free') {
            $affected = array_values(array_unique(array_merge($affected, array_map(
                fn($tm) => (int) $tm->memberId,
                Bean::find('teammember', 'team_id = ?', [$teamId])
            ))));
        }

        foreach ($affected as $memberId) {
            if ($memberId <= 0) continue;
            if (self::countsFor($memberId, $instanceId)) continue;   // already theirs, adds nothing
            $refusal = self::refusalFor($memberId, 1);
            if ($refusal === null) continue;

            $who = $memberId === (int) $team->ownerId ? "the owner of " . ($team->name ?: 'that team') : 'a member of that team';
            return ['ok' => false, 'code' => 402,
                    'error' => "Sharing this project would put {$who} over their plan. "
                             . 'Ask them to upgrade at /billing, or share it with a different team.'];
        }
        return null;
    }

    /**
     * Would joining this team push the joiner over their cap?
     *
     * Only bites when the team's owner is on the free tier — that is the only case where
     * somebody else's shared projects start counting against you. Checked at the moment of
     * ACCEPTING, never at the moment of inviting: an invitation must not be able to change
     * a stranger's bill, and finding out at the door is better than being billed for a
     * room you did not know you had entered.
     */
    public static function refusalForJoin(int $memberId, int $teamId): ?array {
        if (!self::enforcementEnabled()) return null;

        $team = Bean::load('team', $teamId);
        if (!$team->id) return null;
        if (self::tierOf((int) $team->ownerId) !== 'free') return null;   // owner absorbs them

        $adding = 0;
        foreach (Bean::getCol('SELECT instance_id FROM instance_team WHERE team_id = ?', [$teamId]) as $instanceId) {
            if (!self::countsFor($memberId, (int) $instanceId)) $adding++;
        }
        if ($adding === 0) return null;

        $refusal = self::refusalFor($memberId, $adding);
        if ($refusal === null) return null;

        return ['ok' => false, 'code' => 402,
                'error' => "Joining this team would add {$adding} project" . ($adding === 1 ? '' : 's')
                         . ' to your plan and put you over your limit. See /billing.'];
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
            // Calls the same function the billing service is answered with, rather than
            // repeating the comparison. The inline copy that used to live here is exactly
            // how a page and an invoice come to disagree about what somebody owes.
            'needs_paid' => self::needsPaidPlan($memberId),
            'over'       => $count > $cap,
        ];
    }
}
