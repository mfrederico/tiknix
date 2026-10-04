<?php
/**
 * ProjectNav — what the sidebar's project panel (views/platform/chrome_navtop.php) offers one
 * member on one project, decided in one place so the panel, the Deploy page and the launcher
 * cannot disagree.
 *
 * Three things decide a link, and they are different questions:
 *
 *   the member's TIKNIX grants    a sidecar plugin is behind a feature flag (app\Feature), per
 *                                 member.
 *   the member's ROLE ON THE      AppAccess::level — the owner (1), a team owner/admin of a team
 *   PROJECT                       the project is shared with (50), a team member (100). It is the
 *                                 level Tiknix vouches for inside the project's own app, so it
 *                                 decides which of the app's pages are worth offering.
 *   what the PLUGIN is            [sidecar.<name>] in conf/config.ini:
 *                                   scope   = project (default) | platform — a platform plugin
 *                                             (Insights) is about Tiknix, not the selected
 *                                             project, and belongs in the Tiknix menu.
 *                                   premium = true — a paid plugin (QA Testing). It gets its own
 *                                             group. Whether it is on offer to someone who does
 *                                             not have it is [<feature>] available, which a
 *                                             premium plugin MUST set (true = an upgrade, false =
 *                                             not offered yet) — the nav does not guess which.
 */
namespace app;

use app\Sidecar\Registry;

class ProjectNav {

    /** What the panel calls each role on a project (AppAccess::level). */
    public const ROLES = [1 => 'Owner', 50 => 'Team admin', 100 => 'Team member'];

    /**
     * The project's own pages (its app, in its container): path, icon, label, and the level the
     * app asks for the page (the runtime's authcontrol seed: dashboard 100, the rest ADMIN).
     * Connections is not here: /connections on Tiknix asks the app what it is connected to.
     */
    public const APP_PAGES = [
        ['/dashboard',    'speedometer2', 'Dashboard',    100],
        ['/agents',       'robot',        'Agents & MCP', 50],   // models, MCP servers, skills & plugins: one page of the app
        ['/pipelines',    'diagram-2',    'Data',         50],
        ['/integrations', 'diagram-3',    'Integrations', 50],
        ['/settings',     'gear',         'Settings',     50],
        ['/admin',        'people',       'Members',      50],
    ];

    /** The name of a role on a project. No role is a fault for someone who can open the project. */
    public static function roleName(?int $projectLevel): string {
        if ($projectLevel !== null && isset(self::ROLES[$projectLevel])) return self::ROLES[$projectLevel];
        error_log('ERROR ProjectNav::roleName(): no role name for project level ' . var_export($projectLevel, true)
            . ' (AppAccess::level returned a level ProjectNav::ROLES does not name, or a team role AppAccess::TEAM_LEVEL does not map)');
        return 'No role';
    }

    /**
     * The app pages a member at $projectLevel can open. Tiknix signs them in at that level
     * (AppToken::launch), so a page above it would answer 403 — it is left out. No role: none.
     *
     * @return array<int, array{0:string,1:string,2:string,3:int}>
     */
    public static function appPages(?int $projectLevel, bool $mcpGrant = false): array {
        if ($projectLevel === null) return [];
        $pages = array_values(array_filter(self::APP_PAGES, fn($p) => $projectLevel <= $p[3]));
        // A team member holding the MCP Access grant gets the part of the agents page the grant
        // covers (the app is told of the grant when it signs them in).
        if ($mcpGrant && $projectLevel > 50) $pages[] = ['/agents?tab=mcp', 'hdd-network', 'MCP & skills', 100];
        return $pages;
    }

    /** project | platform — [sidecar.<name>] scope. Anything else is a typo, said loudly. */
    public static function scope(string $name): string {
        $scope = \Flight::get("sidecar.{$name}.scope");
        if ($scope === null || $scope === '') return 'project';
        if (!in_array($scope, ['project', 'platform'], true)) {
            throw new \RuntimeException("conf/config.ini [sidecar.{$name}] scope is '{$scope}' — it is 'project' (acts on the selected project) or 'platform' (about Tiknix itself).");
        }
        return $scope;
    }

    public static function premium(string $name): bool {
        return filter_var(\Flight::get("sidecar.{$name}.premium") ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * A premium plugin for one member: the door when they have it, the upgrade when it is on
     * offer and they do not, "coming soon" until it is.
     *
     * @return array{state:string,url:string} state: enabled | upsell | soon
     */
    public static function offer(string $name, int $memberId, int $level): array {
        $plugin = Registry::get($name);
        if (!$plugin) throw new \RuntimeException("conf/config.ini has no [sidecar.{$name}] section.");
        $feature = $plugin['feature'];
        $on = \Flight::get("{$feature}.available");
        if ($on === null) throw new \RuntimeException("conf/config.ini has no [{$feature}] available setting (true = offered as an upgrade, false = coming soon).");
        if (Feature::isEnabled($feature, $memberId, $level)) return ['state' => 'enabled', 'url' => '/sidecar/app/' . $name];
        return filter_var($on, FILTER_VALIDATE_BOOLEAN) ? ['state' => 'upsell', 'url' => '/contact?category=feature'] : ['state' => 'soon', 'url' => ''];
    }

    /**
     * The plugins to show a member, in conf/config.ini's order.
     *
     * A plugin they have is a link. A premium one they do not have is shown as the upgrade only
     * when it is on offer AND they own the selected project ($projectLevel 1): the owner is who
     * switches a premium plugin on for a project, so offering it to a team member sells them
     * something they cannot buy. Everything else is left out.
     *
     * @return array<int, array{name:string,label:string,icon:string,href:string,scope:string,premium:bool,state:string}>
     *         state: enabled | upsell
     */
    public static function plugins(int $memberId, int $level, ?int $projectLevel): array {
        $out = [];
        // Deploy is core's own page (controls/Deploy.php: a project's domains and exports), not a
        // sidecar — the publisher sidecar is gone — but it is gated and grouped like one, by the
        // 'publisher' feature (the Feature catalog's label for it is Deploy).
        if (Feature::isEnabled('publisher', $memberId, $level)) {
            $out[] = ['name' => 'publisher', 'label' => 'Deploy', 'icon' => 'bi-rocket-takeoff', 'href' => '/deploy',
                      'scope' => 'project', 'premium' => false, 'state' => 'enabled'];
        }
        foreach (Registry::launchable() as $name => $p) {
            $premium = self::premium($name);
            $entry = [
                'name' => $name, 'label' => $p['label'], 'icon' => $p['icon'], 'href' => '/sidecar/app/' . $name,
                'scope' => self::scope($name), 'premium' => $premium, 'state' => 'enabled',
            ];
            if ($premium) {
                $offer = self::offer($name, $memberId, $level);
                if ($offer['state'] === 'soon') continue;
                if ($offer['state'] === 'upsell') {
                    if ($projectLevel !== 1) continue;
                    $entry['state'] = 'upsell';
                    $entry['href'] = $offer['url'];
                }
            } elseif (!Feature::isEnabled($p['feature'], $memberId, $level)) {
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
