<?php
/** Store — /store alias for the storefront; redirects into /shop. Public. */

namespace app;

class Store {
    public function index($params = []): void { \Flight::redirect('/shop', 301); }
    public function _fallback($seg, $params = []): void {
        $uri = $_SERVER['REQUEST_URI'] ?? '/store';
        \Flight::redirect(preg_replace('~^/store\b~', '/shop', $uri), 301);
    }
}
