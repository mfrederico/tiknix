<?php
/**
 * Pipeline\MemberModel — a pipeline agent step on the project OWNER's model connection
 * (MODEL_CONNECTIONS_PLAN.md phase 4).
 *
 * The key never reaches this app. The owner's connection lives encrypted on core; this asks
 * core to make the call over the app's own broker key (conf/broker.ini — the same
 * credential it reaches its connections and the concept catalog with):
 *
 *   GET  /brokerinfo/modelconnections      what the owner opted in (names + models, no keys)
 *   POST /brokerinfo/modelcall             {connection, model, system, prompt, …} → {job}
 *   GET  /brokerinfo/modelresult?job=      poll until done | failed
 *
 * Core answers the POST at once and finishes the call after the response, so a long
 * generation is not cut off by a 60 s proxy timeout. Every failure carries what core or
 * the endpoint said; nothing here substitutes another model or another key.
 */

namespace app\Pipeline;

class MemberModel {

    /** @var callable(string $method, string $url, array $headers, ?string $body, int $timeout): array{0:int,1:string} */
    private $http;
    /** @var callable(int $seconds): void */
    private $sleep;
    /** @var callable(): int */
    private $now;
    private string $base;
    private string $key;

    public function __construct(string $base, string $key, ?callable $http = null, ?callable $sleep = null, ?callable $now = null) {
        $this->base  = rtrim($base, '/');
        $this->key   = $key;
        $this->http  = $http ?? [self::class, 'httpCall'];
        $this->sleep = $sleep ?? fn(int $s) => sleep($s);
        $this->now   = $now ?? fn() => time();
    }

    /** This install's broker (conf/broker.ini). Throws naming the file when it is missing or incomplete. */
    public static function forInstall(?string $root = null): self {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/');
        $file = "{$root}/conf/broker.ini";
        $ini = is_file($file) ? parse_ini_file($file, true) : false;
        if ($ini === false) throw new \RuntimeException("Owner's model connections need {$file} ([broker] endpoint + key) — this install has none, so it cannot reach core.");
        $u = parse_url((string) ($ini['broker']['endpoint'] ?? ''));
        $key = (string) ($ini['broker']['key'] ?? '');
        if ($key === '' || empty($u['scheme']) || empty($u['host'])) throw new \RuntimeException("{$file}: [broker] endpoint and key are both required.");
        return new self($u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''), $key);
    }

    /** @return array<int,array{id:int,name:string,protocol:string,models:array,ready:bool,problems:array}> */
    public function connections(): array {
        $d = $this->json('GET', '/brokerinfo/modelconnections', null, 30);
        return array_values((array) ($d['connections'] ?? []));
    }

    /**
     * One call on the owner's connection $connectionId.
     *
     * @return array{ok:bool,text:string,usage:array,error:string,model:string,job:int}
     */
    public function call(int $connectionId, string $model, string $system, string $prompt, int $timeout, int $maxTokens = 4096): array {
        $out = ['ok' => false, 'text' => '', 'usage' => [], 'error' => '', 'model' => $model, 'job' => 0];
        try {
            $start = $this->json('POST', '/brokerinfo/modelcall', json_encode([
                'connection' => $connectionId, 'model' => $model, 'system' => $system,
                'prompt' => $prompt, 'timeout' => $timeout, 'max_tokens' => $maxTokens,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 30);
            $job = (int) ($start['job'] ?? 0);
            if ($job <= 0) throw new \RuntimeException('core accepted the call but returned no job id');
            $out['job'] = $job;
            $deadline = ($this->now)() + $timeout + 30;   // core's own timeout, plus the time to write the result
            $wait = 1;
            while (true) {
                $r = $this->json('GET', '/brokerinfo/modelresult?job=' . $job, null, 30);
                $status = (string) ($r['status'] ?? '');
                if ($status === 'done' || $status === 'failed') {
                    return ['ok' => $status === 'done', 'text' => (string) ($r['text'] ?? ''), 'usage' => (array) ($r['usage'] ?? []),
                            'error' => (string) ($r['error'] ?? ''), 'model' => (string) ($r['model'] ?? $model), 'job' => $job];
                }
                if ($status !== 'running') throw new \RuntimeException("core reported an unknown status '{$status}' for job {$job}");
                if (($this->now)() >= $deadline) throw new \RuntimeException("no result for job {$job} after {$timeout}s (+30s); core never finished it — see core's log for 'Pipeline model call'");
                ($this->sleep)($wait);
                $wait = min(5, $wait + 1);
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
    }

    /** A broker request; throws with core's own message on anything but 200 + JSON. */
    private function json(string $method, string $path, ?string $body, int $timeout): array {
        [$code, $resp] = ($this->http)($method, $this->base . $path,
            ['Authorization: Bearer ' . $this->key, 'Accept: application/json', 'Content-Type: application/json'], $body, $timeout);
        $d = json_decode((string) $resp, true);
        if ($code !== 200 || !is_array($d)) {
            $msg = is_array($d) ? (string) ($d['message'] ?? $d['error'] ?? '') : '';
            throw new \RuntimeException("core ({$this->base}{$path}) answered HTTP {$code}" . ($msg !== '' ? ": {$msg}" : ''));
        }
        return $d;
    }

    /** @return array{0:int,1:string} */
    public static function httpCall(string $method, string $url, array $headers, ?string $body, int $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) $body]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) return [0, json_encode(['message' => 'connection failed: ' . curl_error($ch)])];
        return [$code, (string) $resp];   // no curl_close(): it throws in a PHP 8.5 web handler
    }
}
