<?php
/**
 * Shop — the cacheable front controller for the public storefront (all /shop URLs).
 *
 * index.php routes every /shop/* request here; nothing under /shop is a real file or
 * directory, so Shop owns it all. It serves BOTH the page shells and the catalog JSON
 * (read from shopdata/ via StoreCatalog), each with Cache-Control + ETag so browsers
 * and a CDN cache them.
 *
 *   /shop, /shop/product                     PLP shell
 *   /shop/product/<sku>                       PDP shell
 *   /shop/product/index.json                  product manifest (JSON)
 *   /shop/product/<sku>.json                  product data (JSON)
 *   /shop/catalog                             catalogs shell
 *   /shop/catalog/<slug>                       catalog shell
 *   /shop/catalog/index.json                   catalog manifest (JSON)
 *   /shop/catalog/<slug>.json                  catalog data (JSON)
 *   /shop/category/<slug>                      alias of /shop/catalog/<slug>
 *   /shop/<slug>                               resolves to a product OR a catalog
 *
 * Images are static at /shopmedia/…; JS/CSS static at /shopassets/…. Public (no auth).
 */

namespace app;

use app\StoreCatalog;

class Shop {

    private function store(): StoreCatalog {
        return new StoreCatalog(dirname(__DIR__));
    }

    /** Send a body with a short public cache + ETag; honour If-None-Match (304). */
    private function emit(string $body, string $type): void {
        $etag = '"' . md5($body) . '"';
        if (!headers_sent()) {
            header('Content-Type: ' . $type);
            header('Cache-Control: public, max-age=60');
            header('ETag: ' . $etag);
        }
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return; }
        echo $body;
    }

    private function json($data): void {
        $this->emit(json_encode($data ?? new \stdClass(), JSON_UNESCAPED_SLASHES), 'application/json; charset=utf-8');
    }

    private function notFound(): void {
        if (!headers_sent()) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); }
        echo '{"error":"not found"}';
    }

    /** Emit the standalone storefront shell (cacheable). */
    private function shell(array $store): void {
        $store = array_merge([
            'dataBase' => '/shop/product/', 'catBase' => '/shop/catalog/', 'shopBase' => '/shop/',
        ], $store);
        $this->emit(
            '<!doctype html><html lang="en"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Shop</title>'
            . '<link rel="stylesheet" href="/shopassets/store.css">'
            . '<script>window.__STORE__=' . json_encode($store, JSON_UNESCAPED_SLASHES) . ';</script>'
            . '</head><body><div id="app">Loading…</div><script src="/shopassets/store.js"></script></body></html>',
            'text/html; charset=utf-8'
        );
    }

    /** The raw last URL segment (before normalization) — needed to spot a ".json" request. */
    private function seg($params): string {
        return (string)($params['operation']->name ?? '');
    }

    /** GET /shop — all products. */
    public function index($params = []): void {
        $this->shell(['view' => 'plp']);
    }

    /** GET /shop/product[/<sku>] and /shop/product/*.json. */
    public function product($params = []): void {
        $seg = $this->seg($params);
        if ($seg === '') { $this->shell(['view' => 'plp']); return; }
        if (str_ends_with($seg, '.json')) {
            $name = substr($seg, 0, -5);
            if ($name === 'index') { $this->json($this->store()->manifest()); return; }
            $p = $this->store()->getProduct($name);
            $p ? $this->json($p) : $this->notFound();
            return;
        }
        $this->shell(['view' => 'pdp', 'sku' => StoreCatalog::normalizeSku($seg)]);
    }

    /** GET /shop/catalog[/<slug>] and /shop/catalog/*.json. */
    public function catalog($params = []): void {
        $seg = $this->seg($params);
        if ($seg === '') { $this->shell(['view' => 'catalogs']); return; }
        if (str_ends_with($seg, '.json')) {
            $name = substr($seg, 0, -5);
            if ($name === 'index') { $this->json($this->store()->categoryManifest()); return; }
            $c = $this->store()->getCategory($name);
            $c ? $this->json($c) : $this->notFound();
            return;
        }
        $this->shell(['view' => 'category', 'category' => StoreCatalog::normalizeSku($seg)]);
    }

    /** GET /shop/category/<slug> — alias of /shop/catalog/<slug>. */
    public function category($params = []): void {
        $this->catalog($params);
    }

    /** GET /shop/<slug> — resolve a bare segment to a product or a catalog. */
    public function _fallback($seg, $params = []): void {
        $slug = StoreCatalog::normalizeSku((string)$seg);
        if ($slug === '') { $this->shell(['view' => 'plp']); return; }
        $store = $this->store();
        if ($store->getProduct($slug))  { $this->shell(['view' => 'pdp', 'sku' => $slug]); return; }
        if ($store->getCategory($slug)) { $this->shell(['view' => 'category', 'category' => $slug]); return; }
        $this->shell(['view' => 'plp']);
    }
}
