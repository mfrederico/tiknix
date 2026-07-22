<?php
/**
 * Pricing — pretty /pricing URL for the marketing pricing page.
 *
 * Thin alias controller: /pricing auto-routes here and delegates to
 * Index::pricing(), which gates the page to the flagship site (the root control
 * plane) and redirects to "/" on a provisioned instance clone. Public via the
 * framework's default-public permission fallback — no authcontrol row needed.
 */

namespace app;

class Pricing extends BaseControls\Control {

    public function index($params = []) {
        (new Index())->pricing($params);
    }
}
