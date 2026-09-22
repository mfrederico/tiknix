<?php
/**
 * Model_Agent — one of the app's named agents (COMPONENTS_PLAN.md, "Named agents").
 *
 *   name         unique, [a-z0-9-], what a pipeline's `agent` step says
 *   kind         cli    → an EngineRegistry engine, run through the install's bin/claude
 *                openai → any OpenAI-compatible chat-completions endpoint
 *   engine/model cli: the engine + model tier; openai: model is the endpoint's model id
 *   endpoint     openai only: base URL (…/v1), chat/completions is appended
 *   api_key_enc  the key, encrypted with the install's own key (ConnectionStore::ownKey).
 *                Never returned to a page. On a cli agent, empty = the install's chain.
 *   timeout      seconds
 *   pre_prompt   the agent's system prompt; a step's `system` is appended to it
 *   is_default   the agent a step without a name uses
 *
 * Reached through the bean (RedBean FUSE): `$agent->keyStatus()`, `$agent->apiKey()`.
 */

class Model_Agent extends \RedBeanPHP\SimpleModel {

    public const KINDS = ['cli', 'openai'];
    public const NAME_RE = '/^[a-z0-9][a-z0-9-]{0,62}$/D';
    public const DEFAULT_TIMEOUT = 600;

    /** @return \RedBeanPHP\OODBBean|null */
    public static function byName(string $name): ?\RedBeanPHP\OODBBean {
        $name = trim($name);
        if ($name === '') return null;
        $a = \app\Bean::findOne('agent', 'name = ?', [$name]);
        return ($a && $a->id) ? $a : null;
    }

    /** The agent a step without a name uses, or null when none is marked default. */
    public static function defaultAgent(): ?\RedBeanPHP\OODBBean {
        $a = \app\Bean::findOne('agent', 'is_default = 1 ORDER BY id ASC');
        return ($a && $a->id) ? $a : null;
    }

    /** @return \RedBeanPHP\OODBBean[] all agents, by name */
    public static function all(): array {
        return array_values(\app\Bean::find('agent', 'ORDER BY name ASC'));
    }

    /**
     * Shape rules for a save. Returns problems, [] when fine. Does not touch the key —
     * setApiKey() does that, separately, so an edit of the pre-prompt never re-encrypts.
     */
    public static function problems(array $in, ?int $exceptId = null): array {
        $p = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (!preg_match(self::NAME_RE, $name)) $p[] = 'name must be lowercase letters, digits and dashes (2–63 chars), e.g. data-append';
        elseif (($dup = self::byName($name)) && (int) $dup->id !== (int) $exceptId) $p[] = "an agent named '{$name}' already exists";
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) $p[] = 'kind must be cli or openai';
        if ($kind === 'cli') {
            $engine = (string) ($in['engine'] ?? '');
            if ($engine !== '' && class_exists('\\app\\EngineRegistry') && !\app\EngineRegistry::isValid($engine)) {
                $p[] = "engine '{$engine}' is not registered (conf/aibuilder.ini [engine.*])";
            }
        }
        if ($kind === 'openai') {
            $ep = trim((string) ($in['endpoint'] ?? ''));
            if (!preg_match('#^https?://[^\s/]+#i', $ep)) $p[] = 'endpoint must be an absolute http(s) URL, e.g. https://api.openai.com/v1';
            if (trim((string) ($in['model'] ?? '')) === '') $p[] = 'model is required for an openai agent (the endpoint\'s model id)';
        }
        $t = (int) ($in['timeout'] ?? self::DEFAULT_TIMEOUT);
        if ($t < 5 || $t > 3600) $p[] = 'timeout must be between 5 and 3600 seconds';
        return $p;
    }

    /** Apply the editable fields (never the key, never is_default's uniqueness — see setDefault). */
    public function fill(array $in): void {
        $b = $this->bean;
        $b->name        = trim((string) ($in['name'] ?? ''));
        $b->description = trim((string) ($in['description'] ?? ''));
        $b->kind        = (string) ($in['kind'] ?? 'cli');
        $b->engine      = $b->kind === 'cli' ? trim((string) ($in['engine'] ?? '')) : '';
        $b->model       = trim((string) ($in['model'] ?? ''));
        $b->endpoint    = $b->kind === 'openai' ? rtrim(trim((string) ($in['endpoint'] ?? '')), '/') : '';
        $b->timeout     = max(5, min(3600, (int) ($in['timeout'] ?? self::DEFAULT_TIMEOUT)));
        $b->prePrompt   = (string) ($in['pre_prompt'] ?? '');
        if (!$b->createdAt) $b->createdAt = date('Y-m-d H:i:s');
        $b->updatedAt   = date('Y-m-d H:i:s');
    }

    /** Make this the default; only one can be. */
    public function setDefault(bool $on): void {
        if ($on) {
            foreach (\app\Bean::find('agent', 'is_default = 1 AND id != ?', [(int) $this->bean->id]) as $o) {
                $o->isDefault = 0; \app\Bean::store($o);
            }
        }
        $this->bean->isDefault = $on ? 1 : 0;
    }

    /** Store a key (encrypted with the install's own key) or clear it with ''. */
    public function setApiKey(string $raw): void {
        $raw = trim($raw);
        $this->bean->apiKeyEnc = $raw === '' ? '' : \app\EncryptionService::encryptWith($raw, \app\ConnectionStore::ownKey());
    }

    /**
     * The key, decrypted — for the run, never for a page. Throws when a stored key does not
     * decrypt: that is a fault (rotated install key, corrupt row), not "no key".
     */
    public function apiKey(): string {
        $enc = (string) ($this->bean->apiKeyEnc ?? '');
        if ($enc === '') return '';
        return \app\EncryptionService::decryptWith($enc, \app\ConnectionStore::ownKey());
    }

    /** set | unset | unreadable — what a page may say about the key. */
    public function keyStatus(): string {
        if ((string) ($this->bean->apiKeyEnc ?? '') === '') return 'unset';
        try { return $this->apiKey() !== '' ? 'set' : 'unset'; }
        catch (\Throwable $e) { return 'unreadable'; }
    }

    /** What a page or the editor's dropdown may see: no key, ever. */
    public function summary(): array {
        $b = $this->bean;
        return [
            'id' => (int) $b->id, 'name' => (string) $b->name, 'description' => (string) $b->description,
            'kind' => (string) $b->kind, 'engine' => (string) $b->engine, 'model' => (string) $b->model,
            'endpoint' => (string) $b->endpoint, 'timeout' => (int) $b->timeout, 'pre_prompt' => (string) $b->prePrompt,
            'is_default' => (bool) $b->isDefault, 'key_status' => $this->keyStatus(),
        ];
    }
}
