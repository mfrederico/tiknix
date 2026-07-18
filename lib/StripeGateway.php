<?php
/**
 * StripeGateway — the instance-side client for reaching a connected Stripe account.
 *
 * Dual-driver, so the SAME app code works in the AI Builder and after the customer
 * "finally deploys" off-platform:
 *
 *   - 'broker'  (default, in-platform): calls the tiknix control-plane MCP gateway
 *               with this instance's broker key. The account secret NEVER lives here —
 *               it stays on the control plane, which decrypts and calls Stripe.
 *   - 'direct'  (off-platform): when STRIPE_SECRET_KEY is in the environment, the
 *               customer owns the runtime, so we call the Stripe API directly.
 *
 * Broker config (conf/broker.ini, or env fallback):
 *   [broker] endpoint = https://tiknix.com/mcp/message ; key = brk_...
 * Direct config (env): STRIPE_SECRET_KEY.
 *
 * Pick the account per environment: StripeGateway::forEnv('staging').
 */

namespace app;

class StripeGateway {

    private string $driver;
    private array $cfg;

    public function __construct(private string $environment = 'production') {
        $envKey = (string)getenv('STRIPE_SECRET_KEY');
        if ($envKey !== '') {
            $this->driver = 'direct';
            $this->cfg = ['secret' => $envKey];
        } else {
            $this->driver = 'broker';
            $this->cfg = self::brokerConfig();
        }
    }

    public static function forEnv(string $environment = 'production'): self {
        return new self($environment);
    }

    public function driver(): string { return $this->driver; }

    public function getAccount(): array { return $this->call('get_account', []); }
    public function listProducts(int $limit = 20): array { return $this->call('list_products', ['limit' => $limit]); }
    public function listPrices(int $limit = 20): array { return $this->call('list_prices', ['limit' => $limit]); }
    public function listCustomers(int $limit = 20): array { return $this->call('list_customers', ['limit' => $limit]); }
    public function createCustomer(array $args): array { return $this->call('create_customer', $args); }
    public function createCheckoutSession(array $args): array { return $this->call('create_checkout_session', $args); }
    public function listSubscriptions(array $args = []): array { return $this->call('list_subscriptions', $args); }

    private function call(string $tool, array $args): array {
        return $this->driver === 'direct' ? $this->direct($tool, $args) : $this->broker($tool, $args);
    }

    // --- broker (in-platform): reach the account THROUGH the control plane -------

    private function broker(string $tool, array $args): array {
        $endpoint = (string)($this->cfg['endpoint'] ?? '');
        $keyTok   = (string)($this->cfg['key'] ?? '');
        if ($endpoint === '' || $keyTok === '') {
            throw new \Exception('Broker not configured — expected conf/broker.ini [broker] endpoint + key.');
        }
        $args['environment'] = $this->environment;
        $payload = (string)json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => 'stripe:' . $tool, 'arguments' => $args],
        ]);
        [$status, $body] = self::httpPost($endpoint, $payload, ['Authorization: Bearer ' . $keyTok]);
        if ($status < 200 || $status >= 300) throw new \Exception('Broker HTTP ' . $status . '.');
        $j = json_decode($body, true);
        if (isset($j['error'])) throw new \Exception('Broker error: ' . ($j['error']['message'] ?? 'unknown'));
        $text = $j['result']['content'][0]['text'] ?? '';
        if (!empty($j['result']['isError'])) throw new \Exception('Account error: ' . $text);
        $data = json_decode((string)$text, true);
        return is_array($data) ? $data : [];
    }

    // --- direct (off-platform): customer owns the secret key --------------------

    private function direct(string $tool, array $args): array {
        $secret = (string)($this->cfg['secret'] ?? '');
        if ($secret === '') {
            throw new \Exception('STRIPE_SECRET_KEY not set for direct mode.');
        }
        $limit = max(1, min(100, (int)($args['limit'] ?? 20)));
        switch ($tool) {
            case 'get_account':
                return self::api($secret, 'GET', 'account');
            case 'list_products':
                return self::api($secret, 'GET', 'products?' . http_build_query(['active' => 'true', 'limit' => $limit]));
            case 'list_prices':
                return self::api($secret, 'GET', 'prices?' . http_build_query([
                    'active' => 'true', 'limit' => $limit, 'expand' => ['data.product'],
                ]));
            case 'list_customers':
                return self::api($secret, 'GET', 'customers?' . http_build_query(['limit' => $limit]));
            case 'create_customer':
                $f = [];
                if (!empty($args['email'])) $f['email'] = (string)$args['email'];
                if (!empty($args['name']))  $f['name']  = (string)$args['name'];
                if (!empty($args['metadata']) && is_array($args['metadata'])) $f['metadata'] = array_map('strval', $args['metadata']);
                return self::api($secret, 'POST', 'customers', $f);
            case 'create_checkout_session':
                return self::api($secret, 'POST', 'checkout/sessions', self::checkoutFields($args));
            case 'list_subscriptions':
                $set = ['all', 'active', 'trialing', 'past_due', 'canceled', 'unpaid', 'paused',
                        'incomplete', 'incomplete_expired', 'ended'];
                $status = strtolower(trim((string)($args['status'] ?? 'all')));
                $q = ['limit' => $limit, 'status' => in_array($status, $set, true) ? $status : 'all'];
                if (!empty($args['customer'])) $q['customer'] = (string)$args['customer'];
                return self::api($secret, 'GET', 'subscriptions?' . http_build_query($q));
            default:
                throw new \Exception('Unknown tool: ' . $tool);
        }
    }

    /** Validate + build Checkout session fields (bracket syntax via http_build_query). */
    private static function checkoutFields(array $args): array {
        $successUrl = trim((string)($args['success_url'] ?? ''));
        $cancelUrl  = trim((string)($args['cancel_url'] ?? ''));
        if ($successUrl === '') throw new \Exception('create_checkout_session requires a success_url.');
        if ($cancelUrl === '')  throw new \Exception('create_checkout_session requires a cancel_url.');
        $mode = (string)($args['mode'] ?? 'payment');
        if (!in_array($mode, ['payment', 'subscription'], true)) $mode = 'payment';
        $items = [];
        if (!empty($args['line_items']) && is_array($args['line_items'])) {
            foreach (array_values($args['line_items']) as $li) {
                if (!is_array($li) || empty($li['price'])) continue;
                $items[] = ['price' => (string)$li['price'], 'quantity' => max(1, (int)($li['quantity'] ?? 1))];
            }
        } elseif (!empty($args['price'])) {
            $items[] = ['price' => (string)$args['price'], 'quantity' => max(1, (int)($args['quantity'] ?? 1))];
        }
        if (empty($items)) throw new \Exception('create_checkout_session requires line_items (or a single price).');
        $f = ['mode' => $mode, 'success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'line_items' => $items];
        if (!empty($args['customer']))            $f['customer'] = (string)$args['customer'];
        if (!empty($args['client_reference_id'])) $f['client_reference_id'] = (string)$args['client_reference_id'];
        return $f;
    }

    /** Call the Stripe API directly with the customer-owned secret key. */
    private static function api(string $secret, string $method, string $path, array $fields = []): array {
        $headers = ['Authorization: Bearer ' . $secret, 'Accept: application/json'];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Idempotency-Key: ' . bin2hex(random_bytes(16));
            [$s, $body] = self::httpPost('https://api.stripe.com/v1/' . $path, http_build_query($fields), $headers, true);
        } else {
            [$s, $body] = self::httpGet('https://api.stripe.com/v1/' . $path, $headers);
        }
        if ($s < 200 || $s >= 300) throw new \Exception('Stripe HTTP ' . $s . '.');
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

    private static function httpPost(string $url, string $body, array $headers, bool $rawHeaders = false): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $rawHeaders
                ? $headers
                : array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
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
