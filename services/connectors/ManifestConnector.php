<?php
/**
 * ManifestConnector — a connector declared as DATA rather than written as code.
 *
 * Most APIs are the same shape: a fixed base URL, one of a handful of auth
 * styles, and a set of endpoints. RestConnector already talks to any of them; what
 * it could not do is BE one — it appears as a single generic "REST API" card the
 * user has to configure by hand, every time, correctly.
 *
 * A manifest turns that configuration into a named connector. Drop
 * connectors/hubspot.json in and HubSpot appears in the hub as its own card, with
 * its own label, its own "paste your private app token" wording, and its base URL
 * already known. No PHP, and — the point of the exercise — no edit to any shared
 * file, so adding one cannot conflict with anything in any instance.
 *
 * WHY THIS EXISTS. Adding a connector used to be cheap in theory (the registry
 * discovers classes by glob) and expensive in practice: both connectors written on
 * 2026-08-06 also had to change ConnectorInterface, AbstractConnector, three other
 * connector classes, ConnectionStore, Mcp and the connections view — shared code
 * that then had to reach ten instances in lockstep. A manifest touches none of it.
 *
 * WHAT STAYS CODE. Providers whose behaviour is genuinely bespoke: Shopify speaks
 * GraphQL, Stripe form-encodes and rotates idempotency keys, QuickBooks needs a
 * realmId from the OAuth callback and hourly refresh. A manifest is for the ones
 * that do not need any of that, which is most of them. A class always wins over a
 * manifest of the same key, so a declarative connector can graduate to code
 * without anything being renamed.
 *
 * Everything below the surface — SSRF guarding, auth injection, the OpenAPI
 * import, the request builder — is RestConnector's, inherited rather than copied.
 */

namespace app\services\connectors;

class ManifestConnector extends RestConnector {

    private array $m;

    public function __construct(array $manifest) {
        $this->m = $manifest;
    }

    public function key(): string { return (string) $this->m['key']; }

    public function meta(): array {
        $auth = $this->m['auth'] ?? [];
        $style = (string) ($auth['style'] ?? 'bearer');

        // The manifest FIXES base URL and auth style, so neither is offered as a
        // form field — that is the whole difference from the generic REST card. The
        // user supplies the one thing only they have: the secret.
        $fields = [];
        if (!empty($this->m['ask_base_url'])) {
            $fields[] = ['name' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'required' => true,
                         'default' => (string) ($this->m['base_url'] ?? ''),
                         'help' => 'Your own instance of this API.'];
        }
        foreach ((array) ($this->m['fields'] ?? []) as $f) {
            $fields[] = $f;
        }

        return [
            'label'     => (string) ($this->m['label'] ?? $this->key()),
            'auth_type' => 'api_key',
            'blurb'     => (string) ($this->m['blurb'] ?? ''),
            'category'  => (string) ($this->m['category'] ?? 'Data'),
            'icon'      => (string) ($this->m['icon'] ?? 'plug'),
            'color'     => (string) ($this->m['color'] ?? 'secondary'),
            'features'  => (array)  ($this->m['features'] ?? []),

            'key_label'       => (string) ($auth['key_label'] ?? 'API key'),
            'key_placeholder' => (string) ($auth['key_placeholder'] ?? ''),
            'key_hint'        => (string) ($auth['key_hint'] ?? ''),
            'key_required'    => $style !== 'none',
            'fields'          => $fields,

            // Marks the card as declarative in the UI and in reuse_digest, so it is
            // obvious which connectors can be edited as data.
            'manifest'        => true,
        ];
    }

    /**
     * Fold the manifest's fixed settings in, then let RestConnector do the work.
     *
     * Manifest values WIN over anything posted: the base URL and auth style are the
     * connector's identity, not the user's choice. Letting a form override them
     * would make "HubSpot" a card that can quietly point somewhere else.
     */
    public function validateApiKey(string $key, array $opts = []): array {
        $auth = $this->m['auth'] ?? [];

        $fixed = [
            'auth'      => (string) ($auth['style'] ?? 'bearer'),
            'auth_name' => (string) ($auth['name'] ?? ''),
            'test_path' => (string) ($this->m['test_path'] ?? ''),
        ];
        if (!empty($this->m['spec_url'])) $fixed['spec_url'] = (string) $this->m['spec_url'];
        if (empty($this->m['ask_base_url'])) $fixed['base_url'] = (string) ($this->m['base_url'] ?? '');

        $merged = array_merge($opts, array_filter($fixed, static fn($v) => $v !== ''));

        // RestConnector's "choose None for a public API" is good advice on the
        // generic card and nonsense here: a manifest FIXES the auth style, so there
        // is nothing for the user to choose. Say what they can actually act on.
        if ($fixed['auth'] !== 'none' && trim($key) === '') {
            throw new \Exception(($this->m['label'] ?? $this->key()) . ' needs '
                . lcfirst((string) ($auth['key_label'] ?? 'an API key')) . '.'
                . (!empty($auth['key_hint']) ? ' ' . (string) $auth['key_hint'] : ''));
        }

        // Default the connection's display name to the provider, not the bare host:
        // "HubSpot" reads better in a list than "api.hubapi.com".
        if (trim((string) ($merged['label'] ?? '')) === '') {
            $merged['label'] = (string) ($this->m['label'] ?? $this->key());
        }

        return parent::validateApiKey($key, $merged);
    }
}
