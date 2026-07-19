<?php
/**
 * ConnectorInterface — the contract every third-party connector implements.
 *
 * A connector encapsulates ONE provider's OAuth handshake and identity. It runs
 * only on the control plane. The access token it produces is stored encrypted in
 * the connections table and is never handed to a builder instance.
 */

namespace app\services\connectors;

interface ConnectorInterface {

    /**
     * Stable lowercase key, e.g. 'shopify'. Doubles as connections.connector_type
     * and the /connections/connect/<key> operation segment.
     */
    public function key(): string;

    /** Display metadata: ['label' => ..., 'auth_type' => 'oauth'|'api_key', 'blurb' => ...]. */
    public function meta(): array;

    /** True when this host has the app-level credentials (client_id/secret) present. */
    public function isConfigured(): bool;

    /**
     * Build the provider authorization URL to redirect the builder to.
     *
     * @param array $ctx ['state' => signed state, 'redirect_uri' => ..., 'shop' => ..., 'scopes' => ...]
     * @throws \Exception when required context (e.g. a valid store domain) is missing
     */
    public function authorizeUrl(array $ctx): string;

    /**
     * Exchange the OAuth callback for a token, verifying provider authenticity and
     * that the callback matches the signed state. Returns a normalized payload:
     *   [ 'access_token', 'token_type', 'scopes', 'external_eid',
     *     'external_name', 'external_url', 'metadata' => [] ]
     *
     * @param array $ctx ['params' => $_GET, 'claims' => verified state claims, 'redirect_uri' => ...]
     * @throws \Exception on verification or exchange failure
     */
    public function exchangeCode(array $ctx): array;

    /**
     * Validate a pasted API key against the provider and normalize it into the
     * SAME payload shape exchangeCode() returns:
     *   [ 'access_token', 'token_type', 'scopes', 'external_eid',
     *     'external_name', 'external_url', 'metadata' => [] ]
     * Only meaningful for connectors whose meta()['auth_type'] === 'api_key'.
     *
     * @param string $key the pasted secret/restricted key (in-process only)
     * @throws \Exception when the connector does not support API-key auth or the
     *                    provider rejects the key
     */
    public function validateApiKey(string $key): array;

    /**
     * The read/data tools this connector exposes over the MCP broker, as MCP tool
     * definitions: [ ['name' => 'get_products', 'description' => ..., 'inputSchema' => [...]], ... ].
     * The gateway namespaces these as "<key>:<name>" (e.g. shopify:get_products).
     * Return [] for connectors with no broker tools.
     */
    public function brokerTools(): array;

    /**
     * Execute a broker tool against a live connection. Runs ONLY on the control
     * plane: it receives the already-decrypted access token and returns DATA — the
     * token must never appear in the return value. The gateway JSON-encodes the result.
     *
     * @param string $tool  the tool name (without the "<key>:" prefix)
     * @param object $conn  the connections bean (shop domain, metadata, scopes)
     * @param string $token the decrypted access token (in-process only)
     * @param array  $args  caller arguments
     * @throws \Exception on unknown tool or API failure
     */
    public function callBrokerTool(string $tool, $conn, string $token, array $args): array;

    /**
     * Create a hosted checkout for an order and return ['url' => <redirect>, 'id' => ...].
     * Payment-provider connectors (meta category 'Payments') implement this; others
     * throw. Runs on the control plane with the already-decrypted token (never returned).
     *
     * @param object $conn  the connections bean
     * @param string $token the decrypted access/secret key (in-process only)
     * @param array  $order ['items' => [['title','amount_cents','currency','quantity'],…],
     *                       'success_url','cancel_url','client_reference_id'?,
     *                       'collect'? => provider-neutral collection intent:
     *                         ['billing'=>bool, 'phone'=>bool, 'shipping'=>bool,
     *                          'countries'=>['US',…],
     *                          'shipping_rate'=>['amount_cents','currency','label']|null]]
     *   Each connector maps `collect` onto its own hosted checkout (Stripe address
     *   collection + shipping_options; PayPal/Square equivalents).
     */
    public function createCheckout($conn, string $token, array $order): array;

    /**
     * Given a raw provider webhook (body + headers), authoritatively confirm a
     * completed order and return normalized order data, or null when the event isn't
     * a confirmed payment. Implementations should RE-FETCH from the provider rather
     * than trust the webhook body. Runs on the control plane with the decrypted token.
     * Normalized shape: ['session_id','payment_intent','amount_total','amount_shipping',
     * 'currency','email','name','phone','reference','livemode',
     *  'billing_address'=>['line1','line2','city','state','postal','country'],
     *  'ship_name','shipping_address'=>[…same address keys…]].
     *
     * @param string $secret the provider's webhook signing secret; when non-empty the
     *                        implementation MUST verify the request signature first and
     *                        throw on failure (defense-in-depth on top of the re-fetch).
     */
    public function webhookOrder($conn, string $token, string $rawBody, array $headers, string $secret = ''): ?array;

    /**
     * Given a raw provider webhook, return the normalized CURRENT state of a subscription
     * for lifecycle events (renewal / dunning / cancellation), or null when the event is
     * not a subscription lifecycle event. Like webhookOrder, implementations MUST verify
     * the signature (when $secret is set) and RE-FETCH from the provider.
     * Normalized shape: ['subscription_id','status','current_period_end',
     * 'cancel_at_period_end','customer_id','email','name','amount','currency','interval',
     * 'livemode']. Non-payment connectors return null.
     */
    public function subscriptionFromEvent($conn, string $token, string $rawBody, array $headers, string $secret = ''): ?array;

    /**
     * Return a hosted self-serve billing/management URL for a customer (update card,
     * view invoices, cancel), redirecting back to $returnUrl. Payment providers with a
     * customer portal implement this; others throw. The billing-integration primitive
     * a builder wires into their own project for end-user self-serve.
     */
    public function billingPortalUrl($conn, string $token, string $customerId, string $returnUrl): string;
}
