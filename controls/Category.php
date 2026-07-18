<?php
/** Category — /category alias for catalogs; redirects into /shop/catalog. Public. */

namespace app;

class Category {
    public function index($params = []): void { \Flight::redirect('/shop/catalog', 301); }
    public function _fallback($seg, $params = []): void {
        $uri = $_SERVER['REQUEST_URI'] ?? '/category';
        \Flight::redirect(preg_replace('~^/category\b~', '/shop/catalog', $uri), 301);
    }
}
