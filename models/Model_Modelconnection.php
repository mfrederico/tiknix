<?php
/**
 * Model_Modelconnection — a member's own model endpoint + key, usable as a build engine.
 * MODEL_CONNECTIONS_PLAN.md.
 *
 *   member_id      the owner. Only runs that member triggers use it ("whoever triggers pays").
 *   name           the member's label ("Ollama Cloud", "OpenRouter work account")
 *   preset         which PRESETS row filled the form (informational; every field is editable)
 *   protocol       anthropic → drives the Claude Code CLI (build agents, tool use) via
 *                  ANTHROPIC_BASE_URL; openai → chat only (pipeline steps) until a headless
 *                  OpenAI-protocol coding CLI is wired in. Never silently one for the other.
 *   base_url       anthropic: the root the Anthropic SDK is pointed at (…/v1 is appended by
 *                  the client); openai: the …/v1 base
 *   auth           api_key (x-api-key — Anthropic's own) | bearer | none (a local server)
 *   key_enc        the key, encrypted with core's [security] app_key. Never sent to a page.
 *   *_model        planner / worker / auditor / resolver / haiku (fast) model ids
 *
 * Engine name: "mc-<id>" (EngineRegistry resolves it through here; AgentContext checks the
 * caller owns it). Localhost/LAN endpoints are ROOT-only (owner decision 2026-09-23).
 */

use app\Bean;
use app\CoreDb;
use app\EncryptionService;
use app\MemberEnginePrefs;

class Model_Modelconnection extends \RedBeanPHP\SimpleModel {

    public const PROTOCOLS = ['anthropic', 'openai'];
    public const AUTHS     = ['api_key', 'bearer', 'none'];
    public const TIERS     = ['planner', 'worker', 'auditor', 'resolver', 'haiku'];
    public const ENGINE_RE = '/^mc-([1-9][0-9]*)$/D';
    /** Model ids and URLs reach a shell (jail-run reads them as KEY=value): no quotes, no spaces. */
    public const MODEL_RE  = '/^[A-Za-z0-9._:\/@+-]{1,160}$/D';

    /** The form's starting points. Every field stays editable. */
    public const PRESETS = [
        'anthropic'  => ['label' => 'Anthropic (your API key)', 'protocol' => 'anthropic', 'base_url' => 'https://api.anthropic.com', 'auth' => 'api_key',
                         'models' => ['planner' => 'claude-opus-5-5', 'worker' => 'sonnet', 'auditor' => 'sonnet', 'resolver' => 'claude-opus-5-5', 'haiku' => 'haiku'],
                         'hint' => 'An API key from console.anthropic.com (sk-ant-…).'],
        'ollama'     => ['label' => 'Ollama Cloud', 'protocol' => 'anthropic', 'base_url' => 'https://ollama.com', 'auth' => 'bearer',
                         'models' => ['planner' => 'qwen3.5:397b', 'worker' => 'qwen3.5:397b', 'auditor' => 'qwen3.5:397b', 'resolver' => 'qwen3.5:397b', 'haiku' => 'qwen3.5:397b'],
                         'hint' => 'An API key from ollama.com/settings/keys. Cloud models need usage credits on that account.'],
        'openrouter' => ['label' => 'OpenRouter', 'protocol' => 'anthropic', 'base_url' => 'https://openrouter.ai/api', 'auth' => 'bearer',
                         'models' => ['planner' => 'anthropic/claude-opus-5.5', 'worker' => 'anthropic/claude-sonnet-5', 'auditor' => 'anthropic/claude-sonnet-5', 'resolver' => 'anthropic/claude-opus-5.5', 'haiku' => 'anthropic/claude-haiku-4.5'],
                         'hint' => 'A key from openrouter.ai/keys (sk-or-…). Any model OpenRouter lists works; Claude Code is most reliable on Anthropic models.'],
        'zai'        => ['label' => 'z.ai (GLM)', 'protocol' => 'anthropic', 'base_url' => 'https://api.z.ai/api/anthropic', 'auth' => 'bearer',
                         'models' => ['planner' => 'glm-5.3', 'worker' => 'glm-5.3', 'auditor' => 'glm-5.3', 'resolver' => 'glm-5.3', 'haiku' => 'glm-5.3-flash'],
                         'hint' => 'A z.ai API key (coding plan or pay-as-you-go).'],
        'local'      => ['label' => 'Ollama / LM Studio on this server', 'protocol' => 'anthropic', 'base_url' => 'http://127.0.0.1:11434', 'auth' => 'none',
                         'models' => ['planner' => '', 'worker' => '', 'auditor' => '', 'resolver' => '', 'haiku' => ''],
                         'hint' => 'Root only: a model server on this machine or its LAN. Pick models after Test lists them.'],
        'custom'     => ['label' => 'Custom Anthropic-compatible endpoint', 'protocol' => 'anthropic', 'base_url' => '', 'auth' => 'bearer',
                         'models' => ['planner' => '', 'worker' => '', 'auditor' => '', 'resolver' => '', 'haiku' => ''],
                         'hint' => 'vLLM, llama.cpp server, LiteLLM, a gateway — anything serving /v1/messages.'],
        'openai'     => ['label' => 'OpenAI-compatible endpoint (chat only)', 'protocol' => 'openai', 'base_url' => '', 'auth' => 'bearer',
                         'models' => ['planner' => '', 'worker' => '', 'auditor' => '', 'resolver' => '', 'haiku' => ''],
                         'hint' => 'Serves /v1/chat/completions. Usable by pipeline agent steps; NOT by build agents yet.'],
    ];

    /* ---- lookup (always against core's db, from any process) ------------------------ */

    public static function idFromEngine(string $engine): ?int {
        return preg_match(self::ENGINE_RE, $engine, $m) ? (int) $m[1] : null;
    }

    /** One connection by id from core's db, or null when there is no such row. Throws when core is unreachable. */
    public static function byId(int $id): ?\RedBeanPHP\OODBBean {
        $miss = new \stdClass();
        $row = CoreDb::with(function () use ($id) {
            $b = Bean::load('modelconnection', $id);
            return $b->id ? $b : null;
        }, $miss);
        if ($row === $miss) throw new \RuntimeException("Model connection #{$id}: core database unreachable (" . CoreDb::lastError() . ')');
        return $row;
    }

    /** @return \RedBeanPHP\OODBBean[] the member's connections, by name */
    public static function forMember(int $memberId): array {
        $miss = new \stdClass();
        $rows = CoreDb::with(fn() => array_values(Bean::find('modelconnection', 'member_id = ? ORDER BY name ASC', [$memberId])), $miss);
        if ($rows === $miss) throw new \RuntimeException('Model connections: core database unreachable (' . CoreDb::lastError() . ')');
        return $rows;
    }

    /**
     * The connection this member builds with instead of the platform's Claude, or null when
     * they build on the platform. Setting key `build.connection` on the member (core).
     * A setting that names a connection the member no longer owns is a fault, not "none".
     */
    public static function chosenFor(int $memberId): ?\RedBeanPHP\OODBBean {
        if ($memberId <= 0) return null;
        $miss = new \stdClass();
        $val = CoreDb::with(function () use ($memberId) {
            $r = Bean::findOne('settings', 'member_id = ? AND setting_key = ?', [$memberId, 'build.connection']);
            return $r && $r->id ? trim((string) $r->settingValue) : '';
        }, $miss);
        if ($val === $miss) throw new \RuntimeException('Model connections: could not read the member\'s build connection (' . CoreDb::lastError() . ')');
        if ($val === '') return null;
        $c = self::byId((int) $val);
        if (!$c || (int) $c->memberId !== $memberId) {
            throw new \RuntimeException("Member #{$memberId} builds with model connection #{$val}, which no longer exists or is not theirs. Choose again in Settings → Models.");
        }
        return $c;
    }

    /** Save (or clear with 0) the member's build connection. The caller has checked ownership. */
    public static function choose(int $memberId, int $connectionId): void {
        CoreDb::with(function () use ($memberId, $connectionId) {
            $r = Bean::findOne('settings', 'member_id = ? AND setting_key = ?', [$memberId, 'build.connection']);
            if ($connectionId <= 0) { if ($r && $r->id) Bean::trash($r); return true; }
            if (!$r || !$r->id) { $r = Bean::dispense('settings'); $r->memberId = $memberId; $r->settingKey = 'build.connection'; $r->createdAt = date('Y-m-d H:i:s'); }
            $r->settingValue = (string) $connectionId;
            $r->updatedAt = date('Y-m-d H:i:s');
            Bean::store($r);
            return true;
        }) ?? throw new \RuntimeException('Could not save the build connection: ' . CoreDb::lastError());
    }

    /* ---- validation ----------------------------------------------------------------- */

    /**
     * Problems with a form submission, [] when fine. $isRoot: localhost/LAN endpoints are
     * allowed only for ROOT (they reach this server's own internals).
     */
    public static function problems(array $in, bool $isRoot, ?int $exceptId = null, ?int $memberId = null): array {
        $p = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) $p[] = 'name is required (up to 60 characters)';
        $protocol = (string) ($in['protocol'] ?? '');
        if (!in_array($protocol, self::PROTOCOLS, true)) $p[] = 'protocol must be anthropic or openai';
        $auth = (string) ($in['auth'] ?? '');
        if (!in_array($auth, self::AUTHS, true)) $p[] = 'auth must be api_key, bearer or none';
        $url = rtrim(trim((string) ($in['base_url'] ?? '')), '/');
        if (!preg_match('#^https?://[^\s\'"`$\\\\]+$#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $p[] = 'base URL must be an absolute http(s) URL, e.g. https://ollama.com';
        } elseif (!$isRoot) {
            try { \app\services\connectors\RestConnector::assertPublicHost($url); }
            catch (\Exception $e) { $p[] = $e->getMessage() . ' (endpoints on this server or its LAN are root-only)'; }
        }
        foreach (self::TIERS as $t) {
            $m = trim((string) ($in[$t . '_model'] ?? ''));
            if ($m === '' && $t !== 'haiku') { $p[] = "{$t} model is required"; continue; }
            if ($m !== '' && !preg_match(self::MODEL_RE, $m)) $p[] = "{$t} model '{$m}' has characters a model id cannot contain";
        }
        return $p;
    }

    public function fill(array $in, int $memberId): void {
        $b = $this->bean;
        $b->memberId = $memberId;
        $b->name     = trim((string) $in['name']);
        $b->preset   = isset(self::PRESETS[$in['preset'] ?? '']) ? (string) $in['preset'] : 'custom';
        $b->protocol = (string) $in['protocol'];
        $b->baseUrl  = rtrim(trim((string) $in['base_url']), '/');
        $b->auth     = (string) $in['auth'];
        foreach (self::TIERS as $t) $b->{$t . 'Model'} = trim((string) ($in[$t . '_model'] ?? ''));
        if (!$b->createdAt) $b->createdAt = date('Y-m-d H:i:s');
        $b->updatedAt = date('Y-m-d H:i:s');
    }

    /* ---- the key -------------------------------------------------------------------- */

    /** Store a key, or clear it with ''. Encrypted with core's app_key. */
    public function setKey(string $raw): void {
        $raw = trim($raw);
        $this->bean->keyEnc = $raw === '' ? '' : EncryptionService::encryptWith($raw, MemberEnginePrefs::coreKey());
    }

    /** The key for a run — never for a page. A stored key that will not decrypt THROWS: it is a fault, not "no key". */
    public function apiKey(): string {
        $enc = (string) ($this->bean->keyEnc ?? '');
        if ($enc === '') return '';
        return EncryptionService::decryptWith($enc, MemberEnginePrefs::coreKey());
    }

    /** set | unset | unreadable — all a page may know. */
    public function keyStatus(): string {
        if ((string) ($this->bean->keyEnc ?? '') === '') return 'unset';
        try { return $this->apiKey() !== '' ? 'set' : 'unset'; }
        catch (\Throwable $e) { return 'unreadable'; }
    }

    /** Ready to run: a key when the endpoint needs one, and one that decrypts. Problems as text, [] when ready. */
    public function runProblems(): array {
        $p = [];
        $st = $this->keyStatus();
        if ($st === 'unreadable') $p[] = "the stored key for '{$this->bean->name}' cannot be decrypted (core [security] app_key changed?) — re-enter it";
        if ($st === 'unset' && (string) $this->bean->auth !== 'none') $p[] = "'{$this->bean->name}' has no key; add one in Settings → Models";
        if ((string) $this->bean->protocol !== 'anthropic') $p[] = "'{$this->bean->name}' is an OpenAI-compatible (chat only) endpoint; build agents need an Anthropic-compatible one";
        return $p;
    }

    public function engineName(): string {
        return 'mc-' . (int) $this->bean->id;
    }

    /** The engine-shaped definition EngineRegistry serves for "mc-<id>". */
    public function engineDef(): array {
        $b = $this->bean;
        $def = [
            'label'          => (string) $b->name,
            'transport'      => 'cli-headless',
            'command'        => 'claude',
            'cli_flavor'     => (string) $b->protocol === 'anthropic' ? 'claude' : 'openai',
            'headless_ready' => (string) $b->protocol === 'anthropic',
            'available'      => true,
            'connection'     => (int) $b->id,
            'owner'          => (int) $b->memberId,
        ];
        foreach (self::TIERS as $t) {
            $m = trim((string) ($b->{$t . 'Model'} ?? ''));
            if ($m !== '') $def[$t . '_model'] = $m;
        }
        return $def;
    }

    /* ---- talking to the endpoint ---------------------------------------------------- */

    /** Request headers for this endpoint, with the key. */
    public function headers(bool $json = true): array {
        $h = $json ? ['Content-Type: application/json', 'Accept: application/json'] : ['Accept: application/json'];
        $key = $this->apiKey();
        $auth = (string) $this->bean->auth;
        if ($auth === 'api_key' && $key !== '') { $h[] = 'x-api-key: ' . $key; }
        elseif ($auth === 'bearer' && $key !== '') { $h[] = 'Authorization: Bearer ' . $key; }
        if ((string) $this->bean->protocol === 'anthropic') $h[] = 'anthropic-version: 2023-06-01';
        return $h;
    }

    public function modelsUrl(): string {
        $base = rtrim((string) $this->bean->baseUrl, '/');
        return (string) $this->bean->protocol === 'anthropic' ? $base . '/v1/models' : $base . '/models';
    }

    /**
     * The model ids the endpoint lists. Both protocols answer GET …/v1/models with {data:[{id}]}
     * (Ollama also accepts {models:[{name}]}). Throws with the endpoint's own words.
     *
     * @return string[]
     */
    public function listModels(?callable $http = null): array {
        [$code, $body] = ($http ?? [self::class, 'httpCall'])('GET', $this->modelsUrl(), $this->headers(false), null);
        $d = json_decode((string) $body, true);
        if ($code !== 200 || !is_array($d)) {
            throw new \RuntimeException('GET ' . $this->modelsUrl() . " answered HTTP {$code}: " . self::said($body, $d));
        }
        $ids = [];
        foreach ((array) ($d['data'] ?? []) as $m) if (!empty($m['id'])) $ids[] = (string) $m['id'];
        foreach ((array) ($d['models'] ?? []) as $m) if (!empty($m['name'] ?? $m['model'] ?? '')) $ids[] = (string) ($m['name'] ?? $m['model']);
        sort($ids);
        return array_values(array_unique($ids));
    }

    /**
     * Prove the connection: list its models, then (anthropic protocol) make a 1-token
     * Messages call with the worker model — the call a build agent makes first. Records
     * the outcome on the row. Never throws; the result says what happened.
     *
     * @return array{ok:bool,message:string,models:string[]}
     */
    public function test(?callable $http = null): array {
        $http = $http ?? [self::class, 'httpCall'];
        $b = $this->bean;
        $models = [];
        try {
            $models = $this->listModels($http);
            $msg = count($models) . ' model(s) listed.';
            if ((string) $b->protocol === 'anthropic') {
                $model = (string) $b->workerModel;
                $payload = json_encode(['model' => $model, 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'ping']]]);
                [$code, $body] = $http('POST', rtrim((string) $b->baseUrl, '/') . '/v1/messages', $this->headers(true), $payload);
                $d = json_decode((string) $body, true);
                if ($code !== 200) throw new \RuntimeException("POST /v1/messages with '{$model}' answered HTTP {$code}: " . self::said($body, $d));
                $msg .= " A message to '{$model}' was answered.";
                if ($models && !in_array($model, $models, true)) $msg .= " (Note: '{$model}' is not in the listed models, but the endpoint accepted it.)";
            } else {
                $msg .= ' OpenAI-compatible: usable by pipeline agent steps (chat), not by build agents.';
            }
            $ok = true;
        } catch (\Throwable $e) {
            $ok = false;
            $msg = $e->getMessage();
        }
        $b->lastTestAt = date('Y-m-d H:i:s');
        $b->lastTestOk = $ok ? 1 : 0;
        $b->lastTestMsg = mb_substr($msg, 0, 500);
        return ['ok' => $ok, 'message' => $msg, 'models' => $models];
    }

    /**
     * Write what a run needs into the member's per-engine state dir (outside every project
     * tree, never on a command line): `auth-token` (0600, the key) and `endpoint.env`
     * (KEY=value lines jail-run.sh reads and a direct run sources). Rewritten on every run so
     * a rotated key or a changed model takes effect at once. Throws when it cannot run.
     */
    public function materialize(string $stateDir): void {
        if ($p = $this->runProblems()) throw new \RuntimeException('Model connection: ' . implode('; ', $p));
        $b = $this->bean;
        if (!is_dir($stateDir) && !@mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
            throw new \RuntimeException("Model connection: cannot create {$stateDir}");
        }
        $env = [
            'TIKNIX_MC_ID'       => (string) (int) $b->id,
            'TIKNIX_MC_AUTH'     => (string) $b->auth,
            'ANTHROPIC_BASE_URL' => rtrim((string) $b->baseUrl, '/'),
        ];
        // Claude Code keeps asking for opus/sonnet/haiku (subagents, compaction); these say
        // what those names mean on this endpoint. --model still names the tier's model.
        foreach (['ANTHROPIC_DEFAULT_OPUS_MODEL' => 'planner', 'ANTHROPIC_DEFAULT_SONNET_MODEL' => 'worker', 'ANTHROPIC_DEFAULT_HAIKU_MODEL' => 'haiku'] as $var => $t) {
            $m = trim((string) ($b->{$t . 'Model'} ?? ''));
            if ($m === '' && $t === 'haiku') $m = (string) $b->workerModel;
            $env[$var] = $m;
        }
        $lines = '';
        foreach ($env as $k => $v) {
            if (!preg_match('/^[A-Za-z0-9._:\/@+-]*$/D', $v)) throw new \RuntimeException("Model connection: {$k} has characters that cannot be written safely");
            $lines .= "{$k}={$v}\n";
        }
        self::writePrivate($stateDir . '/endpoint.env', $lines);
        self::writePrivate($stateDir . '/auth-token', $this->apiKey() . "\n");
    }

    private static function writePrivate(string $file, string $content): void {
        $tmp = $file . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $content) === false) throw new \RuntimeException("Model connection: cannot write {$file}");
        @chmod($tmp, 0600);   // under /home/<operator>/.tiknix, not an ACL-managed instance dir
        if (!@rename($tmp, $file)) { @unlink($tmp); throw new \RuntimeException("Model connection: cannot write {$file}"); }
    }

    /** What an error body said, briefly. */
    private static function said($body, $d): string {
        if (is_array($d)) {
            $m = $d['error']['message'] ?? $d['error'] ?? $d['message'] ?? null;
            if (is_string($m) && $m !== '') return mb_substr($m, 0, 300);
        }
        $first = trim((string) strtok(trim((string) $body), "\n"));
        return $first !== '' ? mb_substr($first, 0, 300) : 'no body';
    }

    /** @return array{0:int,1:string} */
    public static function httpCall(string $method, string $url, array $headers, ?string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) $body]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) return [0, json_encode(['message' => 'request failed: ' . curl_error($ch)])];
        return [$code, (string) $resp];   // no curl_close(): it throws in a PHP 8.5 web handler
    }
}
