<?php
/**
 * AbstractConnector — shared plumbing for connectors: control-plane credential
 * loading (conf/<key>.ini) and a tiny cURL helper. Concrete connectors implement
 * key(), meta(), authorizeUrl() and exchangeCode().
 */

namespace app\services\connectors;

abstract class AbstractConnector implements ConnectorInterface {

    /** Full conf/<key>.ini contents (control plane only). */
    protected function config(): array {
        // services/connectors/ -> services/ -> project root
        $file = dirname(__DIR__, 2) . '/conf/' . $this->key() . '.ini';
        return @parse_ini_file($file, true) ?: [];
    }

    /** The [oauth] section of conf/<key>.ini. */
    protected function oauth(): array {
        $c = $this->config();
        return $c['oauth'] ?? [];
    }

    public function isConfigured(): bool {
        $o = $this->oauth();
        return !empty($o['client_id']) && !empty($o['client_secret']);
    }

    /** The server-wide scope list, when this connector has one. */
    public function defaultScopes(): string {
        return (string) ($this->oauth()['scopes'] ?? '');
    }

    /**
     * Does this connector ONLY ever use the customer's own app?
     *
     * True means there is no platform app to fall back to and none should be invented.
     * Shopify is the case: a store is always reached through the merchant's own custom
     * app, so a shared credential is not a convenience here — it is a second way of doing
     * the one thing, and the failure it produces (a store bound to an app the merchant
     * does not control) is worse than being asked for a key.
     */
    public function requiresOwnApp(): bool {
        return false;
    }

    /**
     * The provider app to authenticate AS, for ONE connection.
     *
     * A customer's account belongs to the customer's project, and so does the app that
     * reaches it: their client id, their scopes, their billing, their callback on their
     * own domain. conf/<key>.ini is the fallback for a customer who has not made one —
     * a convenience, not the model.
     *
     * Taken as a PAIR or not at all. Half of one is refused rather than completed from
     * the ini, because pairing a customer's client id with the server's secret fails
     * verification at the provider and reads as "they rejected us" instead of "you mixed
     * two apps".
     *
     * @param array $ctx ['app' => ['client_id' => …, 'client_secret' => …]]
     */
    protected function appFor(array $ctx): array {
        $global = $this->oauth();
        $custom = is_array($ctx['app'] ?? null) ? $ctx['app'] : [];
        $id     = trim((string) ($custom['client_id'] ?? ''));
        $secret = trim((string) ($custom['client_secret'] ?? ''));

        if ($id === '' && $secret === '' && $this->requiresOwnApp()) {
            throw new \Exception(ucfirst($this->key())
                . ' connects through the merchant\'s own app — an API key and secret are required.');
        }
        if ($id === '' && $secret === '') return $global;
        if ($id === '' || $secret === '') {
            throw new \Exception('A custom ' . ucfirst($this->key())
                . ' app needs BOTH a client id/API key and a secret.');
        }
        return ['client_id' => $id, 'client_secret' => $secret] + $global;
    }

    /**
     * Can this connector authenticate at all, given this context?
     *
     * isConfigured() asks only about the server-wide ini, which is the wrong question on a
     * project: conf/<key>.ini is scrubbed empty at provision so a customer's project can
     * never hold the platform's secret, so the answer there is always no. Asked per
     * attempt, a customer's own app is a perfectly good yes.
     */
    public function isConfiguredFor(array $ctx): bool {
        try {
            $a = $this->appFor($ctx);
        } catch (\Throwable $e) {
            return false;
        }
        return !empty($a['client_id']) && !empty($a['client_secret']);
    }

    /** What this provider puts BETWEEN scopes. Shopify commas; GitHub and Google space. */
    protected function scopeSeparator(): string { return ','; }

    /**
     * What ONE scope may look like for this provider. Overridden where the grammar is
     * known: the permissive default has to admit GitHub's repo:status and Google's URL
     * scopes, which means it also admits READ_PRODUCTS for Shopify — valid-looking, not a
     * real scope, and rejected only later by the provider with nothing useful said. A
     * connector that knows its own grammar should say so and fail here instead.
     */
    protected function scopePattern(): string {
        return '/^[A-Za-z0-9_.:\-\/]+$/';
    }

    /**
     * The scopes to REQUEST for one connection.
     *
     * A customer's app is configured with its own scope set, and asking for the server's
     * list against it gets the whole authorisation rejected — so a per-connection app
     * without per-connection scopes is only half useful.
     *
     * Empty means "not specified", not "ask for nothing": `$ctx['scopes'] ?? default`
     * would pass an empty form field through as a literal empty scope parameter, which
     * providers read as a request for no access at all.
     *
     * Validated PER TOKEN, because this is interpolated into the URL the customer is
     * redirected to. Stripping whitespace globally first turns "read products" into
     * "readproducts" — which passes any pattern, is not a scope that exists, and fails at
     * the provider saying nothing useful. Space around a separator is a typo worth
     * forgiving; a space inside a name is a different scope.
     */
    public function scopesFor(array $ctx): string {
        $want = trim((string) ($ctx['scopes'] ?? ''));
        if ($want === '') return $this->defaultScopes();

        /* Split on EITHER separator. Providers disagree — Shopify comma-delimits, GitHub
           space-delimits and its own docs show "repo read:user" — so a customer pasting the
           list straight from the provider's documentation must not be told it is invalid.
           Rejoined with the separator THIS provider expects, so accepting both on input
           does not send a malformed list onward. */
        $parts = preg_split('/[\s,]+/', $want, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$parts) throw new \Exception('No scopes given.');
        foreach ($parts as $p) {
            if ($p === '' || !preg_match($this->scopePattern(), $p)) {
                throw new \Exception('Scopes must be a list separated by commas or spaces'
                    . " — \"$p\" is not a valid scope.");
            }
        }
        return implode($this->scopeSeparator(), $parts);
    }

    /** Connectors are OAuth-only by default; api_key connectors override this. */
    public function validateApiKey(string $key, array $opts = []): array {
        throw new \Exception('Connector "' . $this->key() . '" does not support API-key auth.');
    }

    /** Connectors expose no broker tools by default; override to add them. */
    public function brokerTools(): array {
        return [];
    }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        throw new \Exception('Connector "' . $this->key() . '" exposes no broker tools.');
    }

    /** Non-payment connectors don't support checkout; payment providers override. */
    public function createCheckout($conn, string $token, array $order): array {
        throw new \Exception('Connector "' . $this->key() . '" is not a payment provider.');
    }

    /** Non-payment connectors have no webhook orders; payment providers override. */
    public function webhookOrder($conn, string $token, string $rawBody, array $headers, string $secret = ''): ?array {
        return null;
    }

    /** Non-payment connectors have no subscriptions; payment providers override. */
    public function subscriptionFromEvent($conn, string $token, string $rawBody, array $headers, string $secret = ''): ?array {
        return null;
    }

    /** Non-payment connectors have no billing portal; payment providers override. */
    public function billingPortalUrl($conn, string $token, string $customerId, string $returnUrl): string {
        throw new \Exception('Connector "' . $this->key() . '" has no billing portal.');
    }

    /** Non-social connectors have no feed; Social-category connectors override. */
    public function fetchFeed($conn, string $token, array $opts = []): array {
        throw new \Exception('Connector "' . $this->key() . '" is not a social feed provider.');
    }

    /** Most tokens don't expire; connectors with expiring tokens (Meta) override. */
    public function refreshToken($conn, string $token): ?array {
        return null;
    }

    /** Attempts (including the first) when a provider asks us to slow down. */
    protected const RATE_LIMIT_ATTEMPTS = 3;

    /** Never sleep longer than this for one retry, whatever Retry-After claims. */
    protected const RATE_LIMIT_MAX_WAIT = 30;

    /**
     * Minimal cURL request. Returns [httpStatus, body, transportError].
     *
     * BEING POLITE. A pipeline paginating a large store is the heaviest thing this
     * codebase does to somebody else's API, and the difference between a good
     * citizen and a bad one is entirely in what happens on a 429. Retrying
     * immediately, or not at all, are both rude — the first hammers a provider that
     * just asked for room, the second turns a routine throttle into a failed sync.
     *
     * So: when a provider says 429 (or 503, which is the same request under load),
     * wait as long as it ASKED for via Retry-After and try again, a bounded number
     * of times. Retry-After is honoured rather than guessed at, because the provider
     * knows when its window resets and we do not.
     *
     * Writes are retried ONLY on 429. A 429 means the request was refused before
     * doing anything; a 503 may well have been processed before the response was
     * lost, and replaying a POST that already created an invoice is a worse outcome
     * than failing loudly.
     *
     * @param array $opts ['headers' => string[], 'body' => string, 'timeout' => int]
     */
    protected function http(string $method, string $url, array $opts = []): array {
        $method     = strtoupper($method);
        $idempotent = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

        for ($attempt = 1; ; $attempt++) {
            [$status, $body, $err, $headers] = $this->httpOnce($method, $url, $opts);

            $throttled = $status === 429 || ($status === 503 && $idempotent);
            if (!$throttled || $attempt >= static::RATE_LIMIT_ATTEMPTS) {
                return [$status, $body, $err];
            }

            $wait = self::retryAfter($headers, $attempt);
            \Flight::get('log')?->info('Connector backing off at a provider\'s request', [
                'connector' => $this->key(), 'status' => $status,
                'attempt' => $attempt, 'wait_seconds' => $wait, 'host' => parse_url($url, PHP_URL_HOST),
            ]);
            sleep($wait);
        }
    }

    /** One request, with response headers captured so Retry-After can be read. */
    private function httpOnce(string $method, string $url, array $opts): array {
        $ch = curl_init($url);

        $headers = [];
        $co = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? 20),
            CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
            CURLOPT_CUSTOMREQUEST  => $method,   // supports GET/POST/PUT/PATCH/DELETE
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $p = strpos($line, ':');
                if ($p > 0) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ];
        if ($method !== 'GET' && $method !== 'HEAD' && isset($opts['body'])) {
            $co[CURLOPT_POSTFIELDS] = $opts['body'];
        }
        curl_setopt_array($ch, $co);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // The transport error, THIRD so existing [$status, $body] callers are
        // unaffected. Without it every failure before a response — DNS, TLS,
        // connect refused, timeout — arrives as the same bare "HTTP 0", and the
        // caller reports "sent no usable answer" for four unrelated causes with
        // nothing to tell them apart. curl already knows which; this stops
        // throwing that away.
        $err = curl_errno($ch) ? curl_strerror(curl_errno($ch)) . ': ' . curl_error($ch) : '';

        // No curl_close(): deprecated in PHP 8.5 and it throws in a web handler.
        return [$status, is_string($body) ? $body : '', $err, $headers];
    }

    /**
     * How long to wait, in seconds.
     *
     * Retry-After comes in two forms and both appear in the wild: a number of
     * seconds, or an HTTP date. Some providers instead send only a reset timestamp
     * (GitHub's x-ratelimit-reset), which is worth reading for the same reason.
     * With nothing to go on, back off exponentially rather than retrying at the
     * same rate that just got refused.
     */
    private static function retryAfter(array $headers, int $attempt): int {
        $raw = trim((string) ($headers['retry-after'] ?? ''));

        if ($raw !== '' && ctype_digit($raw)) {
            $wait = (int) $raw;
        } elseif ($raw !== '' && ($ts = strtotime($raw)) !== false) {
            $wait = $ts - time();
        } elseif (!empty($headers['x-ratelimit-reset']) && ctype_digit((string) $headers['x-ratelimit-reset'])) {
            $wait = (int) $headers['x-ratelimit-reset'] - time();
        } else {
            $wait = 2 ** $attempt;                       // 2s, 4s
        }

        // Clamped both ways: never a busy-loop, never a step that hangs for the
        // twenty minutes a provider might technically be entitled to ask for.
        return max(1, min($wait, static::RATE_LIMIT_MAX_WAIT));
    }
}
