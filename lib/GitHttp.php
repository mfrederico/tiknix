<?php
/**
 * GitHttp — serve an instance's repository over Git's smart-HTTP protocol, READ-ONLY,
 * so a tenant container can clone/fetch its own code from core.
 *
 * WHY THIS EXISTS: tenant containers run on the Proxmox node, not on core, and PVE
 * refuses bind mounts to any API token (root@pam only). A container therefore cannot be
 * handed a directory of code — it has to fetch it over the network, including on first
 * boot. Instances are already git clones whose origin is core, so this just makes that
 * existing relationship reachable from the node's network. No GitHub round trip, and the
 * code never leaves the control plane.
 *
 *   git clone https://x:<deploy-token>@core/git/<slug>.git
 *
 * SECURITY MODEL — this endpoint is reachable unauthenticated (authcontrol git::* = 101)
 * and gates itself, exactly like controls/Mcp.php:
 *   - ONLY git-upload-pack is served. receive-pack is never wired up, so a client can
 *     read but can never push. The service name is checked against an allowlist rather
 *     than passed through to the shell.
 *   - The slug must resolve to an `active` row in the instance registry, and the repo
 *     path is rebuilt from that row — a request never contributes a filesystem path.
 *   - Auth is HTTP Basic; any username, password must equal that instance's
 *     deploy_token, compared with hash_equals(). One token per instance, so revoking
 *     one tenant's access cannot affect another's.
 */
namespace app;

use RedBeanPHP\R;

class GitHttp {

    /** The only git service this endpoint will ever run. Read-only by construction. */
    const SERVICE = 'git-upload-pack';

    /** Where provisioned instances live, mirroring ProvisionService::instanceDir(). */
    const INSTANCE_ROOT = '/var/www/html/default';

    /**
     * Resolve a URL slug to an instance repo on disk.
     * @return array{ok:bool, dir?:string, bean?:object, error?:string, code?:int}
     */
    public static function resolve(string $slug): array {
        $slug = strtolower(trim($slug));
        // Same shape ProvisionService mints: {base}-{hash}, path-safe by construction.
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 64) {
            return ['ok' => false, 'error' => 'Invalid instance slug', 'code' => 404];
        }
        $inst = R::findOne('instance', 'slug = ?', [$slug]);
        if (!$inst || !$inst->id)                  return ['ok' => false, 'error' => 'Unknown instance', 'code' => 404];
        if ((string) $inst->status !== 'active')   return ['ok' => false, 'error' => 'Instance is not active', 'code' => 403];

        $dir = self::INSTANCE_ROOT . '/' . $slug . '.' . self::namespaceOf();
        if (!is_dir($dir . '/.git'))               return ['ok' => false, 'error' => 'Instance has no repository', 'code' => 404];

        return ['ok' => true, 'dir' => $dir, 'bean' => $inst];
    }

    /** App namespace for instance dirs ("tiknix" from https://tiknix.com). */
    private static function namespaceOf(): string {
        $host = strtolower((string) (parse_url((string) \Flight::get('app.baseurl'), PHP_URL_HOST) ?: ''));
        $ns   = preg_replace('/\.com$/', '', $host);
        return ($ns !== '' && preg_match('/^[a-z0-9.-]+$/', $ns)) ? $ns : 'tiknix';
    }

    /**
     * The instance's deploy token, minted on first use. Read-only capability: it grants
     * clone/fetch of exactly one instance's repo and nothing else.
     */
    public static function deployToken(object $inst): string {
        if (empty($inst->deployToken)) {
            $inst->deployToken = bin2hex(random_bytes(24));
            R::store($inst);
        }
        return (string) $inst->deployToken;
    }

    /** Verify HTTP Basic credentials against the instance's deploy token. */
    public static function authorize(object $inst): bool {
        $supplied = self::basicPassword();
        if ($supplied === '') return false;
        return hash_equals(self::deployToken($inst), $supplied);
    }

    /**
     * Password from HTTP Basic. Apache/CGI does not always populate PHP_AUTH_PW, so fall
     * back to the raw header (including the REDIRECT_ copy mod_rewrite leaves behind).
     */
    private static function basicPassword(): string {
        if (isset($_SERVER['PHP_AUTH_PW']) && $_SERVER['PHP_AUTH_PW'] !== '') return (string) $_SERVER['PHP_AUTH_PW'];
        $header = '';
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
            if (!empty($_SERVER[$k])) { $header = (string) $_SERVER[$k]; break; }
        }
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $header = (string) $v; break; }
        }
        if (stripos($header, 'basic ') !== 0) return '';
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) return '';
        return substr($decoded, strpos($decoded, ':') + 1);
    }

    /** Ask for credentials. Git retries with Basic auth when it sees this. */
    public static function requireAuth(): void {
        header('WWW-Authenticate: Basic realm="tiknix instance repository"');
        header('Content-Type: text/plain');
        self::status(401);
        echo "authentication required\n";
    }

    /**
     * Set the response status. http_response_code() alone is NOT enough: Flight sends
     * its own response after the route callback returns, which resets the status to 200
     * while leaving header() values intact — so a 401 would go out as a 200 with a
     * WWW-Authenticate header, and git would never retry with credentials.
     */
    private static function status(int $code): void {
        http_response_code($code);
        if (class_exists('\Flight')) \Flight::response()->status($code);
    }

    /** Git pkt-line framing: 4-byte hex length (inclusive) then payload. */
    public static function pktLine(string $payload): string {
        return sprintf('%04x', strlen($payload) + 4) . $payload;
    }

    /**
     * GET /git/<slug>.git/info/refs?service=git-upload-pack — the ref advertisement that
     * opens every clone/fetch.
     */
    public static function advertiseRefs(string $dir): void {
        [$out, $code] = self::run(['git', 'upload-pack', '--stateless-rpc', '--advertise-refs', $dir], '');
        if ($code !== 0) { self::fail(500, 'git upload-pack failed'); return; }

        header('Content-Type: application/x-' . self::SERVICE . '-advertisement');
        self::noCache();
        echo self::pktLine('# service=' . self::SERVICE . "\n");
        echo '0000';
        echo $out;
    }

    /**
     * POST /git/<slug>.git/git-upload-pack — the negotiation + packfile. The request body
     * is git's wire protocol and is piped to upload-pack untouched.
     */
    public static function uploadPack(string $dir): void {
        $body = (string) file_get_contents('php://input');
        // Git compresses larger negotiation requests; upload-pack expects plain input.
        if (strcasecmp((string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? ''), 'gzip') === 0) {
            $plain = @gzdecode($body);
            if ($plain === false) { self::fail(400, 'malformed gzip body'); return; }
            $body = $plain;
        }

        [$out, $code] = self::run(['git', 'upload-pack', '--stateless-rpc', $dir], $body);
        if ($code !== 0) { self::fail(500, 'git upload-pack failed'); return; }

        header('Content-Type: application/x-' . self::SERVICE . '-result');
        self::noCache();
        echo $out;
    }

    /**
     * Run git with an argv array — no shell, so nothing in the request can be interpreted
     * as a shell metacharacter.
     * @return array{0:string, 1:int}
     */
    private static function run(array $argv, string $stdin): array {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env  = ['GIT_PROTOCOL' => (string) ($_SERVER['HTTP_GIT_PROTOCOL'] ?? ''), 'PATH' => '/usr/bin:/bin:/usr/local/bin'];
        $proc = @proc_open($argv, $spec, $pipes, null, $env);
        if (!is_resource($proc)) return ['', 1];

        if ($stdin !== '') fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 && $err !== '') error_log('GitHttp: ' . implode(' ', $argv) . ' -> ' . trim($err));
        return [$out, $code];
    }

    /** Git caches aggressively by default; ref data must never be served stale. */
    private static function noCache(): void {
        header('Expires: Fri, 01 Jan 1980 00:00:00 GMT');
        header('Pragma: no-cache');
        header('Cache-Control: no-cache, max-age=0, must-revalidate');
    }

    public static function fail(int $code, string $message): void {
        header('Content-Type: text/plain');
        self::status($code);
        echo $message . "\n";
    }
}
