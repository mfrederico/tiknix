<?php
/**
 * Stories — pretty /stories URL for the founder stories page.
 *
 * Thin alias controller, like Pricing: /stories auto-routes here and delegates to
 * Index::stories(), which gates the page to the flagship site and redirects to "/"
 * on a provisioned instance clone. Public via the stories::* row in 02_AuthControl.
 */

namespace app;

class Stories extends BaseControls\Control {

    public function index($params = []) {
        (new Index())->stories($params);
    }
}
