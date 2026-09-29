<?php
/**
 * branch — a REAL condition evaluator (myctobot's was a `// TODO` stub that returned
 * truthy). Evaluates `left <op> right` and reports ok = the boolean result, so flow
 * routes via the step's on_success / on_fail (e.g. on_success: "goto:paid",
 * on_fail: "goto:unpaid"). Merges myctobot's separate condition + switch types.
 */

namespace app\Pipeline\Steps;

class BranchStep implements StepInterface {

    public static function type(): string { return 'branch'; }

    public static function schema(): array {
        return [
            'summary' => 'Evaluate left <op> right; ok=result. Route via on_success/on_fail (goto:*).',
            'fields'  => [
                ['name' => 'left',  'label' => 'Left operand',  'type' => 'text', 'required' => true, 'help' => 'Left operand — usually a {variable}.'],
                ['name' => 'op',    'label' => 'Operator',      'type' => 'select', 'required' => true,
                    'options' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'matches', 'exists', 'truthy'],
                    'help' => 'Comparison operator.'],
                ['name' => 'right', 'label' => 'Right operand', 'type' => 'text', 'help' => 'Right operand (omit for exists / truthy).'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $left  = $config['left'] ?? null;
        $right = $config['right'] ?? null;
        $op    = strtolower((string) ($config['op'] ?? 'truthy'));

        $result = $this->evaluate($left, $op, $right);
        return ['ok' => $result, 'output' => ['result' => $result, 'op' => $op],
                'stdout' => $result ? 'true' : 'false', 'stderr' => '', 'exit' => $result ? 0 : 1];
    }

    private function evaluate($left, string $op, $right): bool {
        switch ($op) {
            case 'eq':       return $this->norm($left) == $this->norm($right);
            case 'ne':       return $this->norm($left) != $this->norm($right);
            case 'gt':       return (float) $left >  (float) $right;
            case 'gte':      return (float) $left >= (float) $right;
            case 'lt':       return (float) $left <  (float) $right;
            case 'lte':      return (float) $left <= (float) $right;
            case 'contains': return strpos((string) $left, (string) $right) !== false;
            case 'matches':  return (bool) @preg_match('#' . str_replace('#', '\#', (string) $right) . '#', (string) $left);
            case 'exists':   return $left !== null && $left !== '';
            case 'truthy':
            default:         return !empty($left) && $left !== 'false' && $left !== '0';
        }
    }

    private function norm($v) { return is_scalar($v) ? (string) $v : json_encode($v); }
}
