<?php
/**
 * Products — the public storefront (tiknix.com/products/…).
 *
 * Serves a tiny standalone shell (NO app chrome) whose <base> is /products/; the
 * client-side reassembler (public/products/store.js) fetches the JSON that lives
 * under public/products/ and renders the PLP (/products/) or a PDP (/products/<sku>/).
 *
 * Auto-routed: /products -> index() (PLP). /products/<sku> has no matching method,
 * so the router's _fallback() hook (lib/FlightMap.php) hands us the <sku> segment
 * and we render its PDP. Public (no authcontrol row -> default PUBLIC). The catalog
 * JSON is committed to the repo, so it publishes with the site.
 */

namespace app;

use app\StoreCatalog;

class Products {

    /** Emit the standalone storefront shell; store.js renders from the JSON. */
    private function shell(?string $sku): void {
        $store = json_encode(['sku' => $sku], JSON_UNESCAPED_SLASHES);
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head>'
           . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<base href="/products/">'
           . '<title>Shop</title>'
           . '<link rel="stylesheet" href="store.css">'
           . '<script>window.__STORE__=' . $store . ';</script>'
           . '</head><body><div id="app">Loading…</div><script src="store.js"></script></body></html>';
    }

    /** GET /products — product listing (PLP). */
    public function index($params = []): void {
        $this->shell(null);
    }

    /** GET /products/<sku> — the router routes the unknown <sku> segment here. */
    public function _fallback($sku, $params = []): void {
        $this->shell(StoreCatalog::normalizeSku((string)$sku));
    }
}
