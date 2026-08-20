<?php
/**
 * ShopifyConnector — Shopify OAuth.
 *
 * The access token returned here is a PERMANENT offline token (Shopify offline tokens do
 * not expire) and is always stored ENCRYPTED, sealed with the key of whichever install
 * holds it.
 *
 * Custody follows the APP, which is the whole point of appFor() on the base class. A store
 * connected through the shared platform app is held by the control plane and reached over
 * the MCP broker. A store connected through the MERCHANT'S OWN custom app is held by the
 * project itself: it is their credential, their callback on their own domain, and the
 * dance never touches core. Per-connection is the model; conf/shopify.ini is the fallback
 * for a merchant who has not made an app.
 */

namespace app\services\connectors;

class ShopifyConnector extends AbstractConnector {

    public function key(): string { return 'shopify'; }




    public function meta(): array {
        return [
            'label'     => 'Shopify',
            'auth_type' => 'oauth',
            'blurb'     => 'Connect a Shopify store to sync products, orders, and customers.',
            'category'  => 'Stores',
            'icon'      => 'bag-check',
            'color'     => 'success',
            'features'  => ['Products', 'Orders', 'Customers'],
        ];
    }

    public function apiVersion(): string {
        return (string)($this->oauth()['api_version'] ?? '2024-10');
    }

    /**
     * A Shopify store is ALWAYS reached through the merchant's own custom app.
     *
     * There is no shared tiknix app in this flow and conf/shopify.ini is not a fallback:
     * the merchant owns the app, the scopes, the billing and the callback on their own
     * project's domain. Saying so here rather than leaving a silent fallback is the
     * difference between "you need to paste a key" and a store quietly bound to an app
     * the merchant cannot see, revoke, or refresh.
     */
    public function requiresOwnApp(): bool { return true; }

    /** Shopify scopes are lowercase words with underscores — nothing else is real. */
    protected function scopePattern(): string { return '/^[a-z_]+$/'; }

    public function defaultScopes(): string {
        return (string)($this->oauth()['scopes'] ?? 'read_products,read_orders,read_customers');
    }

    /**
     * Normalize any shop input to a bare <name>.myshopify.com host, or '' if it is
     * not a valid myshopify store. Restricting to *.myshopify.com prevents an
     * open-redirect / SSRF to an attacker-chosen host during the OAuth dance.
     */
    public static function normalizeShopDomain(string $shop): string {
        $shop = strtolower(trim($shop));
        $shop = preg_replace('~^https?://~', '', $shop);
        $shop = explode('/', $shop)[0];
        if ($shop === '') return '';
        if (strpos($shop, '.') === false) $shop .= '.myshopify.com';
        if (!preg_match('~^[a-z0-9][a-z0-9-]*\.myshopify\.com$~', $shop)) return '';
        return $shop;
    }

    public function authorizeUrl(array $ctx): string {
        $shop = self::normalizeShopDomain((string)($ctx['shop'] ?? ''));
        if ($shop === '') throw new \Exception('A valid myshopify.com store domain is required.');
        $o = $this->appFor($ctx);   // the merchant's custom app, or the shared one
        $q = http_build_query([
            'client_id'       => (string)($o['client_id'] ?? ''),
            'scope'           => $this->scopesFor($ctx),
            'redirect_uri'    => (string)($ctx['redirect_uri'] ?? ''),
            'state'           => (string)($ctx['state'] ?? ''),
            'grant_options[]' => '', // offline (permanent) token
        ]);
        return 'https://' . $shop . '/admin/oauth/authorize?' . $q;
    }

    public function exchangeCode(array $ctx): array {
        $params = $ctx['params'] ?? [];
        $claims = $ctx['claims'] ?? [];
        // The SAME app the authorize leg used. The HMAC below is computed with this
        // secret, so taking it from the ini here while the redirect went out under a
        // merchant's client_id would fail verification every time.
        $o      = $this->appFor($ctx);
        $secret = (string)($o['client_secret'] ?? '');

        // 1) Verify Shopify's own HMAC over the callback query (provider authenticity).
        if (!self::verifyShopifyHmac($params, $secret)) {
            throw new \Exception('Shopify HMAC verification failed.');
        }
        // 2) The shop in the callback MUST equal the shop we signed into the state,
        //    so a token can't be re-bound to a different store than was authorized.
        $shopParam = self::normalizeShopDomain((string)($params['shop'] ?? ''));
        $shopState = self::normalizeShopDomain((string)($claims['shop'] ?? ''));
        if ($shopParam === '' || !hash_equals($shopState, $shopParam)) {
            throw new \Exception('Shop mismatch between callback and signed state.');
        }
        $code = (string)($params['code'] ?? '');
        if ($code === '') throw new \Exception('Missing authorization code.');

        // 3) Exchange the code for a permanent offline access token.
        [$status, $body] = $this->http('POST', 'https://' . $shopParam . '/admin/oauth/access_token', [
            'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            'body'    => http_build_query([
                'client_id'     => (string)($o['client_id'] ?? ''),
                'client_secret' => $secret,
                'code'          => $code,
            ]),
        ]);
        $j = json_decode($body, true);
        if ($status < 200 || $status >= 300 || empty($j['access_token'])) {
            throw new \Exception('Shopify token exchange failed (HTTP ' . $status . ').');
        }
        $token  = (string)$j['access_token'];
        // What Shopify actually GRANTED. Falls back to what we asked for on this attempt,
        // not to the server default — with a custom app those differ, and recording the
        // shared list against a store authorised under another app is simply wrong.
        $scopes = (string)($j['scope'] ?? $this->scopesFor($ctx));

        // 4) Best-effort: fetch the shop's display name.
        $name = $shopParam;
        [$s2, $b2] = $this->http('GET',
            'https://' . $shopParam . '/admin/api/' . $this->apiVersion() . '/shop.json',
            ['headers' => ['X-Shopify-Access-Token: ' . $token, 'Accept: application/json']]);
        if ($s2 >= 200 && $s2 < 300) {
            $sj = json_decode($b2, true);
            if (!empty($sj['shop']['name'])) $name = (string)$sj['shop']['name'];
        }

        return [
            'access_token'  => $token,
            'token_type'    => 'Bearer',
            'scopes'        => $scopes,
            'external_eid'  => $shopParam,
            'external_name' => $name,
            'external_url'  => 'https://' . $shopParam,
            'metadata'      => ['shop' => $shopParam, 'api_version' => $this->apiVersion()],
        ];
    }

    // --- Broker (read) tools --------------------------------------------------

    public function brokerTools(): array {
        return [
            [
                'name'        => 'get_shop',
                'description' => 'Fetch the connected Shopify store profile (name, domain, plan, currency).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name'        => 'get_products',
                'description' => 'List products from the connected Shopify store.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'limit'       => ['type' => 'integer', 'description' => 'Max products, 1-250 (default 20).'],
                    'environment' => ['type' => 'string', 'description' => 'Which connection: development|staging|production (default production).'],
                ]],
            ],
            [
                'name'        => 'get_orders',
                'description' => 'List recent orders from the connected Shopify store.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'limit'       => ['type' => 'integer', 'description' => 'Max orders, 1-250 (default 20).'],
                    'status'      => ['type' => 'string', 'description' => 'Filter: any|open|closed|cancelled (default any).'],
                    'environment' => ['type' => 'string', 'description' => 'Which connection: development|staging|production (default production).'],
                ]],
            ],
            [
                'name'        => 'graphql',
                'description' => 'Run any GraphQL query or mutation against the connected Shopify Admin API. The shop domain, API version, and auth are injected server-side.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query'       => ['type' => 'string', 'description' => 'The GraphQL query or mutation.'],
                    'variables'   => ['type' => 'object', 'description' => 'GraphQL variables (values may reference pipeline {context.x}).'],
                    'environment' => ['type' => 'string', 'description' => 'Which connection: development|staging|production (default production).'],
                ], 'required' => ['query']],
            ],
        ];
    }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        $shop = self::normalizeShopDomain((string)($conn->externalEid ?? ''));
        if ($shop === '') throw new \Exception('Connection has no valid store domain.');
        $meta  = json_decode((string)($conn->metadataJson ?: '{}'), true) ?: [];
        $ver   = (string)($meta['api_version'] ?? $this->apiVersion());
        $limit = max(1, min(250, (int)($args['limit'] ?? 20)));

        switch ($tool) {
            case 'get_shop':
                return $this->adminGet($shop, $ver, $token, 'shop.json');
            case 'get_products':
                return $this->adminGet($shop, $ver, $token, 'products.json?limit=' . $limit);
            case 'get_orders':
                $status = (string)($args['status'] ?? 'any');
                if (!in_array($status, ['any', 'open', 'closed', 'cancelled'], true)) $status = 'any';
                return $this->adminGet($shop, $ver, $token, 'orders.json?limit=' . $limit . '&status=' . $status);
            case 'graphql':
                return $this->adminGraphql($shop, $ver, $token, (string)($args['query'] ?? ''), $args['variables'] ?? null);
            default:
                throw new \Exception('Unknown Shopify broker tool: ' . $tool);
        }
    }

    /** POST a GraphQL query to the Shopify Admin GraphQL API with the store token. */
    private function adminGraphql(string $shop, string $ver, string $token, string $query, $variables): array {
        if (trim($query) === '') throw new \Exception('graphql: a query is required.');
        $payload = json_encode([
            'query'     => $query,
            'variables' => (is_array($variables) || is_object($variables)) ? $variables : new \stdClass(),
        ], JSON_UNESCAPED_SLASHES);
        [$status, $body] = $this->http('POST',
            'https://' . $shop . '/admin/api/' . $ver . '/graphql.json',
            ['headers' => ['X-Shopify-Access-Token: ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
             'body' => $payload]);
        if ($status === 401 || $status === 403) {
            throw new \Exception('Shopify rejected the token (HTTP ' . $status . ') — reconnect the store.');
        }
        $j = json_decode($body, true);
        // GraphQL returns 200 even for query errors (in the `errors` array) — surface the body either way.
        if ($status < 200 || $status >= 300) throw new \Exception('Shopify GraphQL error (HTTP ' . $status . ').');
        return is_array($j) ? $j : ['raw' => $body];
    }

    /** GET the Shopify Admin REST API with the store token; decode to an array. */
    private function adminGet(string $shop, string $ver, string $token, string $path): array {
        [$status, $body] = $this->http('GET',
            'https://' . $shop . '/admin/api/' . $ver . '/' . $path,
            ['headers' => ['X-Shopify-Access-Token: ' . $token, 'Accept: application/json']]);
        if ($status === 401 || $status === 403) {
            throw new \Exception('Shopify rejected the token (HTTP ' . $status . ') — reconnect the store.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \Exception('Shopify API error (HTTP ' . $status . ').');
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : [];
    }

    /**
     * Verify the `hmac` query param Shopify appends to the callback: drop hmac +
     * signature, sort the rest by key, join as k=v&..., HMAC-SHA256 with the app
     * client_secret.
     */
    public static function verifyShopifyHmac(array $params, string $secret): bool {
        if ($secret === '' || empty($params['hmac'])) return false;
        $provided = (string)$params['hmac'];
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'hmac' || $k === 'signature') continue;
            if (is_array($v)) continue;
            $pairs[$k] = $k . '=' . $v;
        }
        ksort($pairs);
        $computed = hash_hmac('sha256', implode('&', $pairs), $secret);
        return hash_equals($computed, $provided);
    }
}
