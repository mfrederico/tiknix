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
        $model = (string) ($config['model'] ?? '') ?: EngineRegistry::model($engine, 'worker', 'sonnet');

        // Build the headless agent command; engines without a proven headless launcher
        // fall back to claude (best-effort), matching the AI Builder's own posture.
        $inner = EngineRegistry::agentCommand($engine, $prompt, $model)
              ?? EngineRegistry::agentCommand('claude', $prompt, $model);
        if ($inner === null) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'no agent launcher', 'exit' => 1];

        $timeout = max(5, min(3600, (int) ($config['timeout'] ?? 600)));
        $cwd = (string) ($run['run_directory'] ?? getcwd());

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open('timeout ' . $timeout . ' bash -lc ' . escapeshellarg($inner), $desc, $pipes, is_dir($cwd) ? $cwd : null);
        if (!is_resource($proc)) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'could not start agent', 'exit' => 1];
        $stdout = (string) stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'ok'     => $exit === 0,
            'output' => trim($stdout),
            'stdout' => $stdout, 'stderr' => $stderr, 'exit' => (int) $exit,
            'meta'   => ['engine' => $engine, 'model' => $model],
        ];
    }
}
