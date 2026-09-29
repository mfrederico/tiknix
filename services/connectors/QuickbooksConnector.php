<?php
/**
 * QuickbooksConnector — QuickBooks Online (Intuit) accounting data.
 *
 * Three things make QBO different from the other OAuth connectors here, and each
 * one is a place a naive implementation breaks:
 *
 * 1. THE COMPANY ID IS NOT IN THE TOKEN. Intuit returns `realmId` as a query
 *    parameter on the OAuth callback, and every API path is
 *    /v3/company/<realmId>/... A connector that only reads the token body ends up
 *    authenticated with nowhere to call. It is captured at callback and kept as
 *    external_eid — the company IS the identity of the connection.
 *
 * 2. ACCESS TOKENS LAST ONE HOUR. Shopify and Stripe tokens do not expire, so
 *    nothing in this codebase ever refreshed one. A QBO connection would work
 *    beautifully for an hour and then fail forever. refreshToken() is implemented
 *    here and the broker now calls it before use.
 *
 * 3. SANDBOX AND PRODUCTION ARE DIFFERENT HOSTS with the same auth server. The
 *    environment on the connection selects the API host, so a developer's sandbox
 *    company cannot be mistaken for live books.
 *
 * Requires an Intuit app: conf/quickbooks.ini [oauth] client_id / client_secret,
 * with this install's /connections/callback/quickbooks registered as a redirect URI.
 */

namespace app\services\connectors;

class QuickbooksConnector extends AbstractConnector {

    private const AUTH_BASE  = 'https://appcenter.intuit.com/connect/oauth2';
    private const TOKEN_URL  = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    private const API_LIVE   = 'https://quickbooks.api.intuit.com';
    private const API_SANDBOX = 'https://sandbox-quickbooks.api.intuit.com';

    /**
     * Minor version pins the response shape. Without it Intuit serves whatever is
     * current and a field can change under a working pipeline.
     */
    private const MINOR_VERSION = '75';

    /** Refresh this many seconds BEFORE expiry, so a long call cannot straddle it. */
    public const REFRESH_SKEW = 300;

    public function key(): string { return 'quickbooks'; }

    public function meta(): array {
        return [
            'label'     => 'QuickBooks Online',
            'auth_type' => 'oauth',
            'blurb'     => 'Read and write your books — customers, invoices, payments and accounts — with the company chosen when you connect.',
            'category'  => 'Accounting',
            'icon'      => 'calculator',
            'color'     => 'success',
            'features'  => ['Invoices', 'Customers', 'Reports'],
        ];
    }

    public function defaultScopes(): string {
        // Accounting is the useful surface. openid/profile are NOT requested: they
        // add a consent prompt for identity data this connector never reads.
        return (string) ($this->oauth()['scope'] ?? 'com.intuit.quickbooks.accounting');
    }

    public function authorizeUrl(array $ctx): string {
        $o = $this->oauth();
        return self::AUTH_BASE . '?' . http_build_query([
            'client_id'     => (string) ($o['client_id'] ?? ''),
            'response_type' => 'code',
            'scope'         => (string) ($ctx['scopes'] ?? $this->defaultScopes()),
            'redirect_uri'  => (string) ($ctx['redirect_uri'] ?? ''),
            'state'         => (string) ($ctx['state'] ?? ''),
        ]);
    }

    public function exchangeCode(array $ctx): array {
        $params = $ctx['params'] ?? [];

        if (!empty($params['error'])) {
            throw new \Exception('QuickBooks authorization failed: '
                . (string) ($params['error_description'] ?? $params['error']));
        }

        $code = trim((string) ($params['code'] ?? ''));
        if ($code === '') throw new \Exception('QuickBooks returned no authorization code.');

        // The company id, and the reason this connector cannot be generic. Without
        // it there is no API path to call, so refusing here beats storing a
        // connection that can authenticate and reach nothing.
        $realmId = trim((string) ($params['realmId'] ?? ''));
        if ($realmId === '') {
            throw new \Exception('QuickBooks did not return a company (realmId). Re-authorize and pick a company.');
        }

        $tok = $this->tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => (string) ($ctx['redirect_uri'] ?? ''),
        ]);

        $env  = (string) ($ctx['claims']['environment'] ?? 'production');
        $name = $this->companyName($tok['access_token'], $realmId, $env) ?: ('Company ' . $realmId);

        return [
            'access_token'  => $tok['access_token'],
            'refresh_token' => $tok['refresh_token'],
            'expires_at'    => $tok['expires_at'],
            'token_type'    => 'Bearer',
            'scopes'        => $this->defaultScopes(),
            'external_eid'  => $realmId,
            'external_name' => $name,
            'external_url'  => 'https://app.qbo.intuit.com/app/homepage',
            'metadata'      => [
                'realm_eid'          => $realmId,
                'minor_version'      => self::MINOR_VERSION,
                // Recorded so a support question about a stale connection can be
                // answered without guessing when the refresh token itself dies.
                'refresh_expires_in' => $tok['refresh_expires_in'],
            ],
        ];
    }

    /**
     * Swap the refresh token for a fresh access token.
     *
     * Intuit ROTATES the refresh token periodically — it returns a new one, and the
     * old one stops working. Returning it here matters: dropping it means the
     * connection keeps working until the day Intuit rotates, and then dies with no
     * obvious cause.
     */
    public function refreshToken($conn, string $token): ?array {
        $refresh = (string) ($conn->plainRefreshToken ?? '');
        if ($refresh === '') {
            throw new \Exception('This QuickBooks connection has no refresh token — reconnect it.');
        }

        $tok = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);

        return [
            'access_token'  => $tok['access_token'],
            'refresh_token' => $tok['refresh_token'],
            'expires_at'    => $tok['expires_at'],
        ];
    }

    public function brokerTools(): array {
        return [
            [
                'name'        => 'query',
                'description' => 'Run a QuickBooks query and return the matching records. Uses QBO\'s SQL-like syntax, e.g. SELECT * FROM Invoice WHERE TxnDate > \'2026-01-01\'.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The QBO query, e.g. SELECT * FROM Customer MAXRESULTS 100.'],
                    ],
                    'required'   => ['query'],
                ],
            ],
            [
                'name'        => 'request',
                'description' => 'Call any QuickBooks Online endpoint for this company. The company id and credential are supplied for you.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'method' => ['type' => 'string', 'description' => 'GET, POST. Defaults to GET.'],
                        'path'   => ['type' => 'string', 'description' => 'Path AFTER /v3/company/<id>, e.g. /invoice/42 or /reports/ProfitAndLoss.'],
                        'query'  => ['type' => 'object', 'description' => 'Optional query-string parameters.'],
                        'body'   => ['description' => 'Optional JSON body for a write.'],
                    ],
                    'required'   => ['path'],
                ],
            ],
        ];
    }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        $realm = (string) ($conn->externalEid ?? '');
        if ($realm === '') throw new \Exception('This QuickBooks connection has no company id — reconnect it.');

        $base = self::apiBase((string) ($conn->environment ?? 'production')) . '/v3/company/' . rawurlencode($realm);

        if ($tool === 'query') {
            $q = trim((string) ($args['query'] ?? ''));
            if ($q === '') throw new \Exception('A query is required.');
            $url = $base . '/query?' . http_build_query(['query' => $q, 'minorversion' => self::MINOR_VERSION]);
            return $this->call('GET', $url, $token, null);
        }

        if ($tool !== 'request') throw new \Exception("Unknown quickbooks tool '{$tool}'.");

        $method = strtoupper(trim((string) ($args['method'] ?? 'GET')));
        if (!in_array($method, ['GET', 'POST'], true)) {
            // QBO models updates as POST with a sparse body; PUT/DELETE are not part
            // of its API, so accepting them would only produce confusing 4xx.
            throw new \Exception("QuickBooks uses GET and POST only, not '{$method}'.");
        }

        $path  = '/' . ltrim((string) ($args['path'] ?? ''), '/');
        $query = (array) ($args['query'] ?? []);
        $query['minorversion'] = $query['minorversion'] ?? self::MINOR_VERSION;
        $url = $base . $path . '?' . http_build_query($query);

        $body = null;
        if (isset($args['body']) && $method === 'POST') {
            $body = is_array($args['body']) ? json_encode($args['body'], JSON_UNESCAPED_SLASHES) : (string) $args['body'];
        }
        return $this->call($method, $url, $token, $body);
    }

    // ------------------------------------------------------------------ internals

    private static function apiBase(string $env): string {
        // Anything that is not explicitly production is treated as sandbox. Getting
        // this backwards would write test data into real books.
        return $env === 'production' ? self::API_LIVE : self::API_SANDBOX;
    }

    /** One authenticated API call, with QBO's error shape unwrapped. */
    private function call(string $method, string $url, string $token, ?string $body): array {
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        if ($body !== null) $headers[] = 'Content-Type: application/json';

        [$status, $raw, $err] = $this->http($method, $url, ['headers' => $headers, 'body' => $body, 'timeout' => 30]);
        if ($err !== '') throw new \Exception('QuickBooks request failed: ' . $err);

        $decoded = json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            // Intuit nests the useful sentence several levels down. Surfacing the
            // raw JSON instead would make every failure look the same.
            $f = $decoded['Fault']['Error'][0] ?? null;
            $msg = $f
                ? trim((string) ($f['Message'] ?? '') . ' ' . (string) ($f['Detail'] ?? ''))
                : ('HTTP ' . $status);
            throw new \Exception('QuickBooks: ' . $msg);
        }

        return ['status' => $status, 'ok' => true, 'body' => $decoded !== null ? $decoded : $raw];
    }

    /** POST to Intuit's token endpoint; normalizes both grant types' replies. */
    private function tokenRequest(array $form): array {
        $o = $this->oauth();
        $id     = (string) ($o['client_id'] ?? '');
        $secret = (string) ($o['client_secret'] ?? '');
        if ($id === '' || $secret === '') {
            throw new \Exception('QuickBooks is not configured on this install (conf/quickbooks.ini [oauth] client_id / client_secret).');
        }

        [$status, $raw, $err] = $this->http('POST', self::TOKEN_URL, [
            'headers' => [
                'Authorization: Basic ' . base64_encode($id . ':' . $secret),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            'body'    => http_build_query($form),
            'timeout' => 20,
        ]);
        if ($err !== '') throw new \Exception('Could not reach Intuit: ' . $err);

        $j = json_decode($raw, true) ?: [];
        if ($status < 200 || $status >= 300) {
            throw new \Exception('Intuit rejected the token request: '
                . (string) ($j['error_description'] ?? $j['error'] ?? ('HTTP ' . $status)));
        }

        $access = (string) ($j['access_token'] ?? '');
        if ($access === '') throw new \Exception('Intuit returned no access token.');

        return [
            'access_token'       => $access,
            'refresh_token'      => (string) ($j['refresh_token'] ?? ''),
            // expires_in is seconds from now; stored absolute so a comparison later
            // does not depend on when it was read.
            'expires_at'         => time() + (int) ($j['expires_in'] ?? 3600),
            'refresh_expires_in' => (int) ($j['x_refresh_token_expires_in'] ?? 0),
        ];
    }

    /** The company's display name, for a connection label a human recognizes. */
    private function companyName(string $token, string $realmId, string $env): string {
        try {
            $url = self::apiBase($env) . '/v3/company/' . rawurlencode($realmId)
                 . '/companyinfo/' . rawurlencode($realmId) . '?minorversion=' . self::MINOR_VERSION;
            $r = $this->call('GET', $url, $token, null);
            return trim((string) ($r['body']['CompanyInfo']['CompanyName'] ?? ''));
        } catch (\Throwable $e) {
            // A label is not worth failing a connection over — the realm id is
            // already a usable name.
            return '';
        }
    }
}
