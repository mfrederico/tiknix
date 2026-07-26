<?php
/**
 * publish — hand this instance's publish over to the control plane.
 *
 * Every publish target needs a credential the instance must never hold: a GitHub PAT, a
 * hypervisor token, an SSH key. So the step ships no credential of its own. It presents
 * the instance's `brk_` broker key (conf/broker.ini — the same one ConnectionStep uses)
 * to core's /publish/run, and core resolves WHICH instance from that key alone. A
 * pipeline can therefore only ever publish the instance it belongs to; there is no
 * instance id in the request to tamper with.
 *
 * This is what the Publisher writes into a project's pipelines/publish.json, which is why
 * a publish schedules, retries and step-traces like any other pipeline instead of being a
 * bespoke deploy button somewhere.
 */

namespace app\Pipeline\Steps;

class PublishStep implements StepInterface {

    public static function type(): string { return 'publish'; }

    public static function schema(): array {
        return [
            'summary' => 'Publish this instance to a configured target (GitHub PR, Tiknix Hosted) — credentials stay on the control plane.',
            'fields'  => [
                ['name' => 'target',  'label' => 'Target',  'type' => 'text', 'required' => true, 'help' => 'The publish target key, e.g. github-pr, tiknix-hosted.'],
                ['name' => 'op',      'label' => 'Operation', 'type' => 'select', 'options' => ['deploy', 'refresh', 'status'], 'help' => 'deploy (default) publishes; refresh re-applies; status only reports.'],
                ['name' => 'config',  'label' => 'Target config', 'type' => 'keyval', 'help' => 'Optional per-target settings (e.g. domain). Never credentials.'],
                ['name' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'help' => 'Optional — seconds; default 120. Container deploys can take a minute.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $target = (string) ($config['target'] ?? '');
        if ($target === '') return $this->err('target is required');
        $op = strtolower((string) ($config['op'] ?? 'deploy'));

        $root = rtrim((string) ($run['root'] ?? dirname(__DIR__, 3)), '/');
        $ini  = @parse_ini_file($root . '/conf/broker.ini', true) ?: [];
        $endpoint = (string) ($ini['broker']['endpoint'] ?? '');
        $key      = (string) ($ini['broker']['key'] ?? '');
        if ($endpoint === '' || $key === '') return $this->err('broker not configured (conf/broker.ini)');

        // The broker endpoint is core's /mcp/message; the publish door is on the same
        // origin. Deriving it keeps ONE piece of core-location config per instance.
        $parts = parse_url($endpoint);
        if (empty($parts['host'])) return $this->err('broker endpoint is not a URL');
        $core = ($parts['scheme'] ?? 'https') . '://' . $parts['host']
              . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $payload = json_encode([
            'target' => $target,
            'op'     => $op,
            'config' => (object) ((array) ($config['config'] ?? [])),
        ]);
        $timeout = max(1, min(600, (int) ($config['timeout'] ?? 120)));

        $ch = curl_init($core . '/publish/run');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json',
                                   'Authorization: Bearer ' . $key],
        ]);
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        if ($resp === false) return $this->err($cerr ?: 'publish request failed');

        $j  = json_decode((string) $resp, true);
        $ok = $status >= 200 && $status < 300 && !empty($j['success']);
        $msg = (string) ($j['message'] ?? ('HTTP ' . $status));
        // Surface the driver's own steps as the step log, so a publish reads in the
        // step-trace debugger the way it reads in the Connections deploy output.
        $lines = array_merge([$msg], (array) ($j['data']['steps'] ?? []));

        return [
            'ok'     => $ok,
            'output' => $j['data'] ?? null,
            'stdout' => implode("\n", array_map('strval', $lines)),
            'stderr' => $ok ? '' : $msg,
            'exit'   => $ok ? 0 : 1,
        ];
    }

    private function err(string $m): array {
        return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $m, 'exit' => 1];
    }
}
