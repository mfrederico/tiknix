<?php
/**
 * transform — reshape data. Modes:
 *   template  — output a string (its {variables} already resolved by the Executor)
 *   jsonpath  — pull a dot-path out of `input` (which is usually a {step.output})
 *   regex     — match (first group / all) or replace against `input`
 * Deliberately NO php-eval mode (myctobot's `parser` had one — arbitrary eval, dropped).
 */

namespace app\Pipeline\Steps;

use app\Pipeline\Vars;

class TransformStep implements StepInterface {

    public static function type(): string { return 'transform'; }

    public static function schema(): array {
        return [
            'summary' => 'Reshape data: template | jsonpath | regex.',
            'fields'  => [
                ['name' => 'mode',    'label' => 'Mode',    'type' => 'select', 'options' => ['template', 'jsonpath', 'regex'], 'required' => true, 'help' => 'How to reshape the input.'],
                ['name' => 'input',   'label' => 'Input',   'type' => 'textarea', 'required' => true, 'help' => 'The value to transform — usually a {step.output}.'],
                ['name' => 'path',    'label' => 'Path',    'type' => 'text', 'help' => 'jsonpath mode — dot-path to extract.'],
                ['name' => 'pattern', 'label' => 'Pattern', 'type' => 'text', 'help' => 'regex mode — the pattern.'],
                ['name' => 'replace', 'label' => 'Replace', 'type' => 'text', 'help' => 'regex mode — replacement (omit to extract instead).'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $mode  = (string) ($config['mode'] ?? 'template');
        $input = $config['input'] ?? '';

        switch ($mode) {
            case 'jsonpath':
                $val = is_array($input) ? Vars::lookup((string) ($config['path'] ?? ''), $input) : null;
                return $this->ok($val);

            case 'regex':
                $subject = is_scalar($input) ? (string) $input : json_encode($input);
                $pattern = '#' . str_replace('#', '\#', (string) ($config['pattern'] ?? '')) . '#';
                if (array_key_exists('replace', $config)) {
                    return $this->ok(@preg_replace($pattern, (string) $config['replace'], $subject));
                }
                $val = @preg_match($pattern, $subject, $m) ? ($m[1] ?? $m[0]) : null;
                return $this->ok($val);

            case 'template':
            default:
                // Executor already resolved {vars} in config.input; just pass it through.
                return $this->ok($input);
        }
    }

    private function ok($val): array {
        return ['ok' => $val !== null, 'output' => $val,
                'stdout' => is_scalar($val) ? (string) $val : json_encode($val), 'stderr' => '', 'exit' => $val === null ? 1 : 0];
    }
}
