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
    public static function forMember(int $memberId): ?array {
        if (!is_core_install()) {
            $dir = dirname(__DIR__);
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
        $inst = ProjectContext::current($memberId);
        if ($inst === null) return null;
        $m = $inst->box();
        return [
            'id'   => (int) $inst->id,
            'slug' => (string) $inst->slug,
            'name' => (string) ($inst->displayName ?? '') !== '' ? (string) $inst->displayName : (string) $inst->slug,
            'dir'  => $m->dir(),
            'url'  => $m->url(),
            'here' => false,
        ];
    }
}
