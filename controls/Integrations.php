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
        $this->render('integrations/hub', [
            'title'          => 'Integrations',
            'instance'       => $inst,
            'instances'      => $instances,
            'pipelines'      => InstanceAutomations::pipelines($dir),
            'durableObjects' => InstanceAutomations::durableObjects($dir),
            'baseUrl'        => $this->instanceBaseUrl($dir),
        ]);
    }

    /** Inside-an-instance read-only view (admins only). */
    private function instanceView(): void {
        if (!Flight::hasLevel(LEVELS['ADMIN'])) { Flight::redirect('/dashboard'); return; }
        $root = dirname(__DIR__);                       // the app root this code runs in
        $this->render('integrations/index', [
            'title'          => 'Integrations',
            'pipelines'      => InstanceAutomations::pipelines($root),
            'durableObjects' => InstanceAutomations::durableObjects($root),
            'appName'        => basename($root),
            // This instance's own public base URL — used to show the concrete
            // MCP tool + REST API paths on the exposed pipeline cards.
            'baseUrl'        => rtrim((string) (Flight::get('app.baseurl') ?: ''), '/'),
        ]);
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
