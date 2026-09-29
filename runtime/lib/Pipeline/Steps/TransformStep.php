<?php
/**
 * transform — reshape data. Modes:
 *   template  — output a string (its {variables} already resolved by the Executor)
 *   jsonpath  — pull a dot-path out of input (which is usually a {step.output})
 *   regex     — match (first group / all) or replace against input
 *   map       — array in, array out: pick/rename fields from EACH element
 *   flatten   — array in, array out: expand a nested array into one row per child
 * Deliberately NO php-eval mode (myctobot's parser had one — arbitrary eval, dropped).
 *
 * map and flatten exist because the first three all operate on ONE value, and every
 * real pull is a LIST: orders, inventory, invoices. Without them a pipeline that
 * fetched 250 orders could only reach into them one dot-path at a time, and the only
 * way to process the list was a goto loop per element — 250 iterations against a
 * 500-step budget, so a second page could not even finish.
 *
 * Paths in map/flatten are evaluated RELATIVE TO EACH ELEMENT, which is what makes
 * GraphQL usable without wildcard syntax: Shopify hands back
 * data.orders.edges[] of {node:{...}}, so the field path is simply node.id.
 */

namespace app\Pipeline\Steps;

use app\Pipeline\Vars;

class TransformStep implements StepInterface {

    public static function type(): string { return 'transform'; }

    public static function schema(): array {
        return [
            'summary' => 'Reshape data: template | jsonpath | regex | map | flatten.',
            'fields'  => [
                ['name' => 'mode',    'label' => 'Mode',    'type' => 'select', 'options' => ['template', 'jsonpath', 'regex', 'map', 'flatten'], 'required' => true, 'help' => 'How to reshape the input.'],
                ['name' => 'input',   'label' => 'Input',   'type' => 'textarea', 'required' => true, 'help' => 'The value to transform — usually a {step.output}.'],
                ['name' => 'path',    'label' => 'Path',    'type' => 'text', 'help' => 'jsonpath: dot-path to extract. flatten: dot-path to the nested array INSIDE each element (e.g. node.lineItems.edges).'],
                ['name' => 'pattern', 'label' => 'Pattern', 'type' => 'text', 'help' => 'regex mode — the pattern.'],
                ['name' => 'replace', 'label' => 'Replace', 'type' => 'text', 'help' => 'regex mode — replacement (omit to extract instead).'],
                ['name' => 'fields',  'label' => 'Fields',  'type' => 'keyval', 'help' => 'map/flatten — output column => dot-path within each element (e.g. order_id => node.id). Empty path keeps the element itself.'],
                ['name' => 'keep',    'label' => 'Keep from parent', 'type' => 'keyval', 'help' => 'flatten only — output column => dot-path on the PARENT, copied onto every child row.'],
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

            case 'map':
                if (!is_array($input)) return $this->fail('map needs an array as input, got ' . gettype($input));
                $fields = (array) ($config['fields'] ?? []);
                $out = [];
                foreach ($this->listOf($input) as $el) {
                    $out[] = $fields ? $this->pick($el, $fields) : $el;
                }
                return $this->ok($out);

            case 'flatten':
                if (!is_array($input)) return $this->fail('flatten needs an array as input, got ' . gettype($input));
                $path   = (string) ($config['path'] ?? '');
                $fields = (array) ($config['fields'] ?? []);
                $keep   = (array) ($config['keep'] ?? []);
                if ($path === '') return $this->fail('flatten needs `path` — the dot-path to the nested array inside each element');

                $out = [];
                foreach ($this->listOf($input) as $el) {
                    $children = is_array($el) ? Vars::lookup($path, $el) : null;
                    // A parent with no children is not an error — an order can have
                    // zero of something. It contributes no rows and the run goes on.
                    if (!is_array($children)) continue;
                    $carried = $keep ? $this->pick($el, $keep) : [];
                    foreach ($this->listOf($children) as $child) {
                        $row = $fields ? $this->pick($child, $fields) : (is_array($child) ? $child : ['value' => $child]);
                        $out[] = $carried + $row;
                    }
                }
                return $this->ok($out);

            case 'template':
                // Executor already resolved {vars} in config.input; just pass it through.
                return $this->ok($input);

            default:
                // An unknown mode used to fall through to template, so a step quietly
                // passed its input straight out and reported success. On an instance
                // running older code, mode:"map" therefore "completed" while emitting
                // the unresolved token it was handed — and the next step stored
                // nothing. A mode this build cannot honour has to say so.
                return $this->fail("unknown transform mode '{$mode}' — expected one of: "
                                 . 'template, jsonpath, regex, map, flatten');
        }
    }

    /**
     * Coerce to a plain list.
     *
     * An input may arrive already keyed (RedBean returns id-keyed rows, and a
     * decoded JSON object is an assoc array). array_values keeps iteration honest
     * and stops a keyed input silently producing a keyed output that a later
     * json_encode would render as an object instead of an array.
     */
    private function listOf($v): array {
        if (!is_array($v)) return [];
        return array_values($v);
    }

    /**
     * Build one output row: column => dot-path, resolved against $el.
     *
     * A path that does not resolve yields NULL rather than skipping the column, so
     * every row has the same shape. Ragged rows are how a bulk insert starts
     * writing different columns per record.
     */
    private function pick($el, array $fields): array {
        $row = [];
        foreach ($fields as $col => $path) {
            $path = (string) $path;
            if ($path === '') { $row[$col] = $el; continue; }
            $row[$col] = is_array($el) ? Vars::lookup($path, $el) : null;
        }
        return $row;
    }

    private function fail(string $why): array {
        return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $why, 'exit' => 1];
    }

    private function ok($val): array {
        return ['ok' => $val !== null, 'output' => $val,
                'stdout' => is_scalar($val) ? (string) $val : json_encode($val), 'stderr' => '', 'exit' => $val === null ? 1 : 0];
    }
}
