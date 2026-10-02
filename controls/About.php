<?php
/**
 * About — /about, "Who we are": the company behind tiknix and the person who builds it.
 *
 * PRIMARY site only, like Stories and Newsaas: instances replace Index with their own home
 * page, so a top-level marketing page gets its own controller. Public via the about::* row
 * in 02_AuthControl.
 */

namespace app;

use \Flight as Flight;

class About extends BaseControls\Control {

    public function index($params = []) {
        $this->render('index/about', [
            'title' => 'Who we are: Matt and Fabian — tiknix',
        ], false);
    }
}
