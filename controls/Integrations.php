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
use app\BaseControls\Control;
use app\Bean;

class Integrations extends Control {

    /**
     * GET /integrations — what THIS app exposes (pipelines, tools, APIs) and the services it
     * is connected to. The control plane's hub for the selected project is core's own
     * override of this controller (RUNTIME-SPLIT-MAP.md step 2).
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;
        $this->instanceView();
    }

    /**
     * Inside-an-instance read-only catalog. Open to ALL members (non-admin included) —
     * the point is that builders can discover what's available to integrate with.
     * Connections show as SERVICE + STATUS only (never account identifiers); managing
     * them stays admin-only on /connections.
     */
    private function instanceView(): void {
        $root = \app\Paths::root();                       // the app root this code runs in
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
}
