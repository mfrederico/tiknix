<?php
/**
 * Site — which franchise / location this request is acting for (lib/Sites.php).
 *
 *   GET  /site/status   the site the HOST resolved to, whether the install is multi-site, and
 *                       — for a signed-in member — which connector roles are bound here. Public
 *                       (101): it is how two domains on one install are proved to be two sites,
 *                       and it reveals nothing a page's branding does not.
 *   POST /site/switch   the sidebar switcher: act as another site for this session. Member (100).
 */
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Site extends Control {

    public function status($params = []): void {
        $site = Flight::get('site');   // resolved for every request by the base controller
        if (!$site) { Flight::jsonError('No site resolved for this request — run `php scripts/clitool.php --build` (seed 21_Sites).', 500); return; }
        $out = [
            'host'   => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'site'   => ['id' => (int) $site->id, 'slug' => (string) $site->slug, 'name' => (string) $site->name, 'domain' => (string) $site->domain],
            'multi'  => Sites::multi(),
            'config' => (array) (Flight::get('site.config_applied') ?? []),
            // the release this install runs (--update pins it; '' on the control plane and before the first update)
            'release' => \app\InstanceUpdate::pinned(dirname(__DIR__)),
        ];
        if (Flight::isLoggedIn()) {
            $roles = [];
            foreach (Concepts::instance()->enabled() as $name => $m) {
                foreach ($m->connectorRoles as $r) {
                    if ($r['scope'] === 'entity') continue;
                    try {
                        $c = ConnectionBindings::for($name, $r['role']);
                        $roles[] = ['concept' => $name, 'role' => $r['role'], 'bound' => ConnectionStore::alias($c), 'type' => (string) $c->connectorType];
                    } catch (UnboundRoleException | MissingConnectorException $e) {
                        $roles[] = ['concept' => $name, 'role' => $r['role'], 'bound' => null, 'problem' => $e->getMessage()];
                    }
                }
            }
            $out['roles'] = $roles;
        }
        Flight::jsonSuccess($out);
    }

    public function switch($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;
        $slug = (string) $this->getParam('site', '');
        $s = Sites::bySlug($slug);
        if (!$s) { Flight::jsonError("No site '{$slug}'.", 404); return; }
        Sites::switchTo((int) $s->id);
        Flight::jsonSuccess(['site' => ['slug' => (string) $s->slug, 'name' => (string) $s->name]], "Now acting for {$s->name}.");
    }
}
