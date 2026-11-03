<?php
/**
 * MemberAi — "Your AI" (/yourai): a member's own AI, connected once on their account and used by every project they own. Tiknix is a harness utility:
 * the AI that builds is the member's (their API key), never the platform's.
 *
 * Stored as the member's model connections (Model_Modelconnection, core db, key encrypted with core's key); the one the member
 * chose ("build.connection") is "Your AI". A project builds on ITS OWN agents (the app's /agents), so using Your AI on a project
 * means handing the key to that app: share() pipes it over ssh stdin to the app's `clitool --agent-provider-import`, which
 * connects it exactly as the app's AI agents page would and makes it the builder. Never argv, never a file, never printed.
 * A new project gets it once its container is up (ProvisionService adds `tenant.php --your-ai=SLUG` to its setup).
 */

namespace app;

class MemberAi {

    /** The providers a member can build with, by key, in the order offered. Their endpoints and models are the app's presets (Model_Agent). */
    public const PROVIDERS = ['anthropic', 'openrouter', 'zai', 'deepseek', 'ollama', 'custom'];

    /** A provider from a key's first letters, '' when they do not say. Only prefixes that mean exactly one provider. */
    public static function detect(string $key): string {
        $key = trim($key);
        if (str_starts_with($key, 'sk-ant-')) return 'anthropic';
        if (str_starts_with($key, 'sk-or-')) return 'openrouter';
        return '';
    }

    /** What the page offers: preset => label, where to get a key, a hint. */
    public static function providers(): array {
        $out = [];
        foreach (self::PROVIDERS as $k) {
            $p = \Model_Agent::PRESETS[$k];
            $out[$k] = ['label' => $k === 'custom' ? 'My own model server' : ($k === 'anthropic' ? 'Anthropic (Claude)' : (string) $p['label']),
                        'keys' => (string) ($p['keys'] ?? ''), 'hint' => $k === 'custom' ? 'Anything serving the Anthropic Messages API (/v1/messages) on a public address: Ollama, LM Studio, vLLM, LiteLLM.' : (string) $p['hint']];
        }
        return $out;
    }

    /**
     * Save a member's AI from a key, test it, and make it Your AI when it answers. A key the checks refuse is never stored; one that
     * is stored but does not answer is kept (to fix or retry), said, and not chosen.
     *
     * @return array{id: int, ok: bool, message: string, chosen: bool}
     * @throws \InvalidArgumentException with what a person should read
     */
    public static function save(int $memberId, string $preset, string $key, string $endpoint = '', string $model = '', bool $isRoot = false, ?callable $http = null): array {
        if (!in_array($preset, self::PROVIDERS, true)) throw new \InvalidArgumentException('Choose who provides your AI.');
        $key = trim($key);
        if ($key === '') throw new \InvalidArgumentException('Paste your API key.');
        $p = \Model_Agent::PRESETS[$preset];
        $model = trim($model) !== '' ? trim($model) : (string) $p['model'];
        if ($preset === 'custom' && $model === '') throw new \InvalidArgumentException('Your own model server: name the model it serves.');
        $in = ['name' => self::providers()[$preset]['label'], 'preset' => $preset, 'protocol' => 'anthropic',
               'base_url' => $preset === 'custom' ? trim($endpoint) : (string) $p['endpoint'],
               'auth' => $preset === 'anthropic' ? 'api_key' : 'bearer',
               'planner_model' => $model, 'worker_model' => $model, 'auditor_model' => $model, 'resolver_model' => $model,
               'haiku_model' => trim((string) ($p['fast_model'] ?? '')) !== '' && $preset !== 'custom' ? (string) $p['fast_model'] : $model];
        if ($problems = \Model_Modelconnection::problems($in, $isRoot, null, $memberId)) throw new \InvalidArgumentException('Not saved: ' . implode('; ', $problems) . '.');
        // Core's own database: MemberAi runs only on the control plane (the Builder asks core to create projects over HTTP).
        // One row per provider: a new key for the same provider replaces the old one.
        $c = $preset === 'custom' ? null : Bean::findOne('modelconnection', 'member_id = ? AND preset = ?', [$memberId, $preset]);
        $c = $c ?: Bean::dispense('modelconnection');
        $c->box()->fill($in, $memberId);
        $c->box()->setKey($key);
        $t = $c->box()->test($http);
        Bean::store($c);
        // Only an AI that answered becomes Your AI: a key that does not work would only fail every build.
        if ($t['ok']) \Model_Modelconnection::choose($memberId, (int) $c->id);
        $msg = (string) $t['message'];
        if (!$t['ok'] && preg_match('/HTTP (401|403)\b/', $msg)) $msg = "The provider refused this key. Check you copied all of it, and that the account has credit. ({$msg})";
        return ['id' => (int) $c->id, 'ok' => (bool) $t['ok'], 'message' => $msg, 'chosen' => (bool) $t['ok']];
    }

    /** The member's chosen AI, or null. A choice that points nowhere throws (Model_Modelconnection::chosenFor). */
    public static function chosen(int $memberId): ?object {
        return \Model_Modelconnection::chosenFor($memberId);
    }

    /** The projects a member owns that run in a container (the ones Your AI can be used on). */
    public static function projects(int $memberId): array {
        return array_values(Bean::find('instance', "member_id = ? AND ct_kind = 'tenant' AND ct_domain <> '' AND status = 'active' ORDER BY id", [$memberId]));
    }

    /**
     * Use a member's AI on one of their projects: connect it there as the builder. The caller has checked that $inst is theirs.
     * @return array{ok: bool, message: string}
     */
    public static function share(object $inst, object $conn): array {
        $preset = (string) $conn->preset;
        if (!in_array($preset, self::PROVIDERS, true)) return ['ok' => false, 'message' => "'{$conn->name}' cannot build in a project (it is {$preset}): choose one of " . implode(', ', self::PROVIDERS) . '.'];
        if ((string) ($inst->ctKind ?? '') !== 'tenant' || trim((string) ($inst->ctDomain ?? '')) === '') return ['ok' => false, 'message' => "{$inst->slug} does not run in its own container yet."];
        if ($p = $conn->box()->runProblems()) return ['ok' => false, 'message' => implode('; ', $p)];
        $key = $conn->box()->apiKey();   // throws when it will not decrypt: a fault, said
        $doc = json_encode(['preset' => $preset, 'key' => $key, 'endpoint' => $preset === 'custom' ? (string) $conn->baseUrl : '',
                            'model' => (string) $conn->workerModel, 'from' => 'Tiknix account #' . (int) $conn->memberId]);
        sodium_memzero($key);
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --agent-provider-import', $doc, 120);
        sodium_memzero($doc);
        $out = trim((string) $o);
        $last = (string) (array_slice(array_filter(array_map('trim', explode("\n", $out))), -1)[0] ?? '');
        $r = json_decode($last, true);
        if ($c !== 0 || !is_array($r) || empty($r['ok'])) {
            $why = str_contains($out, 'agent-provider-import') && str_contains($out, 'Unrecognized') ? 'its runtime is older than this feature; update it first' : ($out !== '' ? $out : "exit {$c}");
            \Flight::get('log')->error('MemberAi: could not use a member\'s AI on a project', ['slug' => $inst->slug, 'connection' => (int) $conn->id, 'why' => mb_substr($why, 0, 400)]);
            return ['ok' => false, 'message' => "{$inst->slug} refused it: " . mb_substr($why, 0, 300)];
        }
        $inst->yourAiJson = json_encode(['connection' => (int) $conn->id, 'preset' => $preset, 'at' => date('c')]);
        Bean::store($inst);
        \Flight::get('log')->info('MemberAi: a member\'s AI is now a project\'s builder', ['slug' => $inst->slug, 'connection' => (int) $conn->id, 'preset' => $preset]);
        return ['ok' => true, 'message' => ($inst->displayName ?: $inst->slug) . ' builds with ' . $conn->name . '.'];
    }
}
