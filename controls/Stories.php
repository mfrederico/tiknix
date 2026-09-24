<?php
/**
 * Stories — /stories, the founder stories page: the long form of the landing's founder
 * section (who built what on tiknix, what they started with, what it does now).
 *
 * PRIMARY site only: on a provisioned instance it redirects to "/", like pricing. The page
 * lives here rather than on Index because instances replace Index with their own home
 * page; a /stories that needed Index::stories() would break on every one of them.
 * Public via the stories::* row in 02_AuthControl. Stories are showcase entries carrying
 * a story (scripts/seed-showcase.php).
 */

namespace app;

use \Flight as Flight;

class Stories extends BaseControls\Control {

    public function index($params = []) {
        if (!is_control_plane()) { Flight::redirect('/'); return; }
        $this->render('index/stories', [
            'title'   => 'Founder stories — tiknix',
            'stories' => \Model_Showcase::stories(Bean::find('showcase', 'enabled = 1 ORDER BY sort_order ASC, id ASC')),
        ], false);
    }
}
