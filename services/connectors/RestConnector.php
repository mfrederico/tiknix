<?php
/**
 * RestConnector — connect to ANY REST API by configuration instead of code.
 *
 * Every other connector in this directory hardcodes one vendor's host and auth
 * scheme, so reaching a new API meant writing a new class. A pipeline could
 * already call an arbitrary URL with the http step, but the credential would
 * have to be typed into the step's headers — which puts a plaintext secret in a
 * pipeline JSON file committed to the instance repo. That is the exact thing the
 * broker exists to prevent. This closes the gap: the base URL and auth style are
 * stored on the connection, the secret is encrypted beside them, and a pipeline
 * names neither.
 *
 * The connection row holds:
 *   access_token   the secret, encrypted by the instance (bearer token, api key,
 *                  or the password half of basic auth)
 *   external_eid   the base URL's host, so connections are identifiable at a glance
 *   metadata       base_url, auth style, header/query name, basic username
 *
 * Config lives in metadata rather than a file beside it so a connection stays ONE
 * row: disconnecting removes everything, and there is no second store to drift
 * out of sync. It follows the precedent Shopify already set with api_version.
 *
 * SECURITY — the base URL is user-supplied and the request is made by CORE, which
 * sits on the internal network. Without a guard this connector would be a
 * server-side request forgery primitive: a base_url of http://169.254.169.254/
 * reaches cloud metadata, and http://127.0.0.1/ or 10.x reaches other tenants.
 * So every host is resolved and checked against private, loopback, link-local and
 * reserved ranges — at connect time AND again on every call, because DNS can
 * change between the two. Redirects are not followed (AbstractConnector::http
 * never sets CURLOPT_FOLLOWLOCATION), which closes the obvious way around it.
 */

namespace app\services\connectors;

class RestConnector extends AbstractConnector {

    /** Auth styles a connection may use. */
    private const AUTH_STYLES = ['bearer', 'header', 'basic', 'query', 'none'];

    /**
     * Identify ourselves. Not politeness — GitHub answers 403 to a request with no
     * User-Agent at all, and it is far from alone, so omitting this makes perfectly
     * good connections look like rejected credentials. A caller may override it via
     * the request's own headers.
     */
    private const USER_AGENT = 'User-Agent: tiknix-connector/1.0 (+https://tiknix.com)';

    public function key(): string { return 'rest'; }

    public function meta(): array {
        return [
            'label'     => 'REST API',
            'auth_type' => 'api_key',
            'blurb'     => 'Connect any HTTP API by URL — point at its base address, choose how it authenticates, and pipelines can call every endpoint on it without the key ever leaving this install.',
            'category'  => 'Data',
            'icon'      => 'plug',
            'color'     => 'secondary',
            'features'  => ['Any endpoint', 'Bearer / header / basic', 'Keys stay server-side'],

            // The connect form renders these. Declaring them here rather than in the
            // view is what lets a connector need more than a pasted key without the
            // form growing a special case per connector.
            'key_label'       => 'API key or token',
            'key_placeholder' => 'the secret this API expects (leave blank if it needs none)',
            'key_required'    => false,
            'key_hint'        => 'Stored encrypted on this install. Pipelines reference the connection, never the secret.',
            'fields'          => [
                ['name' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'required' => true,
                 'placeholder' => 'https://api.example.com/v2',
                 'help' => 'Everything before the endpoint path. Calls may only reach this host.'],
                ['name' => 'auth', 'label' => 'Authentication', 'type' => 'select', 'required' => true,
                 'options' => [
                     'bearer' => 'Bearer token  (Authorization: Bearer <key>)',
                     'header' => 'Custom header (e.g. X-API-Key: <key>)',
                     'basic'  => 'Basic auth    (username + key as password)',
                     'query'  => 'Query string  (?api_key=<key>)',
                     'none'   => 'None — public API',
                 ],
                 'default' => 'bearer'],
                ['name' => 'auth_name', 'label' => 'Header / parameter name', 'type' => 'text',
                 'placeholder' => 'X-API-Key',
                 'help' => 'Only for the custom-header and query-string styles.'],
                ['name' => 'username', 'label' => 'Username', 'type' => 'text',
                 'help' => 'Only for basic auth. The key above is used as the password.'],
                ['name' => 'test_path', 'label' => 'Test path', 'type' => 'text',
                 'placeholder' => '/me',
                 'help' => 'Optional — a read-only endpoint used once to prove the credential works before saving.'],
                ['name' => 'spec_url', 'label' => 'OpenAPI / Swagger URL', 'type' => 'url',
                 'placeholder' => 'https://api.example.com/openapi.json',
                 'help' => 'Optional — import the API description so pipelines can pick endpoints by name instead of typing paths. JSON or YAML.'],
                ['name' => 'label', 'label' => 'Name this connection', 'type' => 'text',
                 'placeholder' => 'Acme production API'],
            ],
        ];
    }

    /** No platform-side credentials — usable out of the box. */
    public function isConfigured(): bool { return true; }

    /**
     * There is no OAuth here and there cannot be: OAuth needs a provider-specific
     * app registration, which is the very thing a by-configuration connector does
     * not have. An API that requires OAuth wants its own connector class.
     */
    public function authorizeUrl(array $ctx): string {
        throw new \Exception('The REST connector authenticates with a key, not OAuth.');
    }

    public function exchangeCode(array $ctx): array {
        throw new \Exception('The REST connector authenticates with a key, not OAuth.');
    }

    /**
     * Prove the configuration actually reaches a live API before storing it.
     *
     * A connection that is only checked for well-formedness fails later, inside a
     * pipeline, where the reason is much harder to see. One real request here
     * turns "your pipeline returned nothing" into "that host rejected the key".
     */
    public function validateApiKey(string $key, array $opts = []): array {
        $base = self::normalizeBase((string) ($opts['base_url'] ?? ''));
        $auth = strtolower(trim((string) ($opts['auth'] ?? 'bearer')));
        $name = trim((string) ($opts['auth_name'] ?? ''));
        $user = trim((string) ($opts['username'] ?? ''));
        $key  = trim($key);

        if (!in_array($auth, self::AUTH_STYLES, true)) {
            throw new \Exception('Unknown authentication style. Choose bearer, header, basic, query or none.');
        }
        if ($auth !== 'none' && $key === '') {
            throw new \Exception('That authentication style needs a key. Choose "None" for a public API.');
        }
        if (($auth === 'header' || $auth === 'query') && $name === '') {
            throw new \Exception($auth === 'header'
                ? 'A header name is required, e.g. X-API-Key.'
                : 'A query parameter name is required, e.g. api_key.');
        }
        if ($auth === 'basic' && $user === '') {
            throw new \Exception('Basic auth needs a username. The key above is used as the password.');
        }

        // Guard BEFORE the probe: an unguarded validation request is itself the
        // SSRF, whether or not the connection is ever saved.
        self::assertPublicHost($base);

        $testPath = trim((string) ($opts['test_path'] ?? ''));
        $probeUrl = $testPath === '' ? $base : self::joinUrl($base, $testPath);

        $headers = self::authHeaders($auth, $name, $user, $key);
        $headers[] = 'Accept: application/json';
        $headers[] = self::USER_AGENT;

        [$status, $body, $err] = $this->http('GET', self::applyQueryAuth($probeUrl, $auth, $name, $key), [
            'headers' => $headers,
            'timeout' => 15,
        ]);

        if ($err !== '') {
            throw new \Exception('Could not reach that API: ' . $err);
        }
        if ($status === 401 || $status === 403) {
            // Say which of the two it is. With auth "none" there is no credential to
            // check, and telling someone to check a key they were never asked for
            // sends them looking in the wrong place.
            throw new \Exception($auth === 'none'
                ? 'That API refused an unauthenticated request (HTTP ' . $status . '). It needs a key — pick an authentication style.'
                : 'That API rejected the credential (HTTP ' . $status . '). Check the key and the authentication style.');
        }
        // A 404 on the BASE url is normal — many APIs have nothing at the root. It
        // only means something when the author named a test path to check.
        if ($status === 404 && $testPath !== '') {
            throw new \Exception('The test path returned 404 — check ' . $testPath . ' exists on that API.');
        }
        if ($status === 0) {
            throw new \Exception('No response from that API. Check the base URL.');
        }
        if ($status >= 500) {
            throw new \Exception('That API returned a server error (HTTP ' . $status . '). Try again, or pick a different test path.');
        }

        $host  = (string) parse_url($base, PHP_URL_HOST);
        $label = trim((string) ($opts['label'] ?? '')) ?: $host;

        $metadata = [
            'base_url'  => $base,
            'auth'      => $auth,
            'auth_name' => $name,
            'username'  => $user,
            'test_path' => $testPath,
        ];

        // An imported description is a convenience, so a broken one must not cost
        // the user a working connection: the import throws with its own reason and
        // the caller sees THAT, rather than a connection that silently has no
        // endpoint list and no explanation.
        $specUrl = trim((string) ($opts['spec_url'] ?? ''));
        if ($specUrl !== '') {
            $metadata['spec'] = self::importSpec($specUrl, $auth, $name, $user, $key);
            $metadata['spec_url'] = $specUrl;
            if ($label === $host && !empty($metadata['spec']['title'])) {
                $label = (string) $metadata['spec']['title'];
            }
        }

        return [
            'access_token'  => $key,          // '' for a public API; stored encrypted either way
            'token_type'    => $auth === 'bearer' ? 'Bearer' : $auth,
            'scopes'        => 'api_key',
            'external_eid'  => $host,
            'external_name' => $label,
            'external_url'  => $base,
            'metadata'      => $metadata,
        ];
    }

    /**
     * Fetch and digest an OpenAPI/Swagger document.
     *
     * The URL is user-supplied and fetched BY US, so it goes through exactly the
     * same host guard as an API call — a spec_url is otherwise a tidy way to ask
     * the server to fetch an internal address on your behalf.
     *
     * Only the digest is returned. The raw document routinely runs to megabytes;
     * what a pipeline needs is the operation list, and that is small enough to sit
     * on the connection where the broker can reach it with no filesystem coupling.
     */
    private static function importSpec(string $specUrl, string $auth, string $name, string $user, string $key): array {
        self::assertPublicHost($specUrl);

        $headers = self::authHeaders($auth, $name, $user, $key);
        $headers[] = 'Accept: application/json, application/yaml, text/yaml, */*';
        $headers[] = self::USER_AGENT;

        $c = new self();
        [$status, $body, $err] = $c->http('GET', self::applyQueryAuth($specUrl, $auth, $name, $key), [
            'headers' => $headers, 'timeout' => 30,
        ]);

        if ($err !== '')                  throw new \Exception('Could not fetch the specification: ' . $err);
        if ($status < 200 || $status >= 300) throw new \Exception('The specification URL returned HTTP ' . $status . '.');

        $digest = \app\services\Config\OpenApiSpec::parse($body);

        // Resolve relative servers ("/api/v3") against where the spec came from.
        $digest['servers'] = \app\services\Config\OpenApiSpec::absoluteServers($digest['servers'], $specUrl);
        $digest['imported_at'] = date('Y-m-d H:i:s');

        return $digest;
    }

    public function brokerTools(): array {
        return [
            [
                'name'        => 'request',
                'description' => 'Call any endpoint on this connection\'s API. The base URL and credential are held by the connection; supply a path, or an operation name if a specification was imported.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'method'    => ['type' => 'string', 'description' => 'GET, POST, PUT, PATCH or DELETE. Defaults to GET. Ignored when operation is given.'],
                        'path'      => ['type' => 'string', 'description' => 'Path appended to the base URL, e.g. /customers/42.'],
                        'operation' => ['type' => 'string', 'description' => 'An operation id from the imported specification, e.g. getPetById. Supply this INSTEAD of method and path.'],
                        'params'    => ['type' => 'object', 'description' => 'Values for the operation\'s path and query parameters, by name.'],
                        'query'     => ['type' => 'object', 'description' => 'Extra query-string parameters.'],
                        'body'      => ['description' => 'Optional request body. An object is sent as JSON.'],
                        'headers'   => ['type' => 'object', 'description' => 'Optional extra headers. Authentication is added for you.'],
                    ],
                ],
            ],
            [
                'name'        => 'operations',
                'description' => 'List the endpoints from this connection\'s imported OpenAPI/Swagger specification, so you can call one by name instead of guessing a path.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Optional filter on id, path, summary or tag.'],
                        'limit'  => ['type' => 'integer', 'description' => 'Maximum operations to return. Defaults to 100.'],
                    ],
                ],
            ],
        ];
    }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        $meta = json_decode((string) ($conn->metadataJson ?? ''), true) ?: [];

        if ($tool === 'operations') {
            return self::listOperations($meta, $args);
        }
        if ($tool !== 'request') {
            throw new \Exception("Unknown rest tool '{$tool}'.");
        }

        $base = self::normalizeBase((string) ($meta['base_url'] ?? ''));
        if ($base === '') {
            throw new \Exception('This connection has no base URL recorded — reconnect it.');
        }
        $auth = strtolower((string) ($meta['auth'] ?? 'bearer'));
        $name = (string) ($meta['auth_name'] ?? '');
        $user = (string) ($meta['username'] ?? '');

        $method    = strtoupper(trim((string) ($args['method'] ?? 'GET')));
        $path      = (string) ($args['path'] ?? '');
        $extraQuery = (array) ($args['query'] ?? []);

        // Calling by operation name: the specification supplies the method and the
        // path template, and params fill it in. Placeholders are substituted here
        // rather than by the author, so /pets/{petId} can never go out literally.
        $opId = trim((string) ($args['operation'] ?? ''));
        if ($opId !== '') {
            $op = self::findOperation($meta, $opId);
            [$path, $q] = \app\services\Config\OpenApiSpec::fill($op, (array) ($args['params'] ?? []));
            $method = $op['method'];
            $extraQuery = $q + $extraQuery;
        } elseif ($path === '') {
            throw new \Exception('Supply either a path or an operation name.');
        }

        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            throw new \Exception("Unsupported method '{$method}'.");
        }

        $args['query'] = $extraQuery;
        $url = self::joinUrl($base, $path);

        // The path must not be able to leave the connection's own host. Anything
        // else would let a pipeline borrow this credential to reach a different
        // API — or an internal one.
        if (!self::sameHost($base, $url)) {
            throw new \Exception('That path points off this connection\'s host. A connection may only call its own API.');
        }

        // Re-checked on every call, not just at connect: the name resolved to a
        // public address once, which says nothing about where it points now.
        self::assertPublicHost($url);

        if (!empty($args['query']) && is_array($args['query'])) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($args['query']);
        }
        $url = self::applyQueryAuth($url, $auth, $name, $token);

        $headers = self::authHeaders($auth, $name, $user, $token);
        $headers[] = 'Accept: application/json';
        $headers[] = self::USER_AGENT;
        foreach ((array) ($args['headers'] ?? []) as $hk => $hv) {
            // A caller-supplied header may not overwrite the credential the broker
            // just injected, and may not smuggle a second request via CRLF.
            $hk = trim((string) $hk);
            if ($hk === '' || preg_match('/[\r\n:]/', $hk)) continue;
            if (preg_match('/^(authorization)$/i', $hk)) continue;
            $headers[] = $hk . ': ' . str_replace(["\r", "\n"], '', (string) $hv);
        }

        $opts = ['headers' => $headers, 'timeout' => 30];
        if (isset($args['body']) && $method !== 'GET' && $method !== 'HEAD') {
            if (is_array($args['body'])) {
                $opts['body']  = json_encode($args['body'], JSON_UNESCAPED_SLASHES);
                $opts['headers'][] = 'Content-Type: application/json';
            } else {
                $opts['body'] = (string) $args['body'];
            }
        }

        [$status, $body, $err] = $this->http($method, $url, $opts);
        if ($err !== '') {
            throw new \Exception('Request failed: ' . $err);
        }

        $decoded = json_decode($body, true);
        return [
            'status' => $status,
            // ok is reported so a pipeline can branch on it. ConnectionStep also
            // reads a top-level error/errors, so an API that returns 200 with a
            // failure body still fails the step.
            'ok'     => $status >= 200 && $status < 300,
            'body'   => $decoded !== null ? $decoded : $body,
        ];
    }

    // -------------------------------------------------------- imported spec

    /** The operation list, filtered — what a pipeline author browses. */
    private static function listOperations(array $meta, array $args): array {
        $spec = $meta['spec'] ?? null;
        if (!is_array($spec) || empty($spec['operations'])) {
            throw new \Exception('No API specification has been imported for this connection. Reconnect it with an OpenAPI or Swagger URL.');
        }

        $search = strtolower(trim((string) ($args['search'] ?? '')));
        $limit  = max(1, min(500, (int) ($args['limit'] ?? 100)));

        $hits = [];
        foreach ($spec['operations'] as $op) {
            if ($search !== '') {
                $hay = strtolower($op['id'] . ' ' . $op['path'] . ' ' . $op['summary'] . ' ' . implode(' ', $op['tags']));
                if (strpos($hay, $search) === false) continue;
            }
            $hits[] = $op;
        }

        $total = count($hits);
        return [
            'title'      => (string) ($spec['title'] ?? ''),
            'total'      => $total,
            // Say so when the list was cut, rather than letting a truncated answer
            // read like the whole API.
            'returned'   => min($total, $limit),
            'truncated'  => $total > $limit,
            'operations' => array_slice($hits, 0, $limit),
        ];
    }

    /** Look up one operation by id, or say what is available. */
    private static function findOperation(array $meta, string $id): array {
        $ops = $meta['spec']['operations'] ?? null;
        if (!is_array($ops) || !$ops) {
            throw new \Exception("This connection has no imported specification, so it cannot resolve the operation '{$id}'. Supply a path instead.");
        }
        foreach ($ops as $op) {
            if (strcasecmp((string) $op['id'], $id) === 0) return $op;
        }
        // Offer the near misses — an operation id is easy to mistype and a bare
        // "not found" leaves the author guessing at hundreds of possibilities.
        $near = [];
        foreach ($ops as $op) {
            if (stripos((string) $op['id'], $id) !== false) $near[] = $op['id'];
            if (count($near) >= 5) break;
        }
        throw new \Exception("No operation '{$id}' in this connection's specification."
            . ($near ? ' Did you mean: ' . implode(', ', $near) . '?' : ' Call the operations tool to list them.'));
    }

    // ------------------------------------------------------------------ helpers

    /** Trim to scheme://host[:port]/path with no trailing slash. */
    private static function normalizeBase(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        return rtrim($url, '/');
    }

    private static function joinUrl(string $base, string $path): string {
        $path = trim($path);
        if ($path === '') return $base;
        // An absolute URL in `path` is honoured only so sameHost() can reject it
        // explicitly; silently treating it as relative would hide the mistake.
        if (preg_match('#^https?://#i', $path)) return $path;
        return $base . '/' . ltrim($path, '/');
    }

    private static function sameHost(string $a, string $b): bool {
        $ha = strtolower((string) parse_url($a, PHP_URL_HOST));
        $hb = strtolower((string) parse_url($b, PHP_URL_HOST));
        return $ha !== '' && $ha === $hb;
    }

    /** Auth headers for a style, as a curl-ready list. */
    private static function authHeaders(string $auth, string $name, string $user, string $key): array {
        switch ($auth) {
            case 'bearer': return ['Authorization: Bearer ' . $key];
            case 'header': return [$name . ': ' . $key];
            case 'basic':  return ['Authorization: Basic ' . base64_encode($user . ':' . $key)];
            default:       return [];   // query auth is applied to the URL; none needs nothing
        }
    }

    private static function applyQueryAuth(string $url, string $auth, string $name, string $key): string {
        if ($auth !== 'query' || $name === '' || $key === '') return $url;
        return $url . (strpos($url, '?') === false ? '?' : '&') . rawurlencode($name) . '=' . rawurlencode($key);
    }

    /**
     * Refuse to call anything that is not a public internet host.
     *
     * Core makes this request, and core can see the internal network — other
     * tenants, the Proxmox API, and the cloud metadata service. Every resolved
     * address is checked, not just the first, because a name may return both a
     * public and a private record.
     */
    private static function assertPublicHost(string $url): void {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');

        if ($host === '')                            throw new \Exception('That URL has no host.');
        if ($scheme !== 'http' && $scheme !== 'https') throw new \Exception('Only http and https URLs can be called.');

        $ips = self::resolve($host);
        if (!$ips) throw new \Exception("Could not resolve '{$host}'.");

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                // The address is named so a misconfiguration is diagnosable, but
                // the message does not confirm what else is reachable in there.
                throw new \Exception("'{$host}' resolves to {$ip}, which is not a public address. "
                                   . 'Connections may only reach APIs on the public internet.');
            }
        }
    }

    /** Every A/AAAA address for a host, including a literal IP. */
    private static function resolve(string $host): array {
        // parse_url keeps the brackets on an IPv6 literal ("[::1]"), which is not a
        // valid IP to filter_var or a resolvable name — so it would fall through to
        // DNS and be refused as unresolvable. Right outcome for ::1, wrong reason,
        // and it would equally refuse a legitimate public IPv6 written that way.
        $host = trim($host);
        if (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $ips = $v4;

        // AAAA matters: a host with a private v6 address and no v4 would otherwise
        // pass unchecked.
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $rec) {
            if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
        }
        return array_values(array_unique($ips));
    }

    private static function isPublicIp(string $ip): bool {
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE covers RFC1918, loopback,
        // link-local (169.254 — the cloud metadata address), and the reserved
        // blocks, for both v4 and v6.
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
