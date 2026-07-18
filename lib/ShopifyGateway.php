<?php
/**
 * ShopifyGateway — the instance-side client for reaching a connected Shopify store.
 *
 * Dual-driver, so the SAME app code works in the AI Builder and after the customer
 * "finally deploys" off-platform:
 *
 *   - 'broker'  (default, in-platform): calls the tiknix control-plane MCP gateway
 *               with this instance's broker key. The store token NEVER lives here —
 *               it stays on the control plane, which decrypts and calls Shopify.
 *   - 'direct'  (off-platform): when SHOPIFY_ACCESS_TOKEN is in the environment, the
 *               customer owns the runtime, so we call the Shopify Admin API directly.
 *
 * Broker config (conf/broker.ini, or env fallback):
 *   [broker] endpoint = https://tiknix.com/mcp/message ; key = brk_...
 * Direct config (env): SHOPIFY_SHOP, SHOPIFY_ACCESS_TOKEN, SHOPIFY_API_VERSION.
 *
 * Pick the store per environment: ShopifyGateway::forEnv('staging').
 */

namespace app;

class ShopifyGateway {

    private string $driver;
    private array $cfg;

    public function __construct(private string $environment = 'production') {
        $envToken = (string)getenv('SHOPIFY_ACCESS_TOKEN');
        if ($envToken !== '') {
            $this->driver = 'direct';
            $this->cfg = [
                'shop'  => (string)getenv('SHOPIFY_SHOP'),
                'token' => $envToken,
                'ver'   => (string)(getenv('SHOPIFY_API_VERSION') ?: '2024-10'),
            ];
        } else {
            $this->driver = 'broker';
            $this->cfg = self::brokerConfig();
        }
    }

    public static function forEnv(string $environment = 'production'): self {
        return new self($environment);
    }

    public function driver(): string { return $this->driver; }

    public function getShop(): array { return $this->call('get_shop', []); }
    public function getProducts(int $limit = 20): array { return $this->call('get_products', ['limit' => $limit]); }
    public function getOrders(int $limit = 20, string $status = 'any'): array {
        return $this->call('get_orders', ['limit' => $limit, 'status' => $status]);
    }

    private function call(string $tool, array $args): array {
        return $this->driver === 'direct' ? $this->direct($tool, $args) : $this->broker($tool, $args);
    }

    // --- broker (in-platform): reach the store THROUGH the control plane ---------

    private function broker(string $tool, array $args): array {
        $endpoint = (string)($this->cfg['endpoint'] ?? '');
        $keyTok   = (string)($this->cfg['key'] ?? '');
        if ($endpoint === '' || $keyTok === '') {
            throw new \Exception('Broker not configured — expected conf/broker.ini [broker] endpoint + key.');
        }
        $args['environment'] = $this->environment;
        $payload = (string)json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => 'shopify:' . $tool, 'arguments' => $args],
        ]);
        [$status, $body] = self::httpPost($endpoint, $payload, ['Authorization: Bearer ' . $keyTok]);
        if ($status < 200 || $status >= 300) throw new \Exception('Broker HTTP ' . $status . '.');
        $j = json_decode($body, true);
        if (isset($j['error'])) throw new \Exception('Broker error: ' . ($j['error']['message'] ?? 'unknown'));
        $text = $j['result']['content'][0]['text'] ?? '';
        if (!empty($j['result']['isError'])) throw new \Exception('Store error: ' . $text);
        $data = json_decode((string)$text, true);
        return is_array($data) ? $data : [];
    }

    // --- direct (off-platform): customer owns the token ------------------------

    private function direct(string $tool, array $args): array {
        $shop = (string)($this->cfg['shop'] ?? '');
        $ver  = (string)($this->cfg['ver'] ?? '2024-10');
        $token = (string)($this->cfg['token'] ?? '');
        if ($shop === '' || $token === '') {
            throw new \Exception('SHOPIFY_SHOP / SHOPIFY_ACCESS_TOKEN not set for direct mode.');
        }
        $limit  = max(1, min(250, (int)($args['limit'] ?? 20)));
        $status = (string)($args['status'] ?? 'any');
        if (!in_array($status, ['any', 'open', 'closed', 'cancelled'], true)) $status = 'any';
        switch ($tool) {
            case 'get_shop':     $path = 'shop.json'; break;
            case 'get_products': $path = 'products.json?limit=' . $limit; break;
            case 'get_orders':   $path = 'orders.json?limit=' . $limit . '&status=' . $status; break;
            default: throw new \Exception('Unknown tool: ' . $tool);
        }
        [$s, $body] = self::httpGet('https://' . $shop . '/admin/api/' . $ver . '/' . $path,
            ['X-Shopify-Access-Token: ' . $token, 'Accept: application/json']);
        if ($s < 200 || $s >= 300) throw new \Exception('Shopify HTTP ' . $s . '.');
        $j = json_decode($body, true);
        return is_array($j) ? $j : [];
    }

    private static function brokerConfig(): array {
        $ini = @parse_ini_file(dirname(__DIR__) . '/conf/broker.ini', true) ?: [];
        $b = $ini['broker'] ?? [];
        return [
            'endpoint' => (string)($b['endpoint'] ?? (getenv('TIKNIX_BROKER_ENDPOINT') ?: '')),
            'key'      => (string)($b['key'] ?? (getenv('TIKNIX_BROKER_KEY') ?: '')),
        ];
    }

    private static function httpPost(string $url, string $body, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$status, is_string($resp) ? $resp : ''];
    }

    private static function httpGet(string $url, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$status, is_string($resp) ? $resp : ''];
    }
}
