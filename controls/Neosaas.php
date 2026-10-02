<?php
/**
 * Neosaas — /neosaas, the manifesto's old URL. The term was renamed NewSaaS on 2026-10-02
 * and the page moved to /newsaas (controls/Newsaas.php); this stays only so the links
 * already out there (clicksimple.com, the founder's posts, search results) keep working.
 */

namespace app;

use \Flight as Flight;

class Neosaas extends BaseControls\Control {

    public function index($params = []) {
        Flight::redirect('/newsaas', 301);
    }
}
