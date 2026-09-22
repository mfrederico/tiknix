<?php
/**
 * Concepthub — the control plane's concept catalog, served to instances.
 *
 * An instance has no catalog of its own. Its planner, and its `--concept-install`, ask here,
 * authenticating with the instance's OWN broker key (conf/broker.ini) — the capability it
 * already uses to reach its connections. No new credential, and no anonymous access: what
 * is in the catalog is source code.
 *
 *   GET /concepthub/search?q=…&limit=…   ranked summaries
 *   GET /concepthub/get?name=…           one concept: summary, manifest, file list
 *   GET /concepthub/bundle?name=…        the concept itself, as {path: base64}
 *   POST /concepthub/install?name=…      queue an install INTO the calling instance, as a
 *                                        build owned by that instance's owner (the Install
 *                                        button on a project's Plugins page)
 *
 * Publishing is a CLI action on the control plane (clitool --concept-publish).
 *
 * authcontrol: concepthub::* = 101 — PUBLIC means reachable, not unprotected; every
 * method authenticates the broker key itself, exactly like Brokerinfo and Mcp.
 * See lib/ConceptCatalog.php and COMPONENTS_PLAN.md.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Concepthub extends Control {

    public function search($params = []) {
        if (!($catalog = $this->catalog())) return;
        $q = (string) $this->getParam('q', '');
        $limit = (int) $this->getParam('limit', 10);
        $this->answer(fn() => $catalog->search($q, $limit));
    }

    public function get($params = []) {
        if (!($catalog = $this->catalog())) return;
        $this->answer(fn() => $catalog->get((string) $this->getParam('name', '')));
    }

    public function bundle($params = []) {
        if (!($catalog = $this->catalog())) return;
        $name = (string) $this->getParam('name', '');
        $this->answer(function () use ($catalog, $name) {
            $bundle = $catalog->bundle($name);
            $this->logger->info('Concept bundle served', ['concept' => $name, 'version' => $bundle['version'], 'instance_id' => $this->instanceId]);
            return $bundle;
        });
    }

    /**
     * POST /concepthub/install?name=… — the calling instance wants $name installed into
     * itself. The instance cannot do this alone: the install must end as a commit and merge
     * on its branch, and the build machinery (plan-ingest, PlanOrchestrator) resolves the
     * project in THIS install's registry. So it asks, with the key that already proves which
     * instance it is, and the build runs here as the instance's owner.
     */
    public function install($params = []) {
        if (!($catalog = $this->catalog())) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { $this->fail('POST only.', 405); return; }
        $name = (string) $this->getParam('name', '');
        if (!preg_match('/^[a-z][a-z0-9]*$/D', $name)) { $this->fail('That is not a plugin name.', 400); return; }

        $inst = Bean::load('instance', $this->instanceId);
        if (!$inst->id || (int) ($inst->memberId ?? 0) <= 0) {
            $this->logger->error('ERROR Concepthub install: broker key names an instance with no registry row or no owner', ['instance_id' => $this->instanceId]);
            $this->fail('This instance has no owner on record here, so nobody can own the build.', 409);
            return;
        }
        $project = ['slug' => (string) $inst->slug, 'dir' => \Model_Instance::dirFrom((string) $inst->slug, (string) ($inst->app ?? ''))];
        try {
            $catalog->get($name);   // a name the catalog does not hold is a 404 in words, before anything runs
        } catch (ConceptException $e) {
            $this->fail($e->getMessage(), 404);
            return;
        }
        $r = ConceptCatalog::queueInstall($name, $project, (int) $inst->memberId);
        if ($r['ok']) {
            $this->logger->info('Concept install queued by instance', ['concept' => $name, 'project' => $project['slug'], 'member_id' => (int) $inst->memberId]);
            Flight::json(['queued' => true, 'message' => "Installing '{$name}' — a build with no agent, usually under a minute. Reload this page once it has merged, then switch it on."]);
        } else {
            $this->logger->warning('Concept install not queued (instance request)', ['concept' => $name, 'project' => $project['slug'], 'said' => $r['said']]);
            Flight::json(['queued' => false, 'message' => "Could not queue '{$name}': " . $r['said']]);
        }
    }

    private int $instanceId = 0;

    /** The local catalog, once the caller has proven it is an instance. Null = already answered. */
    private function catalog(): ?ConceptCatalog {
        $key = BrokerService::keyFromRequest();
        if (!$key) { $this->fail('Forbidden.', 403); return null; }
        $this->instanceId = (int) ($key->instanceId ?? 0);
        if ($this->instanceId <= 0) { $this->fail('This broker key is not bound to an instance.', 403); return null; }
        try {
            $catalog = ConceptCatalog::forInstall();
        } catch (ConceptException $e) {
            $this->logger->error('ERROR Concepthub: ' . $e->getMessage());
            $this->fail('This install does not serve a concept catalog.', 503);
            return null;
        }
        if (!$catalog->isLocal()) {
            // An instance answering this would only relay to the control plane, with its own key.
            $this->fail('This install does not serve a concept catalog.', 503);
            return null;
        }
        return $catalog;
    }

    /**
     * A concept that does not exist or is malformed is the caller's 404, said in words.
     *
     * The catalog's directory is this server's business: a tenant on another host learns
     * nothing from it, so it never leaves in a `source` field or an error message.
     */
    private function answer(callable $work): void {
        $dir = rtrim((string) Flight::get('concepts.catalog_dir'), '/');
        $public = rtrim((string) Flight::get('app.baseurl'), '/') . '/concepthub';
        try {
            $data = $work();
            if (isset($data['source'])) $data['source'] = $public;
            Flight::json($data);
        } catch (ConceptException $e) {
            $this->fail($dir !== '' ? str_replace($dir, 'the catalog', $e->getMessage()) : $e->getMessage(), 404);
        }
    }
}
