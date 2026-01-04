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
}
