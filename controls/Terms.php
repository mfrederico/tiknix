<?php
/**
 * Terms Controller
 * Displays terms of service page
 */

namespace app;

use \Flight as Flight;

class Terms extends BaseControls\Control {

    /**
     * Terms of service page
     */
    public function index() {
        $this->render('terms/index', [
            'title' => 'Terms of Service'
        ]);
    }
}
