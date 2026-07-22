<?php
/**
 * wait — pause the run.
 *   mode "delay"       — sleep `seconds` (capped), then continue.
 *   mode "await_input" — stop and mark the RUN 'paused'; it resumes when someone
 *                        calls pipeline_continue(run_id, input). The awaited value
 *                        arrives as {input.*} and as this step's own output.
 * (Merges myctobot's wait/approval sub-modes into two clear ones.)
 */

namespace app\Pipeline\Steps;

class WaitStep implements StepInterface {

    public static function type(): string { return 'wait'; }

    public static function schema(): array {
        return [
            'summary' => 'Delay N seconds, or pause for external input (await_input → pipeline_continue).',
            'fields'  => [
                ['name' => 'mode',    'label' => 'Mode',    'type' => 'select', 'options' => ['delay', 'await_input'], 'required' => true, 'help' => 'delay = sleep; await_input = pause for input.'],
                ['name' => 'seconds', 'label' => 'Seconds', 'type' => 'number', 'help' => 'delay mode — how long (max 300).'],
                ['name' => 'prompt',  'label' => 'Prompt',  'type' => 'text',   'help' => 'await_input mode — what you\'re waiting for.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $mode = (string) ($config['mode'] ?? 'delay');
        if ($mode === 'await_input') {
            // The Executor detects `await` and persists/pauses the run.
            return ['ok' => true, 'await' => true, 'prompt' => (string) ($config['prompt'] ?? 'awaiting input'),
                    'output' => null, 'stdout' => 'awaiting input', 'stderr' => '', 'exit' => 0];
        }
        $seconds = max(0, min(300, (int) ($config['seconds'] ?? 0)));
        if ($seconds > 0) sleep($seconds);
        return ['ok' => true, 'output' => ['waited' => $seconds], 'stdout' => "waited {$seconds}s", 'stderr' => '', 'exit' => 0];
    }
}
