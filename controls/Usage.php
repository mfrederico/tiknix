<?php
/**
 * Usage — what on this install nobody opens (app\PageUse): the pruning report. For Tiknix's
 * admins; it reads, it changes nothing. A rule is changed or removed on Admin → Permissions.
 * ADMIN (seed 47).
 */

namespace app;

use \Flight as Flight;

class Usage extends BaseControls\Control {

    /** GET /usage — the report. */
    public function index() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        $this->render('usage/index', ['title' => 'What is used', 'report' => PageUse::report()]);
    }
}
