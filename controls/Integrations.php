<?php
/**
 * Integrations — the INSTANCE-side "what am I wired to?" view.
 *
 * Core and instances are separate apps with separate databases, so an instance holds
 * no connection rows: its credentials live encrypted in core and are reached through
 * the broker. That makes connections invisible from inside the instance, which reads
 * like they vanished. This page closes that gap — read-only:
 *
 *   • Connections — fetched from core with this instance's own broker key (metadata
 *     only; the credential never leaves core, exactly as before).
 *   • Pipelines + durable objects — read locally; they genuinely DO live here
 *     (pipelines/*.json in this repo, dobject rows in this DB).
 *
 * Manage/author from the control plane (/connections there, or the pipeline editor).
 * On the control plane itself use /connections — that hub is the editable one.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R;
use app\BaseControls\Control;

class Integrations extends Control {

    /**
     * GET /integrations — the automations page.
     *  • Control plane: owner-scoped, instance-selectable hub of the chosen instance's
     *    pipelines + their MCP/REST/object endpoints (credentials live on /connections).
     *  • Inside an instance: read-only "what does this app expose" for admins.
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;
        if (builder_tools_enabled()) { $this->controlPlane(); return; }
        $this->instanceView();
    }

    /** Control-plane hub — the selected project's automations. */
    private function controlPlane(): void {
        $instances = R::find('instance', 'member_id = ? ORDER BY created_at DESC', [(int)$this->member->id]);

        // An explicit ?id= wins (deep links), then the project the member selected. NOT
        // "most recently created" — that guess showed one project's automations while
        // you believed you were in another, and Run would fire the wrong instance's
        // pipeline.
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) {
            $project = \app\ProjectContext::current((int)$this->member->id);
            if ($project) $inst = $this->ownedInstance((int)$project->id);
        }
        if (!$inst) { Flight::redirect('/projects'); return; }

        $dir = $this->instanceDir($inst->slug);
        // Connected services for the selected instance, service+status only (the owner
        // sees full detail on /connections; this catalog never carries identifiers).
        $services = \app\ConnectionStore::withInstall((int)$inst->id, function () {
            $out = [];
            foreach (Bean::find('connections', 'enabled = 1') as $c) {
                $svc = (string)$c->connectorType; if ($svc === '') continue;
                if (!isset($out[$svc])) $out[$svc] = ['connector' => $svc, 'connected' => false, 'revoked' => false];
                if (empty($c->revokedAt)) $out[$svc]['connected'] = true; else $out[$svc]['revoked'] = true;
            }
            return $out;
        }, []);
        $this->render('integrations/hub', [
            'title'          => 'Integrations',
            'instance'       => $inst,
            'instances'      => $instances,
            'pipelines'      => InstanceAutomations::pipelines($dir),
            'durableObjects' => InstanceAutomations::durableObjects($dir),
            'baseUrl'        => $this->instanceBaseUrl($dir),
            'services'       => array_values($services),
            'brokerError'    => '',
        ]);
    }

    /**
     * Inside-an-instance read-only catalog. Open to ALL members (non-admin included) —
     * the point is that builders can discover what's available to integrate with.
     * Connections show as SERVICE + STATUS only (never account identifiers); managing
     * them stays admin-only on /connections.
     */
    private function instanceView(): void {
        $root = dirname(__DIR__);                       // the app root this code runs in
        $this->render('integrations/index', [
            'title'          => 'Integrations',
            'pipelines'      => InstanceAutomations::pipelines($root),
            'durableObjects' => InstanceAutomations::durableObjects($root),
            'appName'        => basename($root),
            // This instance's own public base URL — used to show the concrete
            // MCP tool + REST API paths on the exposed pipeline cards.
            'baseUrl'        => rtrim((string) (Flight::get('app.baseurl') ?: ''), '/'),
        ] + $this->connectedServices($root));
    }

    /**
     * Service+status-only connected-services list for the instance catalog, read from
     * THIS install's own store.
     *
     * It used to ask core over the broker and then flatten every failure into an empty
     * list -- `$broker['connections'] ?? []` plus a rule that swallowed "no broker key"
     * on purpose. That made four different states (nothing connected / no broker key /
     * core unreachable / malformed reply) render identically as "nothing connected".
     * There is no remote call left to fail, so there is nothing left to flatten.
     */
    private function connectedServices(string $root): array {
        $services = \app\ConnectionStore::withOwnDb(function () {
            $out = [];
            foreach (Bean::find('connections') as $c) {
                $svc = (string)$c->connectorType; if ($svc === '') continue;
                if (!isset($out[$svc])) $out[$svc] = ['connector' => $svc, 'connected' => false, 'revoked' => false];
                if ((int)$c->enabled === 1 && empty($c->revokedAt)) $out[$svc]['connected'] = true;
                if (!empty($c->revokedAt)) $out[$svc]['revoked'] = true;
            }
            return $out;
        }, []);

        return ['services' => array_values($services), 'brokerError' => ''];
    }

    private function instanceDir(string $slug): string {
        // Was a hard-coded '.tiknix', which is only right while every instance uses that
        // app namespace. Model_Instance derives it from the row.
        return \Model_Instance::dirForSlug($slug);
    }

    /** Load an instance the current member owns and that exists on disk. */
    private function ownedInstance($id) {
        $id = (int)$id;
        if (!$id) return null;
        $inst = R::load('instance', $id);
        if (!$inst->id || (int)$inst->memberId !== (int)$this->member->id) return null;
        if (!is_file($this->instanceDir($inst->slug) . '/public/index.php')) return null;
        return $inst;
    }

    /** The instance's own public base URL (from its config.ini). */
    private function instanceBaseUrl(string $dir): string {
        $ini = @parse_ini_file($dir . '/conf/config.ini', true) ?: [];
        return rtrim((string) ($ini['app']['baseurl'] ?? ''), '/');
    }
}
