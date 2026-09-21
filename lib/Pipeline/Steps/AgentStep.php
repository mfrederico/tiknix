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
            'summary' => 'Run an AI agent with a prompt; returns its text output.',
            'fields'  => [
                ['name' => 'prompt',  'label' => 'Prompt',  'type' => 'textarea', 'required' => true, 'help' => 'The task/prompt for the agent. Use {context.x} / {step.output} variables.'],
                ['name' => 'engine',  'label' => 'Engine',  'type' => 'text',     'help' => 'Optional — an EngineRegistry engine; default = the instance default.'],
                ['name' => 'model',   'label' => 'Model',   'type' => 'text',     'help' => 'Optional — model tier override; default the engine worker tier.'],
                ['name' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'help' => 'Optional — seconds; default 600.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $prompt = (string) ($config['prompt'] ?? '');
        if ($prompt === '') return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'no prompt', 'exit' => 1];

        $engine = (string) ($config['engine'] ?? '');
        if (!class_exists('\\app\\EngineRegistry')) {
            return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'EngineRegistry unavailable', 'exit' => 1];
        }
        if ($engine === '' || !EngineRegistry::isValid($engine)) $engine = EngineRegistry::defaultEngine();
        $model = (string) ($config['model'] ?? '') ?: EngineRegistry::model($engine, 'worker');

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

        // Build the headless agent command; engines without a proven headless launcher
        // fall back to claude (best-effort), matching the AI Builder's own posture.
        $inner = EngineRegistry::agentCommand($engine, $prompt, $model, $binOpt)
              ?? EngineRegistry::agentCommand('claude', $prompt, $model, $binOpt);
        if ($inner === null) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'no agent launcher', 'exit' => 1];

        // Credentials: the instance's OWN, resolved in precedence order (login token ->
        // anthropic.key.enc -> 'anthropic' connection). Never the operator's creds. Passed via
        // the child ENV, not the command line, so the key never shows up in `ps`.
        [$env, $credErr] = self::agentEnv($engine, $root);
        if ($credErr !== '') return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $credErr, 'exit' => 1];

        $timeout = max(5, min(3600, (int) ($config['timeout'] ?? 600)));
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
            'meta'   => ['engine' => $engine, 'model' => $model],
        ];
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
            try {
                \app\ConnectionStore::useInstall($root);
                $k = \app\EncryptionService::decryptWith((string) @file_get_contents($keyFile), \app\ConnectionStore::ownKey());
                if (is_string($k) && $k !== '') { $env['ANTHROPIC_API_KEY'] = $k; return [$env, '']; }
            } catch (\Throwable $e) { /* fall through to connections */ }
        }

        // 3) an 'anthropic' connection in the instance's ConnectionStore
        try {
            \app\ConnectionStore::useInstall($root);
            $conn = \app\ConnectionStore::for('anthropic');
            if ($conn) {
                $sec = \app\ConnectionStore::ownSecret($conn, 'accessToken');
                if (is_string($sec) && $sec !== '') { $env['ANTHROPIC_API_KEY'] = $sec; return [$env, '']; }
            }
        } catch (\Throwable $e) { /* fall through */ }

        return [$env, 'agent: no claude credential for this instance — set one via /login, secure/anthropic.key.enc, or an anthropic connection'];
    }
}
