<?php
/**
 * mcp_call — call ANY MCP tool over JSON-RPC (composability: pipelines calling
 * tools). For this instance's OWN connections use the `connection` step; use mcp_call
 * for external MCP servers or a specific endpoint/token.
 */

namespace app\Pipeline\Steps;

class McpCallStep implements StepInterface {

    public static function type(): string { return 'mcp_call'; }

    public static function schema(): array {
        return [
            'summary' => 'Call an MCP tool at an endpoint via JSON-RPC tools/call.',
            'fields'  => [
                ['name' => 'endpoint',  'label' => 'Endpoint',  'type' => 'text', 'required' => true, 'help' => 'The MCP /message URL.'],
                ['name' => 'token',     'label' => 'Token',     'type' => 'text', 'help' => 'Optional — bearer token.'],
                ['name' => 'tool',      'label' => 'Tool',      'type' => 'text', 'required' => true, 'help' => 'The tool name, e.g. server:tool.'],
                ['name' => 'arguments', 'label' => 'Arguments', 'type' => 'keyval', 'help' => 'Optional — tool arguments.'],
                ['name' => 'timeout',   'label' => 'Timeout (s)', 'type' => 'number', 'help' => 'Optional — seconds; default 60.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $endpoint = (string) ($config['endpoint'] ?? '');
        $tool     = (string) ($config['tool'] ?? '');
        if (!preg_match('#^https?://#i', $endpoint) || $tool === '') {
            return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'endpoint and tool are required', 'exit' => 1];
        }
        $payload = json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => $tool, 'arguments' => (object) ((array) ($config['arguments'] ?? []))],
        ]);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($config['token'])) $headers[] = 'Authorization: Bearer ' . $config['token'];
        $timeout = max(1, min(300, (int) ($config['timeout'] ?? 60)));

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers]);
        $resp = curl_exec($ch);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $cerr ?: 'request failed', 'exit' => 1];

        $rpc = json_decode((string) $resp, true);
        if (isset($rpc['error'])) return ['ok' => false, 'output' => null, 'stdout' => (string) $resp, 'stderr' => (string) ($rpc['error']['message'] ?? 'error'), 'exit' => 1];
        $text = $rpc['result']['content'][0]['text'] ?? $resp;
        $parsed = json_decode((string) $text, true);
        $isError = !empty($rpc['result']['isError']);
        return ['ok' => !$isError, 'output' => $parsed !== null ? $parsed : $text,
                'stdout' => (string) $text, 'stderr' => $isError ? (string) $text : '', 'exit' => $isError ? 1 : 0];
    }
}
