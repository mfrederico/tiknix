<?php
/**
 * Categories — legacy storefront path; catalogs now live under /shop/catalog.
 * Redirects /categories[/<slug>] to /shop/catalog[/<slug>]. Public.
 */

namespace app;

class Categories {
    public function index($params = []): void { \Flight::redirect('/shop/catalog', 301); }
    public function _fallback($seg, $params = []): void {
        $uri = $_SERVER['REQUEST_URI'] ?? '/categories';
        \Flight::redirect(preg_replace('~^/categories\b~', '/shop/catalog', $uri), 301);
    }
}
