<?php
/**
 * Products — legacy storefront path; the storefront now lives under /shop (Shop.php).
 * Redirects /products[/<sku>] to /shop/products[/<sku>]. The JSON catalog + assets
 * under public/products/ are still served as static files. Public.
 */

namespace app;

class Products {
    private function go(): void {
        $uri = $_SERVER['REQUEST_URI'] ?? '/products';
        \Flight::redirect(preg_replace('~^/products\b~', '/shop/product', $uri), 301);
    }
    public function index($params = []): void { $this->go(); }
    public function _fallback($seg, $params = []): void { $this->go(); }
}
