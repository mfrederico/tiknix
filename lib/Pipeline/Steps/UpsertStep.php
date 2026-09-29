<?php
/**
 * upsert — write an array of rows into a table on the instance database.
 *
 * The missing half of a data pull. db_query binds ONE statement with ordered
 * params, so storing a page of 250 mapped orders meant 250 goto iterations
 * against a 500-step budget — a second page could not finish. This takes the
 * array a transform produced and writes all of it in one step.
 *
 * The table shapes itself. RedBean is fluid, so a column appears the first time
 * a row carries it: pulling a new field from an API is a change to the map step,
 * not a migration. Rows go through Bean::dispense/store rather than raw SQL, so
 * FUSE models and their hooks still apply (CLAUDE.md).
 *
 * Identity comes from `key`: the columns that make a row the same row. With no
 * key every row inserts, which is what you want for an append-only log and NOT
 * what you want for a nightly order sync — hence key is how you get idempotence.
 *
 * Two column names are REFUSED rather than silently mangled, because both
 * quietly corrupt data:
 *
 *   id      RedBean's own primary key. An API's "id" written here overwrites the
 *           PK, so the second pull collides with unrelated local rows.
 *   *_id    RedBean reads any <thing>_id as an integer FK to bean type <thing>
 *           and emits a real constraint in fluid mode. A Shopify customer_id of
 *           "gid://shopify/Customer/123" is a string, and the FK it invents
 *           points at a table that does not exist.
 *
 * The convention (CLAUDE.md) is _eid for a string id from another system. This
 * step says so in the error rather than renaming behind your back — a silent
 * rename would put the data somewhere the pipeline author never looks.
 */

namespace app\Pipeline\Steps;

use app\Bean;

class UpsertStep implements StepInterface {

    public static function type(): string { return 'upsert'; }

    public static function schema(): array {
        return [
            'summary' => 'Write an array of rows into a table on the instance DB (insert or update on key).',
            'fields'  => [
                ['name' => 'table', 'label' => 'Table', 'type' => 'text', 'required' => true,
                 'help'  => 'Bean type / table name, e.g. shopifyorder. Created on first write.'],
                ['name' => 'rows',  'label' => 'Rows',  'type' => 'textarea', 'required' => true,
                 'help'  => 'An array of row objects — usually {transform_step.output} from a map or flatten.'],
                ['name' => 'key',   'label' => 'Key columns', 'type' => 'list',
                 'help'  => 'Columns identifying the same row across runs (e.g. order_eid). Omit to always insert.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $table = Bean::normalize((string) ($config['table'] ?? ''));
        if ($table === '') {
            return $this->fail('upsert needs a table name');
        }

        $rows = $config['rows'] ?? null;
        if (!is_array($rows)) {
            return $this->fail('upsert needs an array of rows in `rows`, got ' . gettype($rows));
        }
        $rows = array_values($rows);
        if (!$rows) {
            // Nothing to write is a legitimate outcome (an empty page, a filter that
            // matched nothing). Report it as success with a count of zero rather
            // than failing a run for having caught up.
            return ['ok' => true, 'output' => ['inserted' => 0, 'updated' => 0, 'total' => 0],
                    'stdout' => 'no rows', 'stderr' => '', 'exit' => 0];
        }

        $key = array_values(array_filter(array_map('strval', (array) ($config['key'] ?? []))));

        // Validate column names ONCE, against the first row, before opening a
        // transaction — a reserved name is an authoring mistake, and the run should
        // say so plainly instead of writing half a page and rolling back.
        if (!is_array($rows[0])) {
            return $this->fail('each row must be an object of column => value; got ' . gettype($rows[0]));
        }
        foreach (array_keys($rows[0]) as $col) {
            if ($why = self::reservedColumn((string) $col)) return $this->fail($why);
        }
        foreach ($key as $k) {
            if (!array_key_exists($k, $rows[0])) {
                return $this->fail("key column '{$k}' is not present in the rows being written");
            }
        }

        $inserted = 0; $updated = 0;
        Bean::begin();
        try {
            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException("row {$i} is not an object of column => value");
                }

                $bean = null;
                if ($key) {
                    $where  = implode(' AND ', array_map(static fn($k) => $k . ' = ?', $key));
                    $params = array_map(static fn($k) => $row[$k] ?? null, $key);
                    $bean   = Bean::findOne($table, $where, $params);
                }

                if ($bean) { $updated++; } else { $bean = Bean::dispense($table); $inserted++; }

                foreach ($row as $col => $val) {
                    // Anything non-scalar (a nested object the map did not flatten)
                    // is stored as JSON rather than letting RedBean stringify it to
                    // the word "Array".
                    $bean->$col = is_scalar($val) || $val === null ? $val : json_encode($val, JSON_UNESCAPED_SLASHES);
                }
                Bean::store($bean);
            }
            Bean::commit();
        } catch (\Throwable $e) {
            Bean::rollback();
            return $this->fail($e->getMessage());
        }

        $total = $inserted + $updated;
        return ['ok' => true,
                'output' => ['inserted' => $inserted, 'updated' => $updated, 'total' => $total, 'table' => $table],
                'stdout' => "{$table}: {$inserted} inserted, {$updated} updated", 'stderr' => '', 'exit' => 0];
    }

    /** Why this column name cannot be written, or '' if it is fine. */
    private static function reservedColumn(string $col): string {
        if ($col === '') {
            return 'a row has an empty column name';
        }
        if (strtolower($col) === 'id') {
            return "column 'id' is RedBean's primary key — map the source id to something like source_eid instead";
        }
        if (preg_match('/_id$/i', $col)) {
            return "column '{$col}' ends in _id, which RedBean turns into an integer foreign key to bean type '"
                 . substr($col, 0, -3) . "' — use '" . substr($col, 0, -3) . "_eid' for a string id from another system";
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $col)) {
            return "column '{$col}' is not a usable column name (letters, digits and underscore, starting with a letter)";
        }
        return '';
    }

    private function fail(string $why): array {
        return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $why, 'exit' => 1];
    }
}
