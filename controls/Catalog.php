<?php
/** Catalog — /catalog alias for catalogs; redirects into /shop/catalog. Public. */

namespace app;

class Catalog {
    public function index($params = []): void { \Flight::redirect('/shop/catalog', 301); }
    public function _fallback($seg, $params = []): void {
        $uri = $_SERVER['REQUEST_URI'] ?? '/catalog';
        \Flight::redirect(preg_replace('~^/catalog\b~', '/shop/catalog', $uri), 301);
    }
}
