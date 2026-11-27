<?php
/**
 * Agencies — /agencies, "Tiknix for agencies": build bespoke software for clients once and run it for every client.
 * It was the front page from 2026-11-07 until 2026-11-27, when "/" became the done-for-you offer (index/build); the
 * front page's FAQ and the marketing nav link here.
 *
 * PRIMARY site only, like Newsaas and Stories: a top-level marketing page gets its own controller. Public via the
 * agencies::* row in seed 64_AgenciesRoute.
 */

namespace app;

class Agencies extends BaseControls\Control {

    public function index($params = []) {
        $this->render('index/agencies', [], false);
    }
}
