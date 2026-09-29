<?php
/**
 * SiteScoped — for a FUSE model whose bean a concept declared per-site (`scoped` in its
 * manifest): a new row is stamped with the current site before it is stored, so nothing a
 * concept creates is ever unowned. Reads go through Sites::filter(); this trait only covers
 * the write. A model with its own update() hook calls stampSite() itself.
 *
 *   class Model_Shoporder extends \RedBeanPHP\SimpleModel { use \app\SiteScoped; }
 */

namespace app;

trait SiteScoped {

    public function update() {
        $this->stampSite();
    }

    protected function stampSite(): void {
        Sites::stamp($this->bean);
    }
}
