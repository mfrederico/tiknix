<?php
/**
 * AppAccess — who Tiknix vouches for inside a project's own app (a project in its own container
 * has its own member accounts), and taking it back.
 *
 *   level()       the level a sign-in hand-off (AppToken::launch) asks the app to create a MISSING
 *                 account at: the project's owner 1 (ROOT), a team owner/admin of a team it is
 *                 shared with 50 (ADMIN), a team member 100 (MEMBER). The app never changes the
 *                 level of an account it already has.
 *   revokeLost()  after someone may have lost a project (left or removed from a team, the team
 *                 deleted, the project unshared): for each such project they can no longer use,
 *                 disable the account Tiknix made for them in the app (`clitool --access-revoke`)
 *                 — or "forgot password" there would still let them in. An account the app made
 *                 itself is left alone. Failures are logged as ERROR and returned, never swallowed.
 */
namespace app;

final class AppAccess {
    private const TEAM_LEVEL = ['owner' => 50, 'admin' => 50, 'member' => 100];

    public static function level(int $memberId, object $inst): ?int {
        if ((int) $inst->memberId === $memberId) return 1;
        $roles = Bean::getCol(
            'SELECT tm.role FROM teammember tm JOIN instance_team it ON it.team_id = tm.team_id
              WHERE tm.member_id = ? AND it.instance_id = ?', [$memberId, (int) $inst->id]);
        $levels = array_values(array_filter(array_map(fn($r) => self::TEAM_LEVEL[(string) $r] ?? null, $roles)));
        return $levels ? min($levels) : null;
    }

    /** @return int[] the projects shared with a team */
    public static function teamProjects(int $teamId): array {
        return array_values(array_map('intval', Bean::getCol('SELECT instance_id FROM instance_team WHERE team_id = ?', [$teamId])));
    }

    /** @return int[] the members of a team */
    public static function teamMembers(int $teamId): array {
        return array_values(array_map('intval', Bean::getCol('SELECT member_id FROM teammember WHERE team_id = ?', [$teamId])));
    }

    /**
     * @param int[] $instanceIds projects $memberId may have just lost
     * @return array{ok:bool, steps:string[], errors:string[]}
     */
    public static function revokeLost(int $memberId, array $instanceIds): array {
        $out = ['ok' => true, 'steps' => [], 'errors' => []];
        $member = Bean::load('member', $memberId);
        $email = strtolower(trim((string) $member->email));
        if (!$member->id || $email === '') return $out;
        foreach (array_unique(array_map('intval', $instanceIds)) as $id) {
            $inst = Bean::load('instance', $id);
            if (!$inst->id || !\Model_Instance::tenantRow($inst) || trim((string) $inst->ctIp) === '') continue;
            if ($inst->accessibleBy($memberId)) continue;            // still has it another way
            [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --access-revoke=' . escapeshellarg($email), null, 60);
            if ($c === 0) { $out['steps'][] = "{$inst->slug}: " . trim((string) $o); continue; }
            $msg = "{$inst->slug}: could not disable {$email}'s account: " . trim((string) $o);
            error_log("ERROR AppAccess::revokeLost {$msg}");
            $out['ok'] = false;
            $out['errors'][] = $msg;
        }
        return $out;
    }
}
