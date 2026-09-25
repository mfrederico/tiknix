<?php
/**
 * Neosaas — /neosaas, the NeoSaaS manifesto: software cut for one business, owned by that
 * business, hosted like SaaS without the rent. This page is the canonical definition of the
 * term (clicksimple.com and the founder's posts link here), so its URL should not move.
 *
 * PRIMARY site only, like Stories: instances replace Index with their own home page, so a
 * top-level marketing page gets its own controller. Public via the neosaas::* row in
 * 02_AuthControl.
 */

namespace app;

use \Flight as Flight;

class Neosaas extends BaseControls\Control {

    public function index($params = []) {
        if (!is_control_plane()) { Flight::redirect('/'); return; }
        $this->render('index/neosaas', [
            'title' => 'NeoSaaS — software that fits you, that you own — tiknix',
        ], false);
    }
}
