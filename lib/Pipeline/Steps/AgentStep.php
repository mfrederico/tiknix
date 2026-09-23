<?php
/**
 * agent — run an AI agent (merges myctobot's ai_agent + llm_call). Reuses tiknix's
 * lib/EngineRegistry to build the engine command (NOT a second agent runtime); the
 * command runs as a subprocess. When the pipeline RUN was dispatched jailed (§
 * Dispatcher), this executes inside that jail — the whole run is confined, so the
 * step just runs the command. Returns the agent's text output.
 */

namespace app\Pipeline\Steps;

use app\EngineRegistry;

class AgentStep implements StepInterface {

    public static function type(): string { return 'agent'; }

    public static function schema(): array {
        return [
            'summary' => 'Run one of the app\'s named agents (or the default) with a prompt; returns its text output.',
            'fields'  => [
                ['name' => 'agent',   'label' => 'Agent',   'type' => 'select', 'options' => [], 'dynamic' => 'agents',
                    'help' => 'One of this app\'s agents (Data page → Agents). Blank = the default agent; with no agents configured, the install\'s engine and credential.'],
                ['name' => 'prompt',  'label' => 'Prompt',  'type' => 'textarea', 'required' => true, 'help' => 'The task/prompt for the agent. Use {context.x} / {step.output} variables.'],
                ['name' => 'system',  'label' => 'System (this step)', 'type' => 'textarea', 'help' => 'Optional — appended to the agent\'s pre-prompt for this step only.'],
                ['name' => 'engine',  'label' => 'Engine',  'type' => 'text',     'help' => 'Optional — overrides the agent\'s engine (cli agents).'],
                ['name' => 'model',   'label' => 'Model',   'type' => 'text',     'help' => 'Optional — overrides the agent\'s model.'],
                ['name' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'help' => 'Optional — overrides the agent\'s timeout (default 600).'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $prompt = (string) ($config['prompt'] ?? '');
        if ($prompt === '') return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'no prompt', 'exit' => 1];

        // Which agent. A name → that agent; blank → the default agent; no agents at all →
        // the install's engine + credential chain, exactly as before agents existed. A NAMED
        // agent that does not exist is a fault, not a reason to run something else.
        $agentName = trim((string) ($config['agent'] ?? ''));
        $agent = null;
        if ($agentName !== '') {
            $agent = \Model_Agent::byName($agentName);
            if (!$agent) return self::fail("agent '{$agentName}' is not configured on this app (Data page → Agents).");
        } elseif (class_exists('\\Model_Agent')) {
            try { $agent = \Model_Agent::defaultAgent(); } catch (\Throwable $e) { $agent = null; }   // no agent table yet: legacy path
        }
        $system = self::composeSystem($agent ? (string) $agent->prePrompt : '', (string) ($config['system'] ?? ''));

        if ($agent && (string) $agent->kind === 'member') {
            // The project owner's model connection: core makes the call with the owner's key
            // (which never comes here) — Pipeline\MemberModel.
            $timeout = max(5, min(3600, (int) ($config['timeout'] ?? 0) ?: (int) ($agent->timeout ?: 600)));
            $model   = (string) ($config['model'] ?? '') ?: (string) $agent->model;   // blank = the connection's build model, chosen on core
            $meta    = ['agent' => (string) $agent->name, 'kind' => 'member', 'connection' => (int) $agent->connectionRef];
            try { $client = \app\Pipeline\MemberModel::forInstall((string) ($run['root'] ?? '') ?: null); }
            catch (\RuntimeException $e) { return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => "agent '{$agent->name}': " . $e->getMessage(), 'exit' => 1, 'meta' => $meta]; }
            $r = $client->call((int) $agent->connectionRef, $model, $system, $prompt, $timeout);
            $meta += ['model' => $r['model'], 'job' => $r['job']];
            if (!$r['ok']) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => "agent '{$agent->name}': " . $r['error'], 'exit' => 1, 'meta' => $meta];
            return ['ok' => true, 'output' => trim($r['text']), 'stdout' => $r['text'], 'stderr' => '', 'exit' => 0, 'meta' => $meta + ['usage' => $r['usage']]];
        }

        if ($agent && (string) $agent->kind === 'openai') {
            try { $key = $agent->apiKey(); }
            catch (\Throwable $e) { return self::fail("agent '{$agent->name}': its API key cannot be decrypted (rotated install key?): " . $e->getMessage()); }
            $timeout = max(5, min(3600, (int) ($config['timeout'] ?? 0) ?: (int) ($agent->timeout ?: 600)));
            $model   = (string) ($config['model'] ?? '') ?: (string) $agent->model;
            $r = \app\Pipeline\OpenAiChat::complete((string) $agent->endpoint, $key, $model, $system, $prompt, $timeout);
            if (!$r['ok']) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => "agent '{$agent->name}': " . $r['error'], 'exit' => 1,
                                   'meta' => ['agent' => (string) $agent->name, 'kind' => 'openai', 'model' => $model, 'http' => $r['http']]];
            return ['ok' => true, 'output' => trim($r['text']), 'stdout' => $r['text'], 'stderr' => '', 'exit' => 0,
                    'meta' => ['agent' => (string) $agent->name, 'kind' => 'openai', 'model' => $model, 'usage' => $r['usage']]];
        }

        $engine = (string) ($config['engine'] ?? '') ?: ($agent ? (string) $agent->engine : '');
        if (!class_exists('\\app\\EngineRegistry')) {
            return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'EngineRegistry unavailable', 'exit' => 1];
        }
        // Unset = the install's default. A NAME that is not registered is a typo or a removed
        // engine, and the step fails naming it — it does not run on some other provider.
        if ($engine === '') {
            $engine = EngineRegistry::defaultEngine();
        } elseif (!EngineRegistry::isValid($engine)) {
            return self::fail("engine '{$engine}' is not a registered engine (" . implode(', ', EngineRegistry::names()) . ') — fix the step or the agent');
        }
        $model = (string) ($config['model'] ?? '') ?: ($agent && (string) $agent->model !== '' ? (string) $agent->model : EngineRegistry::model($engine, 'worker'));

        // Self-contained: the instance runs its OWN claude, <root>/bin/claude — a hard link to
        // the host install (app\ClaudeBinary), or a real install on a remote instance. An
        // install with no bin/claude at all (the control plane itself) uses the engine's
        // configured command.
        $runDir  = (string) ($run['run_directory'] ?? '');
        $root    = $runDir !== '' ? (string) preg_replace('#/data/pipe-runs/.*$#', '', $runDir) : '';
        $instBin = $root !== '' ? $root . '/bin/claude' : '';
        $binOpt  = [];
        if ($instBin !== '' && @is_link($instBin)) {
            // A symlink whose target cannot be seen FROM THIS PROCESS is a fault, and it is
            // named — not exec'd, and not quietly swapped for whatever `claude` is on PATH.
            // It used to be exec'd: is_link() is true for a dangling link, so the run died
            // several steps in with bash's "No such file or directory" about a path that
            // plainly existed on the host. It existed on the HOST; the run had been started
            // from inside the builder sandbox, where /home/ubuntu is not mounted. Falling back
            // to PATH would have "worked" there by running a different binary under different
            // assumptions about whose credentials it carries — the instance runs its own, or
            // it does not run. (Under open_basedir PHP cannot stat outside the tree at all,
            // so there the check is skipped rather than guessed; bash will say.)
            if ((string) ini_get('open_basedir') === '' && !@file_exists($instBin)) {
                $target = (string) @readlink($instBin);
                return ['ok' => false, 'output' => null, 'stdout' => '', 'exit' => 127,
                        'stderr' => "agent step cannot run: {$instBin} is a symlink to {$target}, which does not exist from this process. "
                                  . 'Either the host install moved (claude updates itself), or this run was started from inside the AI Builder '
                                  . "sandbox, which does not mount the operator's home. Fix: on the host, as the operator, run "
                                  . "`php scripts/claude-link.php --root={$root}` — it replaces the symlink with a hard link inside the instance, "
                                  . 'which is visible from both.'];
            }
            $binOpt = ['bin' => $instBin];
        } elseif ($instBin !== '' && @is_file($instBin)) {
            $binOpt = ['bin' => $instBin];
        }

        // The headless command for THIS engine. None = the engine has no proven headless
        // launcher, and the step fails saying so — the same posture as PlanExecutor. It used
        // to run claude instead, with a model resolved for the other engine and whichever
        // Anthropic credential the chain found: work done by a provider nobody chose.
        if ($system !== '') $binOpt['system'] = $system;   // a real system prompt (--append-system-prompt)
        // The project's MCP servers (Agent Setup → Servers write <root>/.mcp.json). The step
        // runs in its run directory, where Claude would not find that file on its own.
        if ($root !== '' && is_file($root . '/.mcp.json')) $binOpt['mcp_config'] = $root . '/.mcp.json';
        $inner = EngineRegistry::agentCommand($engine, $prompt, $model, $binOpt);
        if ($inner === null) return self::fail("engine '{$engine}' has no headless launcher (headless_ready in [engine.{$engine}]), so this step cannot run; choose an engine that has one");

        // Credentials: the instance's OWN, resolved in precedence order (login token ->
        // anthropic.key.enc -> 'anthropic' connection). Never the operator's creds. Passed via
        // the child ENV, not the command line, so the key never shows up in `ps`.
        [$env, $credErr] = self::agentEnv($engine, $root);
        // An agent with its own key uses it; the install's chain is for agents without one.
        if ($agent && (string) ($agent->apiKeyEnc ?? '') !== '') {
            try { $env['ANTHROPIC_API_KEY'] = $agent->apiKey(); unset($env['CLAUDE_CONFIG_DIR']); $credErr = ''; }
            catch (\Throwable $e) { return self::fail("agent '{$agent->name}': its API key cannot be decrypted (rotated install key?): " . $e->getMessage()); }
        }
        if ($credErr !== '') return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $credErr, 'exit' => 1];

        $timeout = max(5, min(3600, (int) ($config['timeout'] ?? 0) ?: ($agent ? (int) ($agent->timeout ?: 600) : 600)));
        $cwd = $runDir ?: getcwd();
        if (is_dir($cwd)) $env['HOME'] = $cwd;   // a writable, in-instance HOME for claude's cache

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open('timeout ' . $timeout . ' bash -lc ' . escapeshellarg($inner), $desc, $pipes, is_dir($cwd) ? $cwd : null, $env);
        if (!is_resource($proc)) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'could not start agent', 'exit' => 1];
        $stdout = (string) stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($proc);

        // Surface an out-of-credit failure to the instance's admins (a flag the UI shows + one
        // email/day), and clear it on any successful agent run so the alert resolves itself.
        if ($exit === 0) {
            \app\CreditAlert::clear();
        } elseif (\app\CreditAlert::looksLikeCredit($stderr . "\n" . $stdout)) {
            \app\CreditAlert::raise($engine, $stderr !== '' ? $stderr : $stdout);
        }

        return [
            'ok'     => $exit === 0,
            'output' => trim($stdout),
            'stdout' => $stdout, 'stderr' => $stderr, 'exit' => (int) $exit,
            'meta'   => ['engine' => $engine, 'model' => $model, 'agent' => $agent ? (string) $agent->name : null, 'kind' => 'cli'],
        ];
    }

    /** The agent's pre-prompt, then the step's own system text, blank-line separated. */
    public static function composeSystem(string $prePrompt, string $stepSystem): string {
        $parts = array_values(array_filter([trim($prePrompt), trim($stepSystem)], fn($p) => $p !== ''));
        return implode("\n\n", $parts);
    }

    private static function fail(string $why): array {
        return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $why, 'exit' => 1];
    }

    /**
     * Child environment carrying the instance's OWN claude credential, resolved in precedence
     * order: (1) a persisted per-instance login token, (2) the instance's encrypted
     * anthropic.key.enc, (3) an 'anthropic' connection in the instance's ConnectionStore.
     * Never the operator's credentials. Returns [$env, $error]; a non-empty error = none found.
     */
    private static function agentEnv(string $engine, string $root): array {
        $env = getenv();
        $env['PATH'] = '/usr/local/bin:/usr/bin:/bin' . (!empty($env['PATH']) ? ':' . $env['PATH'] : '');

        // Only claude / anthropic-compatible engines take the anthropic credential chain.
        if ($engine !== 'claude' && $engine !== 'zai') return [$env, ''];
        if ($root === '') return [$env, 'agent: cannot resolve the instance root to load its claude credential'];

        // 1) persisted per-instance login token (claude setup-token / /login)
        $stateDir = $root . '/.aibuilder/state/' . $engine;
        if (@is_file($stateDir . '/.credentials.json')) { $env['CLAUDE_CONFIG_DIR'] = $stateDir; return [$env, '']; }

        // 2) the instance's own encrypted API key
        $keyFile = $root . '/secure/anthropic.key.enc';
        if (@is_file($keyFile)) {
            // A key that is THERE but will not decrypt is a fault (rotated install key,
            // corrupt file), not "no key": it stops here, named. Falling through to the
            // connection would run this step on a different account than the one stored.
            try {
                \app\ConnectionStore::useInstall($root);
                $k = \app\EncryptionService::decryptWith((string) file_get_contents($keyFile), \app\ConnectionStore::ownKey());
            } catch (\Throwable $e) {
                return [$env, "agent: {$keyFile} exists but cannot be decrypted with this install's key (rotated? corrupt?): " . $e->getMessage()];
            }
            if (!is_string($k) || $k === '') return [$env, "agent: {$keyFile} decrypted to an empty key; re-save it or delete the file"];
            $env['ANTHROPIC_API_KEY'] = $k;
            return [$env, ''];
        }

        // 3) an 'anthropic' connection in the instance's ConnectionStore
        try {
            \app\ConnectionStore::useInstall($root);
            $conn = \app\ConnectionStore::for('anthropic');
        } catch (\Throwable $e) {
            return [$env, 'agent: the connection store could not be read: ' . $e->getMessage()];
        }
        if ($conn) {
            try {
                $sec = \app\ConnectionStore::ownSecret($conn, 'accessToken');
            } catch (\Throwable $e) {
                return [$env, "agent: the 'anthropic' connection exists but its token cannot be decrypted (rotated install key?): " . $e->getMessage()];
            }
            if (!is_string($sec) || $sec === '') return [$env, "agent: the 'anthropic' connection exists but holds no token; reconnect it"];
            $env['ANTHROPIC_API_KEY'] = $sec;
            return [$env, ''];
        }

        return [$env, 'agent: no claude credential for this instance — set one via /login, secure/anthropic.key.enc, or an anthropic connection'];
    }
}
