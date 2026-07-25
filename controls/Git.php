<?php
/**
 * Git — read-only smart-HTTP git endpoint so a tenant container can clone its own
 * instance repository from core.
 *
 *   GET  /git/<slug>.git/info/refs?service=git-upload-pack
 *   POST /git/<slug>.git/git-upload-pack
 *
 * ROUTING: git fixes these URL shapes, and the repo name sits where the default router
 * expects a METHOD — so this uses the router's `_fallback` hook rather than a custom
 * route file. defaultRoute parses /git/<slug>.git/info/refs as class=git,
 * method="<slug>.git", op="info", opid="refs"; no such method exists, so the router
 * hands the whole thing here. The permission lookup is therefore against the SLUG, not
 * a method name, which is why authcontrol needs the wildcard row `git::* = 101`.
 *
 * SECURITY: reachable unauthenticated and self-gating, the same pattern documented for
 * controls/Mcp.php — PUBLIC means REACHABLE, not unprotected. Every request must present
 * the target instance's deploy token via HTTP Basic, and only git-upload-pack is served,
 * so there is no push path. See lib/GitHttp.php for the full model.
 */
namespace app;

use \Flight as Flight;

class Git extends BaseControls\Control {

    /**
     * Catch-all for the git protocol paths.
     *
     * @param string $segment the URL segment where a method would normally be: "<slug>.git"
     * @param array  $params  router params; $params['operation']->name / ->type carry the rest
     */
    public function _fallback(string $segment, array $params = []): void {
        $slug = preg_replace('/\.git$/i', '', (string) $segment);
        $op   = (string) ($params['operation']->name ?? '');
        $sub  = (string) ($params['operation']->type ?? '');

        // GET /git/<slug>.git/info/refs?service=git-upload-pack
        if ($op === 'info' && $sub === 'refs') {
            $service = (string) (Flight::request()->query['service'] ?? '');
            // Allowlist, not passthrough: git-receive-pack must never reach the shell.
            if ($service !== GitHttp::SERVICE) {
                GitHttp::fail(403, 'only ' . GitHttp::SERVICE . ' is served (this endpoint is read-only)');
                return;
            }
            if (($inst = $this->gate($slug)) === null) return;
            GitHttp::advertiseRefs($inst['dir']);
            return;
        }

        // POST /git/<slug>.git/git-upload-pack
        if ($op === GitHttp::SERVICE) {
            if (($inst = $this->gate($slug)) === null) return;
            GitHttp::uploadPack($inst['dir']);
            return;
        }

        // Anything else — notably git-receive-pack — is not served.
        GitHttp::fail(404, 'no such git service');
    }

    /**
     * Resolve the slug and require the instance's deploy token. Returns null having
     * already written the response when the request should not proceed.
     */
    private function gate(string $slug): ?array {
        $r = GitHttp::resolve($slug);
        if (!$r['ok']) {
            // Same status for "no such instance" and "not active" — an unauthenticated
            // caller should not be able to enumerate which instances exist.
            GitHttp::fail((int) ($r['code'] ?? 404), (string) $r['error']);
            return null;
        }
        if (!GitHttp::authorize($r['bean'])) {
            GitHttp::requireAuth();
            return null;
        }
        return $r;
    }
}
