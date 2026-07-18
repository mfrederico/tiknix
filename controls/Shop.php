<?php
/**
 * Shop — the front controller for the public storefront (all things ecommerce).
 *
 *   /shop                     all products (PLP)
 *   /shop/product            all products (PLP)
 *   /shop/product/<sku>      product page (PDP)
 *   /shop/catalog             all catalogs
 *   /shop/catalog/<slug>      one catalog
 *   /shop/category/<slug>     alias of /shop/catalog/<slug>
 *   /shop/<slug>              forgiving: resolves to a product OR a catalog
 *
 * Every route renders a tiny standalone shell (NO app chrome); the client-side
 * reassembler (/products/store.js) fetches the JSON catalog and renders. Data lives
 * at /products/*.json and /categories/*.json (committed, published with the site);
 * Shop is the URL + render layer. Auto-routed via defaultRoute; unknown sub-segments
 * (the <sku>/<slug>) arrive through the router's _fallback() hook. Public.
 */

namespace app;

use app\StoreCatalog;

class Shop {

    /** Emit the standalone storefront shell for a given view descriptor. */
    private function shell(array $store): void {
        $store = array_merge([
            'dataBase' => '/shop/product/', 'catBase' => '/shop/catalog/', 'shopBase' => '/shop/',
        ], $store);
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head>'
           . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Shop</title>'
           . '<link rel="stylesheet" href="/shop/store.css">'
           . '<script>window.__STORE__=' . json_encode($store, JSON_UNESCAPED_SLASHES) . ';</script>'
           . '</head><body><div id="app">Loading…</div><script src="/shop/store.js"></script></body></html>';
    }

    private function op($params): string {
        return StoreCatalog::normalizeSku((string)($params['operation']->name ?? ''));
    }

    private function store(): StoreCatalog {
        return new StoreCatalog(dirname(__DIR__) . '/public');
    }

    /** GET /shop — all products. */
    public function index($params = []): void {
        $this->shell(['view' => 'plp']);
    }

    /** GET /shop/product[/<sku>] — PLP, or a product PDP. */
    public function product($params = []): void {
        $sku = $this->op($params);
        $this->shell($sku !== '' ? ['view' => 'pdp', 'sku' => $sku] : ['view' => 'plp']);
    }

    /** GET /shop/catalog[/<slug>] — all catalogs, or one catalog. */
    public function catalog($params = []): void {
        $slug = $this->op($params);
        $this->shell($slug !== '' ? ['view' => 'category', 'category' => $slug] : ['view' => 'catalogs']);
    }

    /** GET /shop/category/<slug> — alias of /shop/catalog/<slug>. */
    public function category($params = []): void {
        $this->catalog($params);
    }

    /** GET /shop/<slug> — resolve a bare segment to a product or a catalog. */
    public function _fallback($seg, $params = []): void {
        $slug = StoreCatalog::normalizeSku((string)$seg);
        if ($slug === '') { \Flight::redirect('/shop/product'); return; }
        $cat = $this->store();
        if ($cat->getProduct($slug))      { $this->shell(['view' => 'pdp', 'sku' => $slug]); return; }
        if ($cat->getCategory($slug))     { $this->shell(['view' => 'category', 'category' => $slug]); return; }
        $this->shell(['view' => 'plp']);
    }
}
