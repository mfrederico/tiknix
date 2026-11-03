<?php
/**
 * Help Controller
 * Provides help and documentation pages
 */

namespace app;

use \Flight as Flight;

class Help extends BaseControls\Control {
    
    /**
     * Main help page
     */
    public function index() {
        $this->render('help/index', [
            'title' => 'Help Center'
        ]);
    }
    
    /**
     * Getting started guide
     */
    public function start() {
        $this->render('help/getting-started', [
            'title' => 'Your first project'
        ]);
    }
    
    /**
     * FAQ page
     */
    public function faq() {
        $this->render('help/faq', [
            'title' => 'Frequently Asked Questions'
        ]);
    }
    
    /**
     * API documentation
     */
    public function api() {
        $this->render('help/api', [
            'title' => 'API Documentation'
        ]);
    }
}