<?php
/**
 * Demo Controller
 * Psychedelic demos and weird experiments
 */

namespace app;

use \Flight as Flight;

class Demo extends BaseControls\Control {

    /**
     * The psychedelic Buddy & Damon hello world
     */
    public function psychedelic() {
        $this->render('demo/psychedelic', [
            'title' => 'Hello Buddy & Damon - A Psychedelic Journey'
        ], false);
    }

    /**
     * LocalStorage test form demo
     * Tests saving/loading form data with base64 avatar to localStorage
     * URL: /demo/localstoreform
     */
    public function localstoreform() {
        $this->render('demo/test-localstore-form', [
            'title' => 'LocalStorage Test Form'
        ], false);
    }

    /**
     * Simple test page
     * URL: /demo/testpage
     */
    public function testpage() {
        $this->render('demo/testpage', [
            'title' => 'Test Page'
        ]);
    }
}
