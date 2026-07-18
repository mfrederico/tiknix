<?php
/**
 * Categories — public catalog (category) pages: tiknix.com/categories/<slug>/.
 *
 * Same model as the storefront: a standalone shell (NO app chrome) whose <base> is
 * /categories/. store.js (loaded from ../products/) fetches <slug>.json plus the
 * product manifest (../products/index.json) and renders a grid of that catalog's
 * products, all links relative. Auto-routed: /categories has no matching method per
 * slug, so the router's _fallback() hook hands us the <slug>. Public.
 */

namespace app;

use app\StoreCatalog;

class Categories {

    private function shell(string $slug): void {
        $store = json_encode(['category' => $slug, 'productsBase' => '../products/'], JSON_UNESCAPED_SLASHES);
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head>'
           . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<base href="/categories/">'
           . '<title>Shop</title>'
           . '<link rel="stylesheet" href="../products/store.css">'
           . '<script>window.__STORE__=' . $store . ';</script>'
           . '</head><body><div id="app">Loading…</div><script src="../products/store.js"></script></body></html>';
    }

    /** GET /categories — no catalog index; send shoppers to the full shop. */
    public function index($params = []): void {
        \Flight::redirect('/products/');
    }

    /** GET /categories/<slug> — the router routes the unknown <slug> segment here. */
    public function _fallback($slug, $params = []): void {
        $slug = StoreCatalog::normalizeSku((string)$slug);
        if ($slug === '') { \Flight::redirect('/products/'); return; }
        $this->shell($slug);
    }
}
