<?php
/**
 * Ecommerce — the feature-flagged storefront toolset hub.
 *
 * Gated by the per-member `ecommerce` feature flag (see app\Feature): the flag is
 * toggled by an admin on the Edit Member page, available only to ADMIN/ROOT, and
 * the left-nav "Ecommerce" tab is shown by the same check.
 *
 * The hub is INSTANCE-SCOPED — a member's stores are their AI Builder instances,
 * and every ecommerce capability (payments, products, storefront) belongs to one
 * instance. Each feature card surfaces the connection it depends on (Payments ->
 * that instance's Stripe connection) and links into the /connections hub, so
 * connections are always tied to the feature that uses them.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Feature;
use app\Bean;
use RedBeanPHP\R;

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

    /** GET /ecommerce?id=<instance> — the storefront tools hub for one store. */
    public function index($params = []): void {
        if (!$this->requireFeature()) return;

        $instances = R::find('instance', 'member_id = ? ORDER BY created_at DESC', [(int)$this->member->id]);

        // Focus one store: ?id= when it is the member's, else the most recent.
        $wantId   = (int)$this->getParam('id', 0);
        $selected = null;
        foreach ($instances as $i) {
            if ($wantId && (int)$i->id === $wantId) { $selected = $i; break; }
        }
        if (!$selected && !$wantId && count($instances)) {
            $selected = $instances[array_key_first($instances)];
        }

        // Live Stripe status for the selected store — this is the Payments tie.
        $stripe = null;
        if ($selected) {
            $ad = Flight::get('cachedDatabaseAdapter');
            if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('connections');
            $conns = [];
            foreach (Bean::find('connections',
                'member_id = ? AND instance_id = ? AND connector_type = ? AND enabled = 1',
                [(int)$this->member->id, (int)$selected->id, 'stripe']) as $c) {
                if (!empty($c->revokedAt)) continue;
                $conns[] = [
                    'environment' => $c->environment ?: 'production',
                    'name'        => $c->externalName ?: $c->externalEid,
                ];
            }
            $stripe = ['connected' => count($conns) > 0, 'connections' => $conns];
        }

        $this->render('ecommerce/index', [
            'title'     => 'Ecommerce',
            'instances' => $instances,
            'selected'  => $selected,
            'stripe'    => $stripe,
        ]);
    }
}
