<?php
/**
 * Ecommerce — the feature-flagged storefront toolset hub.
 *
 * Every route here is gated by the per-member `ecommerce` feature flag (see
 * app\Feature). The flag is toggled by an admin on the Edit Member page and is
 * only available to ADMIN/ROOT-level members; the left-nav "Ecommerce" tab is
 * shown by the same check. Product catalog / inventory / checkout tools land in
 * later phases — this hub is the landing surface and the guard they share.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Feature;

class Ecommerce extends Control {

    /** Require login AND the ecommerce feature flag; redirect otherwise. */
    private function requireFeature(): bool {
        if (!$this->requireLogin()) return false;
        if (!Feature::isEnabled('ecommerce', (int)$this->member->id, (int)$this->member->level)) {
            $this->flash('error', 'The Ecommerce feature is not enabled for your account.');
            Flight::redirect('/dashboard');
            return false;
        }
        return true;
    }

    /** GET /ecommerce — the storefront tools hub. */
    public function index($params = []): void {
        if (!$this->requireFeature()) return;
        $this->render('ecommerce/index', ['title' => 'Ecommerce']);
    }
}
