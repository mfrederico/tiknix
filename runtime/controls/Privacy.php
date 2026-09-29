<?php
/**
 * Privacy Controller
 * Displays privacy policy page
 */

namespace app;

use \Flight as Flight;

class Privacy extends BaseControls\Control {

    /**
     * Privacy policy page
     */
    public function index() {
        $this->render('privacy/index', [
            'title' => 'Privacy Policy'
        ]);
    }
}
