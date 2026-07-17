<?php
/**
 * ShopifyConnector — Shopify OAuth (control-plane custody).
 *
 * The access token returned here is a PERMANENT offline token (Shopify offline
 * tokens do not expire). It is stored ENCRYPTED in the connections table on the
 * control plane and is never written into a builder instance — instances reach
 * Shopify only through the MCP broker.
 */

namespace app\services\connectors;

class ShopifyConnector extends AbstractConnector {

    public function key(): string { return 'shopify'; }

    public function meta(): array {
        return [
            'label'     => 'Shopify',
            'auth_type' => 'oauth',
            'blurb'     => 'Connect a Shopify store to sync products, orders, and customers.',
        ];
    }

    public function apiVersion(): string {
        return (string)($this->oauth()['api_version'] ?? '2024-10');
    }

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
        $o = $this->oauth();
        $q = http_build_query([
            'client_id'       => (string)($o['client_id'] ?? ''),
            'scope'           => (string)($ctx['scopes'] ?? $this->defaultScopes()),
            'redirect_uri'    => (string)($ctx['redirect_uri'] ?? ''),
            'state'           => (string)($ctx['state'] ?? ''),
            'grant_options[]' => '', // offline (permanent) token
        ]);
        return 'https://' . $shop . '/admin/oauth/authorize?' . $q;
    }

    public function exchangeCode(array $ctx): array {
        $params = $ctx['params'] ?? [];
        $claims = $ctx['claims'] ?? [];
        $o      = $this->oauth();
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
        $scopes = (string)($j['scope'] ?? $this->defaultScopes());

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
