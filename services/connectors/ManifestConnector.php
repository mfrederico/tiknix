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

    /** Does this manifest describe an OAuth provider rather than a pasted key? */
    private function isOauth(): bool {
        return !empty($this->m['oauth']['authorize_url']) && !empty($this->m['oauth']['token_url']);
    }

    /**
     * The [oauth] section, from conf/<config_key>.ini.
     *
     * config_key exists because credentials are per PROVIDER, not per card: GitHub
     * already had conf/github.ini with a client id and secret long before this
     * manifest, and making the operator paste the same pair into
     * conf/github_oauth.ini would be a second copy to keep in step.
     */
    protected function oauth(): array {
        $file = dirname(__DIR__, 2) . '/conf/' . $this->configKey() . '.ini';
        $c = @parse_ini_file($file, true) ?: [];
        return $c['oauth'] ?? [];
    }

    private function configKey(): string {
        $k = trim((string) ($this->m['config_key'] ?? ''));
        // Never let a manifest name an arbitrary path: this becomes a filename.
        return preg_match('/^[a-z][a-z0-9_]*$/', $k) ? $k : $this->key();
    }

    /**
     * OAuth needs credentials only the operator has, so an OAuth manifest is inert
     * until its ini carries them. Saying so is the difference between a card that
     * explains itself and one that fails at the redirect.
     *
     * NOT parent::isConfigured() — RestConnector answers an unconditional true
     * (an api-key connector needs no platform credentials), so deferring to it
     * reported every OAuth manifest as ready to use with no client id at all.
     */
    public function isConfigured(): bool {
        if (!$this->isOauth()) return true;
        $o = $this->oauth();
        return !empty($o['client_id']) && !empty($o['client_secret']);
    }

    public function meta(): array {
        if ($this->isOauth()) return $this->oauthMeta();

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

    // ---------------------------------------------------------------- OAuth

    private function oauthMeta(): array {
        return [
            'label'     => (string) ($this->m['label'] ?? $this->key()),
            'auth_type' => 'oauth',
            'blurb'     => (string) ($this->m['blurb'] ?? ''),
            'category'  => (string) ($this->m['category'] ?? 'Data'),
            'icon'      => (string) ($this->m['icon'] ?? 'plug'),
            'color'     => (string) ($this->m['color'] ?? 'secondary'),
            'features'  => (array)  ($this->m['features'] ?? []),
            'manifest'  => true,
        ];
    }

    /** Manifest providers (GitHub, Google, HubSpot) space-delimit; overridable per manifest. */
    protected function scopeSeparator(): string { return (string) ($this->m['oauth']['scope_separator'] ?? ' '); }

    public function defaultScopes(): string {
        return (string) ($this->oauth()['scope'] ?? $this->m['oauth']['scope'] ?? '');
    }

    public function authorizeUrl(array $ctx): string {
        if (!$this->isOauth()) return parent::authorizeUrl($ctx);

        // Per-CONNECTION app: the customer's own client id/secret when they brought one,
        // the server-wide ini when they did not. Same resolution as the exchange leg below,
        // because a redirect signed by one app and a token exchange authenticated as
        // another fails at the provider saying only that the request was rejected.
        $o = $this->appFor($ctx);
        $q = [
            'client_id'     => (string) ($o['client_id'] ?? ''),
            'response_type' => 'code',
            'redirect_uri'  => (string) ($ctx['redirect_uri'] ?? ''),
            'state'         => (string) ($ctx['state'] ?? ''),
        ];
        // scopesFor(), not ?? — an empty form field is "not specified", and passing it
        // through as a literal empty scope asks the provider for no access at all.
        $scope = $this->scopesFor($ctx);
        if ($scope !== '') $q['scope'] = $scope;

        // Extra fixed parameters some providers require (Google's access_type, an
        // Intuit-style prompt). Declared rather than special-cased per provider.
        foreach ((array) ($this->m['oauth']['extra_params'] ?? []) as $k => $v) $q[(string) $k] = (string) $v;

        return (string) $this->m['oauth']['authorize_url'] . '?' . http_build_query($q);
    }

    public function exchangeCode(array $ctx): array {
        if (!$this->isOauth()) return parent::exchangeCode($ctx);

        $params = $ctx['params'] ?? [];
        if (!empty($params['error'])) {
            throw new \Exception($this->m['label'] . ' authorization failed: '
                . (string) ($params['error_description'] ?? $params['error']));
        }
        $code = trim((string) ($params['code'] ?? ''));
        if ($code === '') throw new \Exception($this->m['label'] . ' returned no authorization code.');

        $o  = $this->appFor($ctx);   // the SAME app the authorize leg used
        $oc = $this->m['oauth'];

        $form = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => (string) ($ctx['redirect_uri'] ?? ''),
        ];
        $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];

        // Two conventions, both common: credentials in the form body, or as HTTP
        // Basic. Declared per manifest because guessing wrong is a 401 with no clue
        // which half was wrong.
        if (($oc['client_auth'] ?? 'body') === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode(($o['client_id'] ?? '') . ':' . ($o['client_secret'] ?? ''));
        } else {
            $form['client_id']     = (string) ($o['client_id'] ?? '');
            $form['client_secret'] = (string) ($o['client_secret'] ?? '');
        }

        [$status, $raw, $err] = $this->http('POST', (string) $oc['token_url'], [
            'headers' => $headers, 'body' => http_build_query($form), 'timeout' => 20,
        ]);
        if ($err !== '') throw new \Exception('Could not reach ' . $this->m['label'] . ': ' . $err);

        $j = json_decode($raw, true);
        if (!is_array($j)) {
            // GitHub answers form-encoded unless asked for JSON, and a provider that
            // ignores the Accept header would otherwise look like a broken response.
            parse_str($raw, $j);
        }
        if ($status < 200 || $status >= 300 || !empty($j['error'])) {
            throw new \Exception($this->m['label'] . ' rejected the authorization: '
                . (string) ($j['error_description'] ?? $j['error'] ?? ('HTTP ' . $status)));
        }

        $access = (string) ($j['access_token'] ?? '');
        if ($access === '') throw new \Exception($this->m['label'] . ' returned no access token.');

        $identity = $this->identity($access);

        $payload = [
            'access_token'  => $access,
            'token_type'    => (string) ($j['token_type'] ?? 'Bearer'),
            'scopes'        => (string) ($j['scope'] ?? $this->defaultScopes()),
            'external_eid'  => $identity['eid'] ?: (string) parse_url((string) $this->m['base_url'], PHP_URL_HOST),
            'external_name' => $identity['name'] ?: (string) $this->m['label'],
            'external_url'  => (string) ($this->m['account_url'] ?? $this->m['base_url'] ?? ''),
            'metadata'      => [
                'base_url'  => rtrim((string) ($this->m['base_url'] ?? ''), '/'),
                'auth'      => 'bearer',
                'auth_name' => '',
                'username'  => '',
                'test_path' => (string) ($this->m['test_path'] ?? ''),
            ],
        ];

        // Only when the provider actually expires tokens. Writing expires_at
        // unconditionally would make the broker try to refresh a token that never
        // needs it, using a refresh token that does not exist.
        if (!empty($j['refresh_token'])) $payload['refresh_token'] = (string) $j['refresh_token'];
        if (!empty($j['expires_in']))    $payload['expires_at']    = time() + (int) $j['expires_in'];

        if (!empty($this->m['spec_url'])) {
            $payload['metadata']['spec'] = self::importSpec((string) $this->m['spec_url'], 'bearer', '', '', $access);
            $payload['metadata']['spec_url'] = (string) $this->m['spec_url'];
        }

        return $payload;
    }

    /**
     * Ask the provider who just authorized, so the connection has a name a person
     * recognizes rather than an opaque id.
     *
     * Best effort by design: a label is not worth failing an otherwise good
     * authorization over, and providers differ on whether an identity endpoint even
     * exists.
     */
    private function identity(string $token): array {
        $spec = $this->m['oauth']['identity'] ?? null;
        if (!is_array($spec) || empty($spec['path'])) return ['eid' => '', 'name' => ''];

        try {
            $r = $this->callBrokerTool('request', $this->pseudoConn(), $token, ['path' => (string) $spec['path']]);
            $body = is_array($r['body'] ?? null) ? $r['body'] : [];
            return [
                'eid'  => (string) (\app\Pipeline\Vars::lookup((string) ($spec['eid'] ?? 'id'), $body) ?? ''),
                'name' => (string) (\app\Pipeline\Vars::lookup((string) ($spec['name'] ?? 'name'), $body) ?? ''),
            ];
        } catch (\Throwable $e) {
            return ['eid' => '', 'name' => ''];
        }
    }

    /**
     * A stand-in connection for calls made BEFORE one exists — the identity probe
     * during exchangeCode. It carries only what callBrokerTool reads: the base URL
     * and auth style from the manifest.
     */
    private function pseudoConn(): object {
        $c = new \stdClass();
        $c->metadataJson = json_encode([
            'base_url'  => rtrim((string) ($this->m['base_url'] ?? ''), '/'),
            'auth'      => 'bearer', 'auth_name' => '', 'username' => '',
        ]);
        return $c;
    }
}
