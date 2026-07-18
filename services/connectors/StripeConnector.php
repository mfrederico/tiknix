<?php
/**
 * StripeConnector — Stripe account connection (control-plane custody).
 *
 * Primary auth is a PASTED, VALIDATED secret/restricted API key (sk_/rk_): the
 * builder creates a restricted key in the Stripe Dashboard and pastes it once;
 * validateApiKey() proves it against GET /v1/account before anything persists.
 * The Connect (Standard) OAuth methods below are kept dormant so OAuth stays a
 * drop-in future option (flip meta()['auth_type'] back to 'oauth').
 *
 * Either way the credential is stored ENCRYPTED in the connections table on the
 * control plane and is never written into a builder instance — instances reach
 * Stripe only through the MCP broker. The PUBLISHABLE key (pk_...) is public by
 * design and may travel in metadata for instance frontends.
 */

namespace app\services\connectors;

class StripeConnector extends AbstractConnector {

    public function key(): string { return 'stripe'; }

    public function meta(): array {
        return [
            'label'     => 'Stripe',
            'auth_type' => 'api_key',
            'blurb'     => 'Paste a Stripe secret or restricted key to take payments, manage customers, and sell subscriptions.',
        ];
    }

    /**
     * API-key mode needs NO platform-side credentials (no client_id/secret) — the
     * connector is usable out of the box, so the UI's connect form is never gated.
     */
    public function isConfigured(): bool {
        return true;
    }

    /**
     * Validate a pasted secret (sk_) or restricted (rk_) key against Stripe and
     * normalize it into the exchangeCode() payload shape. The key itself never
     * appears in any error message.
     */
    public function validateApiKey(string $key): array {
        $key = trim($key);
        if ($key === '') throw new \Exception('A Stripe secret or restricted key is required.');

        [$status, $body] = $this->http('GET', 'https://api.stripe.com/v1/account',
            ['headers' => ['Authorization: Bearer ' . $key, 'Accept: application/json']]);
        if ($status === 401 || $status === 403) {
            throw new \Exception('Stripe rejected that key — check it is a valid secret (sk_) or restricted (rk_) key.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \Exception('Stripe key validation failed (HTTP ' . $status . ').');
        }
        $aj   = json_decode($body, true) ?: [];
        $acct = (string)($aj['id'] ?? '');
        $name = (string)($aj['business_profile']['name']
            ?? $aj['settings']['dashboard']['display_name']
            ?? $aj['email'] ?? '');
        if ($name === '') $name = $acct;

        return [
            'access_token'  => $key,
            'token_type'    => 'Bearer',
            'scopes'        => 'api_key',
            'external_eid'  => $acct,
            'external_name' => $name,
            'external_url'  => 'https://dashboard.stripe.com/' . $acct,
            'metadata'      => [
                'stripe_user_id'  => $acct,
                'livemode'        => strpos($key, '_live_') !== false,
                // Not derivable from a secret key and not needed — Checkout is a
                // hosted redirect. Public-by-design when present via OAuth.
                'publishable_key' => '',
            ],
        ];
    }

    public function defaultScopes(): string {
        return (string)($this->oauth()['scope'] ?? 'read_write');
    }

    public function authorizeUrl(array $ctx): string {
        // Stripe Connect has no per-store domain (unlike Shopify) — ctx['shop'] is ignored.
        $o = $this->oauth();
        $q = http_build_query([
            'response_type' => 'code',
            'client_id'     => (string)($o['client_id'] ?? ''),
            'scope'         => (string)($ctx['scopes'] ?? $this->defaultScopes()),
            'state'         => (string)($ctx['state'] ?? ''),
            'redirect_uri'  => (string)($ctx['redirect_uri'] ?? ''),
        ]);
        return 'https://connect.stripe.com/oauth/authorize?' . $q;
    }

    public function exchangeCode(array $ctx): array {
        // The controller has already verified the signed state + CSRF before calling us.
        $params = $ctx['params'] ?? [];
        $o      = $this->oauth();

        if (!empty($params['error'])) {
            throw new \Exception('Stripe authorization failed: '
                . (string)($params['error_description'] ?? $params['error']));
        }
        $code = (string)($params['code'] ?? '');
        if ($code === '') throw new \Exception('Missing authorization code.');

        // 1) Exchange the code for the connected account's permanent access token.
        [$status, $body] = $this->http('POST', 'https://connect.stripe.com/oauth/token', [
            'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            'body'    => http_build_query([
                'client_secret' => (string)($o['client_secret'] ?? ''),
                'code'          => $code,
                'grant_type'    => 'authorization_code',
            ]),
        ]);
        $j = json_decode($body, true);
        if ($status < 200 || $status >= 300 || empty($j['stripe_user_id']) || empty($j['access_token'])) {
            throw new \Exception('Stripe token exchange failed (HTTP ' . $status . ').');
        }
        $token  = (string)$j['access_token'];
        $acct   = (string)$j['stripe_user_id'];
        $scopes = (string)($j['scope'] ?? 'read_write');

        // 2) Best-effort: fetch the account's display name.
        $name = $acct;
        [$s2, $b2] = $this->http('GET', 'https://api.stripe.com/v1/account',
            ['headers' => ['Authorization: Bearer ' . $token, 'Accept: application/json']]);
        if ($s2 >= 200 && $s2 < 300) {
            $aj = json_decode($b2, true) ?: [];
            $candidate = (string)($aj['business_profile']['name']
                ?? $aj['settings']['dashboard']['display_name']
                ?? $aj['email'] ?? '');
            if ($candidate !== '') $name = $candidate;
        }

        return [
            'access_token'  => $token,
            'token_type'    => 'Bearer',
            'scopes'        => $scopes,
            'external_eid'  => $acct,
            'external_name' => $name,
            'external_url'  => 'https://dashboard.stripe.com/' . $acct,
            'metadata'      => [
                'stripe_user_id'  => $acct,
                // The publishable key is PUBLIC by design — instance frontends use it.
                'publishable_key' => (string)($j['stripe_publishable_key'] ?? ''),
                'livemode'        => (bool)($j['livemode'] ?? false),
                'scope'           => $scopes,
            ],
        ];
    }

    // --- Broker tools ---------------------------------------------------------

    public function brokerTools(): array {
        $envProp = ['type' => 'string', 'description' => 'Which connection: development|staging|production (default production).'];
        return [
            [
                'name'        => 'get_account',
                'description' => 'Fetch the connected Stripe account profile (name, email, capabilities).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'environment' => $envProp,
                ]],
            ],
            [
                'name'        => 'list_products',
                'description' => 'List active products from the connected Stripe account.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'limit'       => ['type' => 'integer', 'description' => 'Max products, 1-100 (default 20).'],
                    'environment' => $envProp,
                ]],
            ],
            [
                'name'        => 'list_prices',
                'description' => 'List active prices (with their products expanded) from the connected Stripe account.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'limit'       => ['type' => 'integer', 'description' => 'Max prices, 1-100 (default 20).'],
                    'environment' => $envProp,
                ]],
            ],
            [
                'name'        => 'list_customers',
                'description' => 'List customers from the connected Stripe account.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'limit'       => ['type' => 'integer', 'description' => 'Max customers, 1-100 (default 20).'],
                    'environment' => $envProp,
                ]],
            ],
            [
                'name'        => 'create_customer',
                'description' => 'Create a customer on the connected Stripe account.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'email'       => ['type' => 'string', 'description' => 'Customer email address.'],
                    'name'        => ['type' => 'string', 'description' => 'Customer full name.'],
                    'metadata'    => ['type' => 'object', 'description' => 'Key/value metadata to attach.'],
                    'environment' => $envProp,
                ]],
            ],
            [
                'name'        => 'create_checkout_session',
                'description' => 'Create a Stripe Checkout session; redirect the buyer to the returned url.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'mode'                => ['type' => 'string', 'description' => 'payment|subscription (default payment).'],
                    'line_items'          => ['type' => 'array', 'description' => 'Items: [{price: price_..., quantity: 1}, ...].'],
                    'price'               => ['type' => 'string', 'description' => 'Single price id (alternative to line_items).'],
                    'quantity'            => ['type' => 'integer', 'description' => 'Quantity for the single price (default 1).'],
                    'success_url'         => ['type' => 'string', 'description' => 'Where Stripe sends the buyer after payment (required).'],
                    'cancel_url'          => ['type' => 'string', 'description' => 'Where Stripe sends the buyer on cancel (required).'],
                    'customer'            => ['type' => 'string', 'description' => 'Existing customer id (optional).'],
                    'client_reference_id' => ['type' => 'string', 'description' => 'Your own reference id for the session (optional).'],
                    'environment'         => $envProp,
                ], 'required' => ['success_url', 'cancel_url']],
            ],
            [
                'name'        => 'list_subscriptions',
                'description' => 'List subscriptions (memberships) from the connected Stripe account.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'limit'       => ['type' => 'integer', 'description' => 'Max subscriptions, 1-100 (default 20).'],
                    'status'      => ['type' => 'string', 'description' => 'Filter: all|active|trialing|past_due|canceled|unpaid|paused|incomplete|incomplete_expired|ended (default all).'],
                    'customer'    => ['type' => 'string', 'description' => 'Limit to one customer id (optional).'],
                    'environment' => $envProp,
                ]],
            ],
        ];
    }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        $limit = max(1, min(100, (int)($args['limit'] ?? 20)));

        switch ($tool) {
            case 'get_account':
                return $this->apiGet($token, 'account');
            case 'list_products':
                return $this->apiGet($token, 'products?' . http_build_query(['active' => 'true', 'limit' => $limit]));
            case 'list_prices':
                return $this->apiGet($token, 'prices?' . http_build_query([
                    'active' => 'true', 'limit' => $limit, 'expand' => ['data.product'],
                ]));
            case 'list_customers':
                return $this->apiGet($token, 'customers?' . http_build_query(['limit' => $limit]));
            case 'create_customer':
                return $this->apiPost($token, 'customers', $this->customerFields($args));
            case 'create_checkout_session':
                return $this->apiPost($token, 'checkout/sessions', $this->checkoutSessionFields($args));
            case 'list_subscriptions':
                $q = ['limit' => $limit, 'status' => $this->normalizeSubscriptionStatus($args['status'] ?? 'all')];
                if (!empty($args['customer'])) $q['customer'] = (string)$args['customer'];
                return $this->apiGet($token, 'subscriptions?' . http_build_query($q));
            default:
                throw new \Exception('Unknown Stripe broker tool: ' . $tool);
        }
    }

    /** Fields for POST /v1/customers. */
    private function customerFields(array $args): array {
        $f = [];
        if (!empty($args['email'])) $f['email'] = (string)$args['email'];
        if (!empty($args['name']))  $f['name']  = (string)$args['name'];
        if (!empty($args['metadata']) && is_array($args['metadata'])) {
            $f['metadata'] = array_map('strval', $args['metadata']);
        }
        return $f;
    }

    /**
     * Validate + build fields for POST /v1/checkout/sessions. Nested arrays are
     * form-encoded by http_build_query into Stripe's bracket syntax, e.g.
     * line_items[0][price]=price_..&line_items[0][quantity]=1.
     */
    private function checkoutSessionFields(array $args): array {
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

        $f = [
            'mode'        => $mode,
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'line_items'  => $items,
        ];
        if (!empty($args['customer']))            $f['customer'] = (string)$args['customer'];
        if (!empty($args['client_reference_id'])) $f['client_reference_id'] = (string)$args['client_reference_id'];
        return $f;
    }

    /** Constrain a subscription status filter to Stripe's set; default 'all'. */
    private function normalizeSubscriptionStatus($status): string {
        $set = ['all', 'active', 'trialing', 'past_due', 'canceled', 'unpaid', 'paused',
                'incomplete', 'incomplete_expired', 'ended'];
        $status = strtolower(trim((string)$status));
        return in_array($status, $set, true) ? $status : 'all';
    }

    /** GET the Stripe API with the account token; decode to an array. */
    private function apiGet(string $token, string $path): array {
        [$status, $body] = $this->http('GET', 'https://api.stripe.com/v1/' . $path,
            ['headers' => ['Authorization: Bearer ' . $token, 'Accept: application/json']]);
        return $this->decodeOrThrow($status, $body);
    }

    /** POST the Stripe API (form-encoded, idempotent); decode to an array. */
    private function apiPost(string $token, string $path, array $fields): array {
        [$status, $body] = $this->http('POST', 'https://api.stripe.com/v1/' . $path, [
            'headers' => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Idempotency-Key: ' . bin2hex(random_bytes(16)),
            ],
            'body' => http_build_query($fields),
        ]);
        return $this->decodeOrThrow($status, $body);
    }

    /** Shared Stripe response handling — errors never include the token. */
    private function decodeOrThrow(int $status, string $body): array {
        if ($status === 401 || $status === 403) {
            throw new \Exception('Stripe rejected the credentials (HTTP ' . $status . ') — reconnect the account.');
        }
        $j = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $msg = (is_array($j) && !empty($j['error']['message'])) ? ': ' . (string)$j['error']['message'] : '.';
            throw new \Exception('Stripe API error (HTTP ' . $status . ')' . $msg);
        }
        return is_array($j) ? $j : [];
    }
}
