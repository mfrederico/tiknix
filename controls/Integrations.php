<?php
/**
 * Integrations — on the control plane, the door to the selected project's own page.
 *
 * A project's pipelines, durable objects and the services they are wired to live in its
 * container; the app's own /integrations (the runtime's controller) shows them. This one
 * opens that page, signed in (/projects/open), for the project selected in the header — it
 * never lists automations from a folder on this host, because no folder here holds a
 * project's code. The hub with the connect buttons is /connections.
 */
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;

class Integrations extends Control {

    /** GET /integrations — open the selected project's own Integrations page. */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        // An explicit ?id= wins (deep links), then the project the member selected — any
        // project they may WORK ON (owned, or shared through a team), since the page is theirs
        // to read. NOT "most recently created": that guess showed one project's automations
        // while you believed you were in another.
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) {
            $project = ProjectContext::current((int) $this->member->id);
            if ($project) $inst = $this->accessibleInstance((int) $project->id);
        }
        if (!$inst) { Flight::redirect('/projects'); return; }

        // /projects/open opens the SELECTED project's app; never another one's page under this one's name.
        $sel = ProjectContext::current((int) $this->member->id);
        if (!$sel || (int) $sel->id !== (int) $inst->id) {
            $this->flash('info', 'Select ' . ($inst->displayName ?: $inst->slug) . ' first, then open its page.');
            Flight::redirect('/projects');
            return;
        }
        Flight::redirect('/projects/open?to=' . rawurlencode('/integrations'));
    }

    /** A project the member may work on, in its container — the project picker's rule (Model_Instance::accessibleBy). */
    private function accessibleInstance($id) {
        $id = (int) $id;
        if (!$id) return null;
        $inst = Bean::load('instance', $id);
        if (!$inst->id || !$inst->accessibleBy((int) $this->member->id)) return null;
        if (!\Model_Instance::tenantRow($inst)) return null;
        return $inst;
    }
}
