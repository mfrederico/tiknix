<?php
/**
 * http — make a REST call. Returns { status, body } as output (body parsed as JSON
 * when the response is JSON). Subsumes myctobot's connector-specific HTTP steps;
 * an instance's OWN connections go through the `connection` step (later phase),
 * not raw http.
 */

namespace app\Pipeline\Steps;

class HttpStep implements StepInterface {

    public static function type(): string { return 'http'; }

    public static function schema(): array {
        return [
            'summary' => 'Call an HTTP endpoint and capture the response.',
            'fields'  => [
                ['name' => 'method',  'label' => 'Method', 'type' => 'select', 'options' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'help' => 'HTTP method; default GET.'],
                ['name' => 'url',     'label' => 'URL',     'type' => 'text',     'required' => true, 'help' => 'The request URL.'],
                ['name' => 'headers', 'label' => 'Headers', 'type' => 'keyval',   'help' => 'Optional — header name → value.'],
                ['name' => 'body',    'label' => 'Body',    'type' => 'textarea', 'help' => 'Optional — request body; a JSON object is sent as JSON.'],
                ['name' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'help' => 'Optional — seconds; default 30.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $url = (string) ($config['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'invalid url', 'exit' => 1];
        $method  = strtoupper((string) ($config['method'] ?? 'GET'));
        $timeout = max(1, min(300, (int) ($config['timeout'] ?? 30)));

        $headers = [];
        foreach ((array) ($config['headers'] ?? []) as $k => $v) $headers[] = $k . ': ' . $v;
        $body = $config['body'] ?? null;
        if (is_array($body)) { $body = json_encode($body); if (!$this->hasHeader($headers, 'content-type')) $headers[] = 'Content-Type: application/json'; }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        if ($body !== null && $method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $err ?: 'request failed', 'exit' => 1];
        $resp = (string) $resp;
        $decoded = json_decode($resp, true);
        return [
            'ok'     => $status >= 200 && $status < 400,
            'output' => ['status' => $status, 'body' => $decoded !== null ? $decoded : $resp],
            'stdout' => $resp, 'stderr' => '', 'exit' => $status >= 200 && $status < 400 ? 0 : 1,
        ];
    }

    private function hasHeader(array $headers, string $name): bool {
        foreach ($headers as $h) if (stripos($h, $name . ':') === 0) return true;
        return false;
    }
}
