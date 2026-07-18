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
use app\Bean;
use app\EncryptionService;
use app\services\connectors\ConnectorRegistry;

class Shop {

    private function baseUrl(): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tiknix.com');
    }

    /** The store's configured payment connection + its connector, or [null,null]. */
    private function resolvePayment(): array {
        $sys      = defined('SYSTEM_ADMIN_ID') ? SYSTEM_ADMIN_ID : 1;
        $memberId = (int)\Flight::getSetting('shop.payment_member', $sys);
        $instId   = (int)\Flight::getSetting('shop.payment_instance', $sys);
        $env      = (string)(\Flight::getSetting('shop.payment_env', $sys) ?: 'production');
        if ($memberId <= 0 || $instId <= 0) return [null, null];
        foreach (ConnectorRegistry::all() as $c) {
            if (($c->meta()['category'] ?? '') !== 'Payments') continue;
            $conn = Bean::findOne('connections',
                'member_id = ? AND instance_id = ? AND environment = ? AND connector_type = ? AND enabled = 1',
                [$memberId, $instId, $env, $c->key()]);
            if ($conn && $conn->id && empty($conn->revokedAt)) return [$conn, $c];
        }
        return [null, null];
    }

    private function paymentConfigured(): bool {
        [$conn] = $this->resolvePayment();
        return (bool)$conn;
    }

    /**
     * POST /shop/checkout — Buy Now for one product. Resolves the store's payment
     * provider, creates a hosted checkout session server-side (price from the catalog,
     * never the client), and redirects the buyer to it. Public.
     */
    public function checkout($params = []): void {
        $sku = StoreCatalog::normalizeSku((string)($_POST['sku'] ?? $_GET['sku'] ?? ''));
        $qty = max(1, min(99, (int)($_POST['qty'] ?? 1)));
        $product = $sku !== '' ? $this->store()->getProduct($sku) : null;
        if (!$product || empty($product['active'])) { \Flight::redirect('/shop/product/'); return; }

        [$conn, $connector] = $this->resolvePayment();
        if (!$conn || !$connector) { \Flight::redirect('/shop/product/' . rawurlencode($sku) . '/?err=nopay'); return; }

        $base  = $this->baseUrl();
        $token = EncryptionService::decrypt($conn->accessToken);
        $url = '';
        try {
            $res = $connector->createCheckout($conn, $token, [
                'items' => [[
                    'title'        => (string)$product['title'],
                    'amount_cents' => (int)round(((float)($product['price'] ?? 0)) * 100),
                    'currency'     => (string)($product['currency'] ?? 'usd'),
                    'quantity'     => $qty,
                ]],
                'success_url'         => $base . '/shop/success?sku=' . rawurlencode($sku),
                'cancel_url'          => $base . '/shop/product/' . rawurlencode($sku) . '/',
                'client_reference_id' => $sku,
            ]);
            $url = (string)($res['url'] ?? '');
        } catch (\Throwable $e) {
            error_log('[shop] checkout failed: ' . $e->getMessage());
        } finally {
            if (function_exists('sodium_memzero')) sodium_memzero($token);
        }
        \Flight::redirect($url !== '' ? $url : '/shop/product/' . rawurlencode($sku) . '/?err=checkout');
    }

    /** GET /shop/success — post-payment thank-you (buyer returns here). */
    public function success($params = []): void {
        $this->shell(['view' => 'success']);
    }

    /**
     * POST /shop/webhook/<provider> — payment provider webhooks. Verifies the order by
     * re-fetching it from the provider (via the connector), records it once, and always
     * answers 200 so the provider stops retrying. Public (self-verifying).
     */
    public function webhook($params = []): void {
        $provider = StoreCatalog::normalizeSku((string)($params['operation']->name ?? ''));
        $raw = file_get_contents('php://input') ?: '';
        [$conn, $connector] = $this->resolvePayment();
        if (!$conn || !$connector || $connector->key() !== $provider) { $this->plain(200, 'ignored'); return; }

        // Webhook verification secret lives ON the connection (encrypted); the connector
        // interprets it per-provider (Stripe whsec HMAC, Square key, PayPal webhook id).
        $secret = '';
        $enc = (string)($conn->webhookSecret ?? '');
        if ($enc !== '') { try { $secret = EncryptionService::decrypt($enc); } catch (\Throwable $e) { $secret = ''; } }
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        // getallheaders() is unreliable/absent on nginx+fpm — read the signature from
        // $_SERVER too so real (signed) webhooks are always verifiable.
        if (empty($headers['Stripe-Signature']) && !empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $headers['Stripe-Signature'] = (string)$_SERVER['HTTP_STRIPE_SIGNATURE'];
        }
        $token = EncryptionService::decrypt($conn->accessToken);
        try {
            $order = $connector->webhookOrder($conn, $token, $raw, $headers, $secret);
            if ($order && !empty($order['session_id'])) $this->recordOrder($provider, $conn, $order);
        } catch (\Throwable $e) {
            error_log('[shop] webhook rejected: ' . $e->getMessage());
            if (function_exists('sodium_memzero')) { sodium_memzero($token); if ($secret !== '') sodium_memzero($secret); }
            $this->plain(400, 'rejected'); return;   // signature / fetch failure — provider will show + retry
        }
        if (function_exists('sodium_memzero')) { sodium_memzero($token); if ($secret !== '') sodium_memzero($secret); }
        $this->plain(200, 'ok');
    }

    private function plain(int $code, string $body): void {
        \Flight::halt($code, $body);   // Flight emits its own 200 at request end; halt sets the real status.
    }

    /** Record a confirmed order once (idempotent on the provider session id). */
    private function recordOrder(string $provider, $conn, array $order): void {
        if (Bean::findOne('shoporder', 'session_id = ?', [(string)$order['session_id']])) return;
        $sku     = StoreCatalog::normalizeSku((string)($order['reference'] ?? ''));
        $product = $sku !== '' ? $this->store()->getProduct($sku) : null;
        $o = Bean::dispense('shoporder');
        $o->provider     = $provider;
        $o->environment  = (string)($conn->environment ?: 'production');
        $o->sessionId    = (string)$order['session_id'];
        $o->paymentId    = (string)($order['payment_intent'] ?? '');
        $o->sku          = $sku;
        $o->title        = (string)($product['title'] ?? $sku);
        $o->amountTotal  = (int)($order['amount_total'] ?? 0);
        $o->currency     = (string)($order['currency'] ?? 'usd');
        $o->email        = (string)($order['email'] ?? '');
        $o->customerName = (string)($order['name'] ?? '');
        $o->status       = 'paid';
        $o->memberId     = (int)($conn->memberId ?? 0);
        $o->instanceId   = (int)($conn->instanceId ?? 0);
        $o->createdAt    = date('Y-m-d H:i:s');
        Bean::store($o);
    }

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
        // Flight sends its own 200 at shutdown, so end the request ourselves on a match.
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { \Flight::halt(304); }
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
        // Only the product page needs to know whether checkout is live (one DB check).
        if (($store['view'] ?? '') === 'pdp') $store['checkout'] = $this->paymentConfigured();
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
