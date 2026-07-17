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
}
