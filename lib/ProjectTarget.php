<?php
/**
 * ProjectTarget — which project a "configure the project" page works on.
 *
 * On CORE: the project selected in the header (ProjectContext), which the member can
 * reach — never core's own tree by default. On a PROJECT (an instance): the install itself;
 * there is nothing to select. One rule for every page that edits a project's files or
 * settings (Plugins, Agent Setup, Hooks, MCP servers), so they cannot disagree about which
 * codebase they are touching — the failure "per-instance, never core" is about.
 *
 * null = on core with no project selected: the caller sends the member to /projects. It is
 * never "core, then".
 */

namespace app;

class ProjectTarget {

    /**
     * @return array{id:int,slug:string,name:string,dir:string,url:string,here:bool}|null
     */
    /**
     * The control plane's resolver — callable(int $memberId): ?array (same shape) — set at
     * boot by lib/controlplane.php: on core the target is the member's SELECTED project, null
     * when none is chosen. An app has no resolver and is always its own target.
     * @var callable|null
     */
    public static $resolver = null;

    public static function forMember(int $memberId): ?array {
        if (self::$resolver !== null && is_core_install()) return (self::$resolver)($memberId);
        $dir = \app\Paths::root();
        $url = rtrim(trim((string) \Flight::get('app.baseurl')), '/');
        if ($url === '') throw new \RuntimeException('[app] baseurl is not set in conf/config.ini, so this project cannot name its own URL.');
        return [
            'id'   => 0,
            'slug' => explode('.', basename($dir), 2)[0],
            'name' => \Flight::siteName(),
            'dir'  => $dir,
            'url'  => $url,
            'here' => true,
        ];
    }
}
