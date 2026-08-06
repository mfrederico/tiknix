<?php
/**
 * connection — call one of THIS instance's own connected stores (Stripe, Shopify, …)
 * via the broker. Reads the instance's conf/broker.ini ([broker] endpoint + brk_
 * key) and POSTs a JSON-RPC tools/call for `<connector>:<tool>` to core's /mcp. The
 * broker decrypts the connection server-side and returns only DATA — the instance
 * never holds the credential, and the broker key's own instance_id is the boundary,
 * so a pipeline can only ever reach ITS instance's connections.
 */

namespace app\Pipeline\Steps;

class ConnectionStep implements StepInterface {

    public static function type(): string { return 'connection'; }

    public static function schema(): array {
        return [
            'summary' => 'Call this instance\'s own connection (Stripe/Shopify/…) via the broker — auth injected server-side.',
            'fields'  => [
                ['name' => 'connector',   'label' => 'Connector', 'type' => 'text', 'required' => true, 'help' => 'The connector key, e.g. stripe, shopify.'],
                ['name' => 'tool',        'label' => 'Tool',      'type' => 'text', 'required' => true, 'help' => 'The request tool: "request" (Stripe/REST — args method,path,body) or "graphql" (Shopify — args query,variables). Named tools (list_products…) still work.'],
                ['name' => 'arguments',   'label' => 'Arguments', 'type' => 'keyval', 'help' => 'REST: {method, path, body}. GraphQL: {query, variables}. Values may reference {context.x}.'],
                ['name' => 'environment', 'label' => 'Environment', 'type' => 'select', 'options' => ['production', 'development'], 'help' => 'Which connection environment; default production.'],
                ['name' => 'account',     'label' => 'Account',     'type' => 'text',
                 'help'  => 'Which connected account, when there is more than one of this connector — the shop domain, account id or the name you gave it. Required only when it would otherwise be ambiguous; the run says which are connected.'],
                ['name' => 'timeout',     'label' => 'Timeout (s)', 'type' => 'number', 'help' => 'Optional — seconds; default 30.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $connector = (string) ($config['connector'] ?? '');
        $tool      = (string) ($config['tool'] ?? '');
        if ($connector === '' || $tool === '') return $this->err('connector and tool are required');

        $root = rtrim((string) ($run['root'] ?? dirname(__DIR__, 3)), '/');
        $ini = @parse_ini_file($root . '/conf/broker.ini', true) ?: [];
        $endpoint = (string) ($ini['broker']['endpoint'] ?? '');
        $key      = (string) ($ini['broker']['key'] ?? '');
        if ($endpoint === '' || $key === '') return $this->err('broker not configured (conf/broker.ini)');

        // The broker resolves the connection by the key's instance_id + connector +
        // environment (read from the arguments; default production). A store connected
        // in development must be reached with environment:"development".
        $brokerArgs = (array) ($config['arguments'] ?? []);
        if (!empty($config['environment'])) $brokerArgs['environment'] = (string) $config['environment'];
        // Which connected account. Sits beside environment because it answers the
        // same kind of question — not "what am I calling" but "whose".
        if (!empty($config['account'])) $brokerArgs['account'] = (string) $config['account'];
        $payload = json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => $connector . ':' . $tool, 'arguments' => (object) $brokerArgs],
        ]);
        $timeout = max(1, min(120, (int) ($config['timeout'] ?? 30)));

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $key],
        ]);
        $resp = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        if ($resp === false) return $this->err($cerr ?: 'broker request failed');

        $rpc = json_decode((string) $resp, true);
        if (isset($rpc['error'])) return $this->err('broker: ' . ($rpc['error']['message'] ?? 'error'));
        // tools/call result → content[0].text (usually JSON); expose parsed when possible.
        $text = $rpc['result']['content'][0]['text'] ?? (is_string($resp) ? $resp : '');
        $parsed = json_decode((string) $text, true);
        $isError = !empty($rpc['result']['isError']);
        $httpOk  = $httpStatus >= 200 && $httpStatus < 300;

        // A GraphQL API answers HTTP 200 and puts the failure in the BODY, so status
        // alone says a rejected query succeeded. Left unchecked the run carries on:
        // the next step maps over data that is not there, produces nothing, stores
        // nothing, and the pipeline reports success having synced zero rows.
        // Observed against a real store — "Variable $first of type Int! was provided
        // invalid value" came back as a completed step.
        $apiError = self::payloadError($parsed);

        $ok = !$isError && $httpOk && $apiError === '';
        $stderr = $isError ? (string) $text : ($apiError !== '' ? $apiError : '');

        return [
            'ok'     => $ok,
            'output' => $parsed !== null ? $parsed : $text,
            'stdout' => (string) $text, 'stderr' => $stderr, 'exit' => $ok ? 0 : 1,
        ];
    }

    /**
     * An error the API reported inside a 200 body, or '' when the payload is clean.
     *
     * Only TOP-LEVEL keys are inspected. GraphQL's spec puts request-level failures in
     * `errors`; a mutation's own `userErrors` are nested and are domain data, not a
     * transport failure, so they stay the pipeline author's business.
     */
    private static function payloadError($parsed): string {
        if (!is_array($parsed)) return '';

        // GraphQL: { "errors": [ {message, locations, ...}, ... ] }
        if (!empty($parsed['errors']) && is_array($parsed['errors'])) {
            $msgs = [];
            foreach ($parsed['errors'] as $e) {
                if (is_array($e)) { $msgs[] = (string) ($e['message'] ?? json_encode($e, JSON_UNESCAPED_SLASHES)); }
                else { $msgs[] = (string) $e; }
            }
            return 'API error: ' . implode('; ', array_slice($msgs, 0, 5));
        }

        // REST convention: { "error": {...} } or { "error": "message" }.
        if (isset($parsed['error']) && !empty($parsed['error'])) {
            $e = $parsed['error'];
            if (is_array($e)) return 'API error: ' . (string) ($e['message'] ?? json_encode($e, JSON_UNESCAPED_SLASHES));
            return 'API error: ' . (string) $e;
        }

        return '';
    }

    private function err(string $m): array {
        return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $m, 'exit' => 1];
    }
}
