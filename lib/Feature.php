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
        // NOTE: the in-core 'ecommerce' flag was removed — the store is now the
        // shop.tiknix sidecar, gated by the 'shop' flag below (per-instance storefront
        // + admin, checkout via each instance's own Stripe via controls/Storebroker).
        'explorer' => [
            'label'     => 'Architecture Explorer',
            'blurb'     => 'Visual data-model + call-graph explorer for your instances (heavy; runs as a sidecar). Members can reach it; each grant is per-member.',
            'min_level' => 100, // MEMBER and above — they own the instances it explores
        ],
        'shop' => [
            'label'     => 'Store',
            'blurb'     => 'A per-instance storefront + admin, with checkout via that instance\'s own Stripe. Runs as the shop.tiknix sidecar.',
            'min_level' => 100, // MEMBER and above — they own the instances that get a store
        ],
        // MCP is NOT a sidecar — it is core's own tooling (API keys, the server registry,
        // the tool editor). It gets a flag for the same reason the plugins do: on the
        // control plane these genuinely need to be available, but "available to whoever
        // asks" and "available to whoever an admin grants it to" are different policies,
        // and only the second is a decision anyone actually made.
        //
        // Level alone could not express this. At 50 the tooling was admin-or-nothing, so
        // letting one member use it meant making them an administrator of everything. The
        // flag decouples the two: eligible from MEMBER up, but off until switched on.
        'mcp' => [
            'label'     => 'MCP Access',
            'blurb'     => 'Issue API keys, and open Agent Setup to manage MCP servers. An API key authenticates '
                         . 'tools/call against this instance, so this is programmatic access to its tools — grant it '
                         . 'deliberately, per person. Editing tool or hook CODE still requires ROOT and is not part of this grant.',
            'min_level' => 100, // MEMBER and above are ELIGIBLE; an admin still has to switch it on
        ],
        // Labels here MUST match the nav labels in conf/config.ini's [sidecar.*]
        // sections: this catalog names the same plugins on the feature-toggle pages,
        // and a toggle called "Publisher" governing a nav item called "Deploy" reads
        // as two different things.
        'pipelines' => [
            'label'     => 'Data',
            'blurb'     => 'Build, edit, run + schedule deterministic pipelines in your instances. Runs as the pipelines.tiknix sidecar.',
            'min_level' => 100, // MEMBER and above — they own the instances whose pipelines they edit
        ],
        'publisher' => [
            'label'     => 'Deploy',
            'blurb'     => 'Decide where and how a project goes live. Publishing runs as a pipeline in the project itself, so it schedules and debugs like any other. Runs as the publisher.tiknix sidecar — deliberately outside the app, since a finished application should not ship its deployment tooling.',
            'min_level' => 100, // MEMBER and above — they own the projects they publish
        ],
        'workbench' => [
            'label'     => 'Builder',
            'blurb'     => 'Plan, build + track AI-assisted development tasks per instance. Runs as the workbench.tiknix sidecar; each instance\'s task data lives in its own workbench.db.',
            'min_level' => 100, // MEMBER and above — they own the instances they build in
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

    /**
     * May this member USE $flag? Admins always; everyone else needs the grant.
     *
     * isEnabled() answers "is the switch on", which is the right question for a nav item
     * an admin has chosen for themselves. This answers "may they do it", which is the
     * question a route gate asks — and the two differ for administrators: requiring an
     * admin to grant themselves a flag before they can reach tooling they already
     * administer is a lockout waiting to happen, not a security boundary. The boundary
     * is the grant for everyone below them.
     */
    public static function allows(string $flag, $memberId = null, ?int $level = null): bool {
        if ($memberId === null) {
            $m = \Flight::getMember();
            $memberId = (int) ($m->id ?? 0);
            if ($level === null) $level = (int) ($m->level ?? 101);
        }
        if ($level !== null && (int) $level <= self::ADMIN_LEVEL) return true;
        return self::isEnabled($flag, $memberId, $level);
    }

    /**
     * ADMIN, as a literal rather than the LEVELS constant.
     *
     * LEVELS is defined by the web bootstrap, and this class is reached from CLIs too
     * (the plan pipeline, seed scripts). Referencing it here made a gate that worked in a
     * request and fatalled in a script — the sort of dependency that only shows up when
     * something already went wrong. Mirrors TwoFactorAuth::REQUIRED_LEVELS.
     */
    private const ADMIN_LEVEL = 50;

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
