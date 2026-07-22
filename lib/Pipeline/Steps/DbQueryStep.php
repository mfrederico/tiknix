<?php
/**
 * db_query — query the instance's OWN database via RedBean (myctobot's
 * datastore_query). Reads by default (getAll → rows); set write:true for an
 * INSERT/UPDATE/DELETE (exec → affected-ish). It's the instance's own DB, in an
 * admin-authored pipeline — parameterize with `params` to stay injection-safe.
 */

namespace app\Pipeline\Steps;

use RedBeanPHP\R;

class DbQueryStep implements StepInterface {

    public static function type(): string { return 'db_query'; }

    public static function schema(): array {
        return [
            'summary' => 'Run a parameterized SQL query on the instance DB (read: rows; write: affected).',
            'fields'  => [
                ['name' => 'sql',    'label' => 'SQL',    'type' => 'textarea', 'required' => true, 'help' => 'SQL with ? placeholders.'],
                ['name' => 'params', 'label' => 'Params', 'type' => 'list',     'help' => 'Optional — bound parameters, in order.'],
                ['name' => 'write',  'label' => 'Write query', 'type' => 'bool', 'help' => 'On for INSERT/UPDATE/DELETE (returns affected rows); off = SELECT.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $sql = trim((string) ($config['sql'] ?? ''));
        if ($sql === '') return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => 'no sql', 'exit' => 1];
        $params = array_values((array) ($config['params'] ?? []));

        try {
            if (!empty($config['write'])) {
                $affected = R::exec($sql, $params);
                return ['ok' => true, 'output' => ['affected' => (int) $affected], 'stdout' => "affected {$affected}", 'stderr' => '', 'exit' => 0];
            }
            $rows = R::getAll($sql, $params);
            return ['ok' => true, 'output' => ['rows' => $rows, 'count' => count($rows)],
                    'stdout' => json_encode($rows, JSON_UNESCAPED_SLASHES), 'stderr' => '', 'exit' => 0];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $e->getMessage(), 'exit' => 1];
        }
    }
}
