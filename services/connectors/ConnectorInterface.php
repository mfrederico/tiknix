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
     *                       'success_url','cancel_url','client_reference_id'?]
     */
    public function createCheckout($conn, string $token, array $order): array;

    /**
     * Given a raw provider webhook (body + headers), authoritatively confirm a
     * completed order and return normalized order data, or null when the event isn't
     * a confirmed payment. Implementations should RE-FETCH from the provider rather
     * than trust the webhook body. Runs on the control plane with the decrypted token.
     * Normalized shape: ['session_id','payment_intent','amount_total','currency',
     * 'email','name','reference','livemode'].
     */
    public function webhookOrder($conn, string $token, string $rawBody, array $headers): ?array;
}
