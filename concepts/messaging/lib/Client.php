<?php
namespace app\concepts\messaging;

/**
 * Client — how this app talks to the messaging service (messaging.tiknix.com, design Part 2). The app
 * signs every call with its own broker key (conf/broker.ini, the key core issued it); the service knows
 * the app's tenant from the key, never from the body, and this app's SITE from the X-Messaging-Site header
 * (Client::site(): the host). Where the service is, core says once (/brokerinfo/messaging) and the answer
 * is kept in the setting messaging_service_url.
 *
 * A call that fails — no answer, a refusal, {ok: false} — throws ServiceException with the service's
 * own words and status. Nothing here turns a failure into an empty answer. Tests set $transport.
 */
class ServiceException extends \RuntimeException {
    public function __construct(public int $status, public array $body, string $message) { parent::__construct($message); }
}

class Client {

    public const URL_SETTING = 'messaging_service_url';
    public const TIMEOUT = 30;

    /** @var null|callable(string $method, string $path, ?array $body): array{code:int,body:array} a fake service, for tests */
    public static $transport = null;

    /** The service's address: the setting, else core's answer (kept). */
    public static function url(): string {
        $u = rtrim(trim((string) \Flight::getSetting(self::URL_SETTING, 0)), '/');
        if ($u !== '') return $u;
        [$core, $key] = self::broker();
        $r = self::http('GET', $core . '/brokerinfo/messaging', null, $key);
        $u = rtrim((string) ($r['body']['data']['url'] ?? ''), '/');   // core's jsonSuccess answers {success, data: {url, project}, message}
        if ($r['code'] !== 200 || $u === '') {
            throw new ServiceException($r['code'], $r['body'], "Tiknix did not say where the messaging service is ({$r['code']}): "
                . (string) ($r['body']['error'] ?? $r['body']['message'] ?? json_encode($r['body'])) . " — is Messaging switched on for this project's owner?");
        }
        \Flight::setSetting(self::URL_SETTING, $u, 0);
        return $u;
    }

    /** @return array{0:string,1:string} core's address and this app's broker key, from conf/broker.ini */
    private static function broker(): array {
        $file = \app\Paths::root() . '/conf/broker.ini';
        $ini = is_file($file) ? (@parse_ini_file($file, true) ?: []) : [];
        $endpoint = (string) ($ini['broker']['endpoint'] ?? ''); $key = (string) ($ini['broker']['key'] ?? '');
        if ($endpoint === '' || $key === '') throw new \RuntimeException("{$file} has no [broker] endpoint and key: this app cannot reach Tiknix, so it cannot reach the messaging service");
        $p = parse_url($endpoint);
        if (empty($p['host'])) throw new \RuntimeException("{$file}: the [broker] endpoint is not an address");
        return [($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $key];
    }

    /** One call to /api/v1/{path}; the decoded answer (its ok is true). @throws ServiceException */
    public static function call(string $method, string $path, ?array $body = null): array {
        $path = ltrim($path, '/');
        if (self::$transport !== null) {
            $r = (self::$transport)($method, $path, $body);
        } else {
            [, $key] = self::broker();
            $r = self::http($method, self::url() . '/api/v1/' . $path, $body, $key);
        }
        if ($r['code'] >= 300 || empty($r['body']['ok'])) {
            throw new ServiceException($r['code'], $r['body'], "messaging: {$method} /api/v1/{$path} answered {$r['code']}: " . (string) ($r['body']['error'] ?? $r['body']['raw'] ?? 'no answer'));
        }
        return $r['body'];
    }

    /**
     * The site this app IS, for the service: people, kinds of message and audiences are kept per site (a database per
     * domain). The host of the request, else the app's baseurl — the hourly pipeline runs per host.
     */
    public static function site(): string {
        // Host::current(): the host this process serves — the visitor's host on the web, TIKNIX_HOST / --host in a CLI run
        // (the hourly pipeline runs once per host). The config's baseurl only when neither says.
        $host = class_exists('\\app\\Host') && is_callable(['\\app\\Host', 'current']) ? (string) \app\Host::current() : '';
        if ($host === '') $host = (string) parse_url((string) \Flight::get('app.baseurl'), PHP_URL_HOST);
        if ($host === '') throw new \RuntimeException('Missing [app] baseurl in conf/config.ini: the messaging service needs to know which site this is.');
        return strtolower($host);
    }

    public static function get(string $path, array $query = []): array { return self::call('GET', $path . ($query ? '?' . http_build_query($query) : '')); }
    public static function post(string $path, array $body = []): array { return self::call('POST', $path, $body); }
    public static function put(string $path, array $body): array { return self::call('PUT', $path, $body); }
    public static function patch(string $path, array $body): array { return self::call('PATCH', $path, $body); }
    public static function delete(string $path): array { return self::call('DELETE', $path); }

    /** The field a 422 names ("subject: write one"), for a form's problems; '' when it names none. */
    public static function field(ServiceException $e): string {
        return preg_match('/^([a-z_]+): /', (string) ($e->body['error'] ?? ''), $m) ? $m[1] : '';
    }

    /** What a refusal says, without the field name. */
    public static function said(ServiceException $e): string {
        return preg_replace('/^[a-z_]+: /', '', (string) ($e->body['error'] ?? $e->getMessage()));
    }

    /** @return array{code:int,body:array} */
    private static function http(string $method, string $url, ?array $body, string $key): array {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $key, 'X-Messaging-Site: ' . self::site()];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOSIGNAL => true];
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES); }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);   // no curl_close(): deprecated in PHP 8.5, and the handle goes with the variable
        if ($out === false) return ['code' => 0, 'body' => ['error' => "{$method} {$url}: " . ($err !== '' ? $err : 'no answer')]];
        $j = json_decode((string) $out, true);
        return ['code' => $code, 'body' => is_array($j) ? $j : ['raw' => mb_substr((string) $out, 0, 300)]];
    }
}
