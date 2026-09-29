<?php
/**
 * shell — run a command, capture stdout/stderr/exit. Runs with the instance's own
 * privileges (a pipeline is part of the instance's code, authored by its admin);
 * a later phase jails it like the AI Builder agents on capricorn instances.
 */

namespace app\Pipeline\Steps;

class ShellStep implements StepInterface {

    public static function type(): string { return 'shell'; }

    public static function schema(): array {
        return [
            'summary' => 'Run a shell command in the run directory.',
            'fields'  => [
                ['name' => 'command', 'label' => 'Command', 'type' => 'textarea', 'required' => true, 'help' => 'The command to run. Use {variables} freely.'],
                ['name' => 'cwd',     'label' => 'Working dir', 'type' => 'text', 'help' => 'Optional — working directory; defaults to the run directory.'],
                ['name' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'help' => 'Optional — seconds; default 120.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $cmd = (string) ($config['command'] ?? '');
        if ($cmd === '') return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'no command', 'exit' => 1];
        $cwd     = (string) ($config['cwd'] ?? ($run['run_directory'] ?? getcwd()));
        $timeout = max(1, min(3600, (int) ($config['timeout'] ?? 120)));

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open('timeout ' . $timeout . ' bash -lc ' . escapeshellarg($cmd), $descriptors, $pipes, is_dir($cwd) ? $cwd : null);
        if (!is_resource($proc)) return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'could not start', 'exit' => 1];

        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit   = proc_close($proc);

        $stdout = (string) $stdout; $stderr = (string) $stderr;
        // If stdout is JSON, expose it structured as `output`; else output = trimmed text.
        $trimmed = trim($stdout);
        $decoded = ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) ? json_decode($trimmed, true) : null;
        return [
            'ok'     => $exit === 0,
            'output' => $decoded !== null ? $decoded : $trimmed,
            'stdout' => $stdout, 'stderr' => $stderr, 'exit' => (int) $exit,
        ];
    }
}
