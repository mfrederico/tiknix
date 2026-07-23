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

class Integrations extends Control {

    /** GET /integrations — read-only view of this instance's connections + automations. */
    public function index($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) { Flight::redirect('/dashboard'); return; }

        $root = dirname(__DIR__);                       // the app root this code runs in
        $broker = InstanceAutomations::brokerConnections($root);

        $this->render('integrations/index', [
            'title'          => 'Integrations',
            'connections'    => $broker['connections'] ?? [],
            'brokerError'    => $broker['error'] ?? '',
            'pipelines'      => InstanceAutomations::pipelines($root),
            'durableObjects' => InstanceAutomations::durableObjects($root),
            'appName'        => basename($root),
        ]);
    }
}
