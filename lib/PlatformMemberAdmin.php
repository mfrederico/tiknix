<?php
/**
 * PlatformMemberAdmin — the control plane's part of the member admin screens: project
 * quotas, free-project grants, plan tiers, the billing tenant and lifecycle, and the
 * invitation "has built" stamp. Moved out of controls/Admin in RUNTIME-SPLIT-MAP.md step 2;
 * plugged in as Admin::$memberExtension by lib/controlplane.php.
 */

namespace app;

use RedBeanPHP\OODBBean;

class PlatformMemberAdmin implements MemberAdminExtension {

    public function listing(array $members): array {
        // Projects held / allowed per member — the same snapshot the invoice and the create
        // gate use, so this column cannot disagree with them.
        $out = [];
        foreach ($members as $m) {
            // The logged-out visitor's account has no projects and gets no allowance.
            if ((int) $m->id === PUBLIC_USER_ID) continue;
            try { $out[(int) $m->id] = ProjectQuota::snapshot((int) $m->id); }
            catch (\Throwable $e) { $out[(int) $m->id] = ['error' => $e->getMessage()]; }
        }
        return $out;
    }

    public function beforeSave(OODBBean $member, object $data, int $adminId): void {
        if ((int) $member->id === PUBLIC_USER_ID) return;
        // Per-member FREE-project allowance (ProjectQuota::freeCapFor). 0 = the global default.
        // Through the model: the number and its audit row (who, when, why) are written
        // together — a grant is money not collected.
        if (isset($data->free_projects)) {
            $member->box()->setFreeProjects((int) $data->free_projects, $adminId, (string) ($data->free_projects_note ?? ''));
        }
        // Plan tier. 'agency' is the one tier a card cannot imply, so an admin sets it here.
        // SignupFlow::syncPlanTier leaves 'agency' and 'legacy' alone; a removed card still
        // drops to free.
        if (isset($data->plan_tier)) {
            $wantTier = strtolower(trim((string) $data->plan_tier));
            $haveTier = strtolower(trim((string) ($member->planTier ?: 'free')));
            if (in_array($wantTier, ['free', 'pro', 'agency', 'legacy'], true) && $wantTier !== $haveTier) {
                $member->planTier = $wantTier;
                $member->planProjectCap = 0;
                \Flight::get('log')->info('admin changed plan tier', [
                    'member' => (int) $member->id, 'from' => $haveTier, 'to' => $wantTier, 'by' => $adminId,
                ]);
            }
        }
    }

    public function afterSave(OODBBean $member): void {
        // Billing follows the account status — suspend somebody and the nightly run must stop
        // invoicing them. A no-op when nothing needs changing. Non-fatal.
        BillingLifecycle::syncFor((int) $member->id);
    }

    public function prepareEdit(int $memberId): void {
        // ASK, don't read the cache: firstBuildAt is stamped lazily by Invite::hasBuilt(),
        // which scans each project's own workbench.db.
        Invite::hasBuilt($memberId);
    }

    public function editView(OODBBean $member): array {
        if ((int) $member->id === PUBLIC_USER_ID) return ['projectQuota' => null, 'freeGrants' => [], 'panel' => ''];
        return [
            // What they hold, what is free, what is billed — the same snapshot the invoice uses.
            'projectQuota' => ProjectQuota::snapshot((int) $member->id),
            'freeGrants'   => array_map(fn($a) => [
                'row' => $a,
                'by'  => Bean::load('member', (int) $a->byRef)->displayName('member #' . (int) $a->byRef),
            ], $member->box()->audits('free_projects', 10)),
            'panel'        => 'platform/member_billing',
            // Which projects those are, at the top of the page: owned and shared, each linked to its report (the members list's "1 / 1").
            'top'          => 'platform/member_projects',
        ];
    }

    /**
     * The member's projects for the edit screen: owned ones first, then those shared with them through a team, none
     * deleted. 'counts' says whether it is one of the projects their allowance counts (ProjectQuota::countedProjects,
     * the same rule as the members list's "1 / 1" and the invoice); 'last' is when THIS member last asked its Builder for
     * something — the prompt log (PromptLog::lastAskedBy) or the project's own board (lastOnBoard), whichever is later:
     * their own change, not an automatic fix's, a GitHub pull's or a teammate's — '' when never.
     *
     * @return list<array{inst: object, role: string, counts: bool, last: string}>
     */
    public static function projects(int $memberId): array {
        $counted = array_column(ProjectQuota::countedProjects($memberId), 'id');
        $owned = array_flip(MemberProjects::ownedIds($memberId));
        $insts = MemberProjects::accessible($memberId);
        $last = PromptLog::lastAskedBy($memberId, $insts);
        foreach ($insts as $inst) {
            $board = self::lastOnBoard($inst, $memberId);
            if ($board !== '' && $board > ($last[(int) $inst->id] ?? '')) $last[(int) $inst->id] = $board;
        }
        $out = [];
        foreach ($insts as $inst) {
            $out[] = ['inst' => $inst, 'role' => isset($owned[(int) $inst->id]) ? 'owner' : 'shared',
                      'counts' => in_array((int) $inst->id, $counted, true), 'last' => $last[(int) $inst->id] ?? ''];
        }
        return $out;
    }

    /**
     * The last thing this member did on a project's board (its data/workbench.db): an Ask message they sent, or a task
     * made for them that no machine started (an automatic fix is source 'detected_error', a GitHub pull 'github'; a
     * board from before the source column has only people's tasks). '' when nothing, or the project has no board.
     * @throws \RuntimeException when the board is there and cannot be read
     */
    public static function lastOnBoard(object $inst, int $memberId): string {
        $file = $inst->box()->workbenchDb();
        if (!is_file($file)) return '';
        try {
            $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
            $at = [];
            if (in_array('askmessage', $tables, true)) {
                $st = $pdo->prepare('SELECT MAX(created_at) FROM askmessage WHERE member_ref = ?'); $st->execute([$memberId]); $at[] = (string) $st->fetchColumn();
            }
            if (in_array('workbenchtask', $tables, true)) {
                $cols = array_column($pdo->query('PRAGMA table_info(workbenchtask)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
                $st = $pdo->prepare('SELECT MAX(created_at) FROM workbenchtask WHERE member_id = ?' . (in_array('source', $cols, true) ? " AND COALESCE(source, '') = ''" : ''));
                $st->execute([$memberId]); $at[] = (string) $st->fetchColumn();
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException("Could not read the board of project {$inst->slug} ({$file}): " . $e->getMessage(), 0, $e);
        }
        return $at ? max($at) : '';
    }

    public function deleting(int $memberId): void {
        // Stop billing BEFORE the row goes: the tenant slug lives on it. Non-fatal.
        BillingLifecycle::onDelete($memberId);
    }

    public function created(OODBBean $member): void {
        // An admin-created member is a billing subject too. Non-fatal.
        SignupFlow::ensureTenantFor((int) $member->id);
    }

    public function beforeCreate(OODBBean $member, object $data, int $adminId): void {
        // Stamped like every other creation path: a NULL tier reads as "unset", and the
        // grandfather migration would sweep it into legacy.
        $member->planTier = 'free';
    }

    public function addView(): array { return []; }
}
