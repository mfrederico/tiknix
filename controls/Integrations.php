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

    /** Control-plane hub — pick one of the member's instances, show its automations. */
    private function controlPlane(): void {
        $instances = R::find('instance', 'member_id = ? ORDER BY created_at DESC', [(int)$this->member->id]);
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) {
            foreach ($instances as $cand) { if ($ok = $this->ownedInstance((int)$cand->id)) { $inst = $ok; break; } }
        }
        if (!$inst) { Flight::redirect('/aibuilder'); return; }

        $dir = $this->instanceDir($inst->slug);
        // Connected services for the selected instance, service+status only (the owner
        // sees full detail on /connections; this catalog never carries identifiers).
        $services = [];
        foreach (Bean::find('connections', 'instance_id = ? AND enabled = 1', [(int)$inst->id]) as $c) {
            $svc = (string)$c->connectorType; if ($svc === '') continue;
            if (!isset($services[$svc])) $services[$svc] = ['connector' => $svc, 'connected' => false, 'revoked' => false];
            if (empty($c->revokedAt)) $services[$svc]['connected'] = true; else $services[$svc]['revoked'] = true;
        }
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
     * Service+status-only connected-services list for the instance catalog. Uses the
     * instance's own broker key (read-only metadata, scoped by that key's instance_id)
     * and strips everything except connector + connected/revoked status. The "no broker
     * key" state (nothing wired yet) is surfaced as an empty list, not a warning.
     */
    private function connectedServices(string $root): array {
        $broker = InstanceAutomations::brokerConnections($root);
        $services = [];
        foreach ($broker['connections'] ?? [] as $c) {
            $svc = (string)($c['connector'] ?? ''); if ($svc === '') continue;
            if (!isset($services[$svc])) $services[$svc] = ['connector' => $svc, 'connected' => false, 'revoked' => false];
            if (!empty($c['enabled']) && empty($c['revoked'])) $services[$svc]['connected'] = true;
            if (!empty($c['revoked'])) $services[$svc]['revoked'] = true;
        }
        $err = (string)($broker['error'] ?? '');
        if (stripos($err, 'no broker key') !== false) $err = '';   // not-yet-wired = empty state, not an error
        return ['services' => array_values($services), 'brokerError' => $err];
    }

    private function instanceDir(string $slug): string {
        return '/var/www/html/default/' . $slug . '.tiknix';
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
