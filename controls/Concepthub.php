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
 *
 * Read-only. Publishing is a CLI action on the control plane (clitool --concept-publish).
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
