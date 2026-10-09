<?php
/**
 * Newsaas — /newsaas, the NewSaaS manifesto: software cut for one business, owned by that
 * business, hosted like SaaS without the rent. This page is the canonical definition of the
 * term, so its URL should not move again: it was /neosaas until 2026-10-02 (the name was
 * changed), and controls/Neosaas.php still answers that URL with a permanent redirect for
 * the links clicksimple.com and the founder's posts already carry.
 *
 * PRIMARY site only, like Stories: instances replace Index with their own home page, so a
 * top-level marketing page gets its own controller. Public via the newsaas::* row in
 * seed 35_NewsaasRoute.
 */

namespace app;

use \Flight as Flight;

class Newsaas extends BaseControls\Control {

    public function index($params = []) {
        $this->render('index/newsaas', [
            'title' => 'Stop software overfitting — NewSaaS, software that fits you — tiknix',
        ], false);
    }
}
