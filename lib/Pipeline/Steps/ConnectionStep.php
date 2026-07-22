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
            'summary' => 'Call this instance\'s own connection (Stripe/Shopify/…) via the broker.',
            'config'  => [
                'connector'   => 'string — the connector key (e.g. stripe, shopify)',
                'tool'        => 'string — the broker tool (e.g. list_products, create_checkout_session)',
                'arguments'   => 'object (optional) — tool arguments',
                'environment' => 'string (optional) — production | development (default production)',
                'timeout'     => 'int (optional) — seconds, default 30',
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
        curl_close($ch);
        if ($resp === false) return $this->err($cerr ?: 'broker request failed');

        $rpc = json_decode((string) $resp, true);
        if (isset($rpc['error'])) return $this->err('broker: ' . ($rpc['error']['message'] ?? 'error'));
        // tools/call result → content[0].text (usually JSON); expose parsed when possible.
        $text = $rpc['result']['content'][0]['text'] ?? (is_string($resp) ? $resp : '');
        $parsed = json_decode((string) $text, true);
        $isError = !empty($rpc['result']['isError']);
        return [
            'ok'     => !$isError && $httpStatus >= 200 && $httpStatus < 300,
            'output' => $parsed !== null ? $parsed : $text,
            'stdout' => (string) $text, 'stderr' => $isError ? (string) $text : '', 'exit' => $isError ? 1 : 0,
        ];
    }

    private function err(string $m): array {
        return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $m, 'exit' => 1];
    }
}
