<?php
/**
 * Fleet — the control plane's view of every project (instance): unattended auto-triage per
 * instance, with its audit trail. Moved out of Admin (/admin/instances) in RUNTIME-SPLIT-MAP.md
 * step 2 — an app's admin has no instances. ADMIN only.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Fleet extends BaseControls\Control {

    /**
     * /fleet — unattended auto-triage, per instance.
     *
     * Admin-only (the check at the top), which is the point: turning this on lets the
     * control plane launch headless agent builds against a client's repo with nobody
     * watching, so it is a spend decision and not a preference the client sets. There is
     * deliberately no member-facing route — see Model_Instance::setAutoTriage.
     */
    public function index($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        $this->viewData['title'] = 'Instances — Unattended Builds';
        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->flash('error', 'CSRF validation failed');
                Flight::redirect('/fleet');
                return;
            }
            $id  = (int) ($request->data->instance_id ?? 0);
            $on  = !empty($request->data->enabled);
            $why = trim((string) ($request->data->note ?? ''));

            $inst = Bean::load('instance', $id);
            if (!$inst->id) {
                $this->flash('error', 'No such instance');
            } else {
                // The model writes the flag and the audit row together, so they cannot
                // come apart, and returns false when nothing actually changed.
                $changed = $inst->setAutoTriage($on, (int) $this->member->id, $why);
                $this->flash(
                    $changed ? 'success' : 'info',
                    $changed
                        ? sprintf('Unattended builds %s for %s.', $on ? 'ENABLED' : 'disabled', $inst->slug)
                        : sprintf('%s was already %s — nothing recorded.', $inst->slug, $on ? 'enabled' : 'disabled')
                );
            }
            Flight::redirect('/fleet');
            return;
        }

        $rows = [];
        foreach (Bean::findAll('instance', 'ORDER BY slug') as $inst) {
            $last  = $inst->lastAudit('auto_triage');
            $byId  = $last ? (int) $last->memberId : 0;
            $rows[] = [
                'bean'    => $inst,
                'on'      => $inst->autoTriageOn(),
                'last'    => $last,
                'by'      => $byId ? (string) (Bean::load('member', $byId)->email ?: 'member #' . $byId) : '',
                'owner'   => (string) (Bean::load('member', (int) $inst->memberId)->email ?: ''),
            ];
        }

        $this->viewData['rows'] = $rows;
        // Whole-trail view: who granted what, when, across every instance.
        $this->viewData['trail'] = Bean::findAll('instanceaudit', 'ORDER BY id DESC LIMIT 25');
        $this->render('fleet/index', $this->viewData);
    }
}
