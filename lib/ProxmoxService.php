<?php
/**
 * ProxmoxService — thin, read-mostly client for the Proxmox VE API, used by the hosted
 * deploy path to stand tenant containers up on the hypervisor.
 *
 * Credentials live in conf/proxmox.ini (gitignored, control-plane ONLY):
 *
 *   [proxmox]
 *   host    = "173.231.12.82"      ; :8006 is appended when no port is given
 *   tokenid = "tiknix@pve!deploy"  ; MUST be quoted — "!" is reserved in INI
 *   secret  = "…"
 *   node    = "agency"             ; optional; defaults to the first node returned
 *
 * PERMISSION MODEL (read this before debugging a 403):
 *
 *  - A privilege-separated token's effective rights are the INTERSECTION of the user's
 *    ACLs and the token's ACLs. Granting only the token yields exactly nothing. Both
 *    `pveum acl modify … -user 'tiknix@pve'` and `… -token 'tiknix@pve!deploy'` are
 *    required. That intersection is a feature — the token can never exceed the user.
 *  - Some operations are "root-only" and are NOT reachable by ANY token, including a
 *    root@pam token with Administrator: notably BIND MOUNTS (mp0: /host/path,mp=/…)
 *    and raw lxc.* config keys. The root-only check is not a privilege check, so no
 *    ACL grant unlocks it. Storage-backed mount points (mp0: local-lvm:8,mp=/…) are
 *    NOT root-restricted and are what this service uses.
 *
 * PVE speaks form-encoded requests and JSON responses. Mutations return a UPID task
 * handle rather than a result — use waitTask() to resolve one to an exit status.
 */
namespace app;

class ProxmoxService {

    /** Endpoints that mutate return a UPID string; poll this long for completion. */
    const TASK_TIMEOUT = 300;

    private string $base;
    private string $tokenid;
    private string $secret;
    private string $node;

    private function __construct(array $cfg) {
        $host = trim((string) ($cfg['host'] ?? ''));
        if (!preg_match('#^https?://#i', $host)) $host = 'https://' . $host;
        if (!preg_match('#:\d+$#', (string) parse_url($host, PHP_URL_HOST) . ':' . (parse_url($host, PHP_URL_PORT) ?: ''))) {
            $host = rtrim($host, '/') . ':8006';
        }
        $this->base    = rtrim($host, '/') . '/api2/json';
        $this->tokenid = trim((string) ($cfg['tokenid'] ?? ''));
        $this->secret  = trim((string) ($cfg['secret'] ?? ''));
        $this->node    = trim((string) ($cfg['node'] ?? ''));
    }

    /** Read conf/proxmox.ini. Tolerates a [proxmox] section or bare top-level keys. */
    public static function config(): array {
        $ini  = @parse_ini_file(dirname(__DIR__) . '/conf/proxmox.ini', true) ?: [];
        $flat = $ini;
        foreach ($ini as $v) if (is_array($v)) $flat = array_merge($flat, $v);
        return [
            'host'    => (string) ($flat['host']    ?? (getenv('PVE_HOST')    ?: '')),
            'tokenid' => (string) ($flat['tokenid'] ?? (getenv('PVE_TOKENID') ?: '')),
            'secret'  => (string) ($flat['secret']  ?? (getenv('PVE_SECRET')  ?: '')),
            'node'    => (string) ($flat['node']    ?? (getenv('PVE_NODE')    ?: '')),
            'image'   => (string) ($flat['image']   ?? (getenv('PVE_IMAGE')   ?: '')),
        ];
    }

    public static function fromConfig(): ?self {
        $cfg = self::config();
        if ($cfg['host'] === '' || $cfg['tokenid'] === '' || $cfg['secret'] === '') return null;
        return new self($cfg);
    }

    /** The token id (safe to log — it is an identity, not a secret). */
    public function tokenId(): string { return $this->tokenid; }

    /** Default node: the configured one, else the first the token can see. */
    public function node(): string {
        if ($this->node !== '') return $this->node;
        foreach ($this->nodes() as $n) {
            if (!empty($n['node'])) return $this->node = (string) $n['node'];
        }
        return '';
    }

    // ---------------------------------------------------------------- transport

    /** @return array{status:int, data:mixed, error:string, raw:string} */
    public function request(string $method, string $path, array $params = []): array {
        $method = strtoupper($method);
        $url    = $this->base . $path;
        $body   = '';

        if ($params !== []) {
            // GET and DELETE take params in the query string. A DELETE with a request
            // body is answered with a bare HTTP 501, which reads like an unsupported
            // endpoint rather than a malformed call — so route it correctly here.
            if ($method === 'GET' || $method === 'DELETE') {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            } else {
                $body = http_build_query($params);
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 60,
            // PVE ships a self-signed cert by default. The API is reached over the
            // hypervisor's own LAN; pin a fingerprint here if that ever changes.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Authorization: PVEAPIToken=' . $this->tokenid . '=' . $this->secret,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        $raw    = is_string($raw) ? $raw : '';
        $json   = json_decode($raw, true);

        $error = $cerr;
        if ($error === '' && ($status < 200 || $status >= 300)) {
            $error = trim((string) ($json['message'] ?? ('HTTP ' . $status)));
            if (!empty($json['errors']) && is_array($json['errors'])) {
                foreach ($json['errors'] as $k => $v) $error .= ' [' . $k . ': ' . $v . ']';
            }
        }
        return ['status' => $status, 'data' => $json['data'] ?? null, 'error' => $error, 'raw' => $raw];
    }

    public function get(string $p, array $q = []): array    { return $this->request('GET', $p, $q); }
    public function post(string $p, array $q = []): array   { return $this->request('POST', $p, $q); }
    public function put(string $p, array $q = []): array    { return $this->request('PUT', $p, $q); }
    public function delete(string $p, array $q = []): array { return $this->request('DELETE', $p, $q); }

    /**
     * Enumerate an API path's child endpoints. PVE's index responses double as
     * capability discovery — a feature present on this version shows up here.
     * @return string[]
     */
    public function endpoints(string $path): array {
        $out = [];
        foreach ((array) ($this->get($path)['data'] ?? []) as $row) {
            if (is_array($row) && isset($row['subdir'])) $out[] = (string) $row['subdir'];
        }
        sort($out);
        return $out;
    }

    /**
     * Resolve a UPID returned by a mutating call to its final exit status.
     * @return array{ok:bool, status:string, exit:string, log:string}
     */
    public function waitTask(string $node, string $upid, int $timeout = self::TASK_TIMEOUT): array {
        $deadline = time() + $timeout;
        $last     = [];
        while (time() < $deadline) {
            $last = (array) ($this->get('/nodes/' . $node . '/tasks/' . rawurlencode($upid) . '/status')['data'] ?? []);
            if (($last['status'] ?? '') === 'stopped') {
                $exit = (string) ($last['exitstatus'] ?? '');
                return ['ok' => $exit === 'OK', 'status' => 'stopped', 'exit' => $exit, 'log' => $this->taskLog($node, $upid)];
            }
            sleep(2);
        }
        return ['ok' => false, 'status' => (string) ($last['status'] ?? 'unknown'),
                'exit' => 'timeout after ' . $timeout . 's', 'log' => $this->taskLog($node, $upid)];
    }

    /** Tail of a task's log — the only place PVE explains *why* something failed. */
    public function taskLog(string $node, string $upid, int $limit = 40): string {
        $out = [];
        foreach ((array) ($this->get('/nodes/' . $node . '/tasks/' . rawurlencode($upid) . '/log', ['limit' => $limit])['data'] ?? []) as $line) {
            $out[] = (string) ($line['t'] ?? '');
        }
        return trim(implode("\n", $out));
    }

    /** A mutating call that returns a UPID, resolved to a completed task. */
    private function task(string $method, string $node, string $path, array $params = []): array {
        $r = $this->request($method, $path, $params);
        if ($r['error'] !== '') return ['ok' => false, 'status' => 'failed', 'exit' => $r['error'], 'log' => ''];
        $upid = is_string($r['data']) ? $r['data'] : '';
        if ($upid === '') return ['ok' => true, 'status' => 'stopped', 'exit' => 'OK', 'log' => ''];
        return $this->waitTask($node, $upid);
    }

    // ------------------------------------------------------------------ queries

    public function version(): array     { return (array) ($this->get('/version')['data'] ?? []); }
    public function nodes(): array       { return (array) ($this->get('/nodes')['data'] ?? []); }
    public function permissions(): array { return (array) ($this->get('/access/permissions')['data'] ?? []); }
    public function nextId(): int        { return (int) ($this->get('/cluster/nextid')['data'] ?? 0); }

    public function storages(string $node): array {
        return (array) ($this->get('/nodes/' . $node . '/storage')['data'] ?? []);
    }

    /** Storages advertising a given content type ("vztmpl", "rootdir", "import", …). */
    public function storagesFor(string $node, string $content): array {
        $out = [];
        foreach ($this->storages($node) as $s) {
            if (in_array($content, array_map('trim', explode(',', (string) ($s['content'] ?? ''))), true)) $out[] = $s;
        }
        return $out;
    }

    public function content(string $node, string $storage, string $content = ''): array {
        return (array) ($this->get('/nodes/' . $node . '/storage/' . $storage . '/content',
            $content !== '' ? ['content' => $content] : [])['data'] ?? []);
    }

    public function containers(string $node): array {
        return (array) ($this->get('/nodes/' . $node . '/lxc')['data'] ?? []);
    }

    public function ctConfig(string $node, int $vmid): array {
        return (array) ($this->get('/nodes/' . $node . '/lxc/' . $vmid . '/config')['data'] ?? []);
    }

    public function ctStatus(string $node, int $vmid): array {
        return (array) ($this->get('/nodes/' . $node . '/lxc/' . $vmid . '/status/current')['data'] ?? []);
    }

    public function ctExists(string $node, int $vmid): bool {
        foreach ($this->containers($node) as $c) if ((int) ($c['vmid'] ?? 0) === $vmid) return true;
        return false;
    }

    // ---------------------------------------------------------------- mutations

    /**
     * Pull an image into a template storage. `url` may be a plain https tarball or,
     * on PVE 9.1+, an OCI reference such as docker://ghcr.io/owner/image:tag.
     * Needs Sys.AccessNetwork on /nodes/<node> — the node is the one fetching.
     */
    public function downloadUrl(string $node, string $storage, string $url, string $filename, string $content = 'vztmpl'): array {
        return $this->task('POST', $node, '/nodes/' . $node . '/storage/' . $storage . '/download-url', [
            'url' => $url, 'filename' => $filename, 'content' => $content,
        ]);
    }

    /**
     * PVE 9.2's dedicated OCI puller (/storage/<s>/oci-registry-pull) — preferred over
     * download-url for registry references. Parameter names vary by build, so callers
     * should discover them with paramSchema() rather than hardcoding.
     * Needs Sys.AccessNetwork on /nodes/<node>.
     */
    public function ociRegistryPull(string $node, string $storage, array $params): array {
        return $this->task('POST', $node, '/nodes/' . $node . '/storage/' . $storage . '/oci-registry-pull', $params);
    }

    /**
     * Discover an endpoint's required parameters by calling it with none: PVE validates
     * before doing any work and names each missing property in `errors`. Read-only in
     * effect — the call always fails validation.
     * @return string[] parameter names
     */
    public function paramSchema(string $method, string $path): array {
        $r    = $this->request($method, $path, []);
        $json = json_decode($r['raw'], true);
        $errs = (array) ($json['errors'] ?? []);
        return array_keys($errs);
    }

    /**
     * Create a container. $params passes through, so callers control rootfs, net0, mpN,
     * cores, memory, features, … Storage-backed mount points only — a bind mount (a host
     * path in mpN) is root-only and 403s for any token.
     */
    public function createCt(string $node, int $vmid, string $ostemplate, array $params = []): array {
        return $this->task('POST', $node, '/nodes/' . $node . '/lxc', array_merge([
            'vmid' => $vmid, 'ostemplate' => $ostemplate, 'unprivileged' => 1, 'onboot' => 0,
        ], $params));
    }

    /** Update a container's config. Not a task — returns the raw response. */
    public function setCtConfig(string $node, int $vmid, array $params): array {
        return $this->put('/nodes/' . $node . '/lxc/' . $vmid . '/config', $params);
    }

    public function startCt(string $node, int $vmid): array {
        return $this->task('POST', $node, '/nodes/' . $node . '/lxc/' . $vmid . '/status/start');
    }

    public function stopCt(string $node, int $vmid): array {
        return $this->task('POST', $node, '/nodes/' . $node . '/lxc/' . $vmid . '/status/stop');
    }

    public function destroyCt(string $node, int $vmid, bool $purge = true): array {
        return $this->task('DELETE', $node, '/nodes/' . $node . '/lxc/' . $vmid,
            $purge ? ['purge' => 1, 'destroy-unreferenced-disks' => 1] : []);
    }
}
