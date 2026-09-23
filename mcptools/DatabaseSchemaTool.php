<?php
/**
 * database_schema — the tables and columns the database ACTUALLY has.
 *
 * Not what a model declares: RedBean creates columns fluidly on first store, so the real
 * schema is the union of everything that was ever written, with the widest type each
 * column has been given. That is the fluid-column trap — a reasoning agent that reads
 * models/ believes a schema nobody has. This asks the database.
 */

namespace app\mcptools;

use app\Bean;

class DatabaseSchemaTool extends BaseTool {

    public static string $name = 'database_schema';
    public static string $description = 'The real database schema: every table with its columns and column types as the database reports them (Bean::inspect + PRAGMA on SQLite), plus indexes and foreign keys. This is what exists, not what models/ declares — RedBean adds columns fluidly on first store. Pass a table name for one table in detail; omit it for the table list with column counts.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'table' => ['type' => 'string', 'description' => 'One table for full detail (columns, indexes, foreign keys). Omit for the overview.'],
        ],
        'required' => [],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $tables = Bean::inspect();
        sort($tables);
        $driver = self::driver();
        $want = isset($args['table']) ? trim((string) $args['table']) : '';
        if ($want === '') {
            $out = "# database_schema ({$driver}, " . count($tables) . " tables)\n\n";
            $out .= "| table | columns | rows |\n|---|---|---|\n";
            foreach ($tables as $t) {
                $cols = Bean::inspect($t);
                $rows = Bean::getCell('SELECT COUNT(*) FROM ' . self::ident($t));
                $out .= "| {$t} | " . count($cols) . " | {$rows} |\n";
            }
            return $out . "\nCall again with `table` for columns, indexes and foreign keys.\n";
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $want) || !in_array($want, $tables, true)) {
            return "# database_schema FAILED\n\nNo table '{$want}'. Tables: " . implode(', ', $tables) . "\n";
        }
        $out = "# database_schema: {$want} ({$driver})\n\n";
        $rows = Bean::getCell('SELECT COUNT(*) FROM ' . self::ident($want));
        $out .= "{$rows} row(s).\n\n## Columns\n\n";
        if ($driver === 'sqlite') {
            $out .= "| column | type | notnull | default | pk |\n|---|---|---|---|---|\n";
            foreach (Bean::getAll('PRAGMA table_info(' . self::ident($want) . ')') as $c) {
                $out .= "| {$c['name']} | {$c['type']} | " . ($c['notnull'] ? 'yes' : '') . ' | ' . ($c['dflt_value'] !== null ? $c['dflt_value'] : '') . ' | ' . ($c['pk'] ? 'yes' : '') . " |\n";
            }
            $idx = Bean::getAll('PRAGMA index_list(' . self::ident($want) . ')');
            $out .= "\n## Indexes\n\n";
            if (!$idx) $out .= "(none beyond the primary key)\n";
            foreach ($idx as $i) {
                $cols = array_column(Bean::getAll('PRAGMA index_info(' . self::ident($i['name']) . ')'), 'name');
                $out .= "- {$i['name']}" . ($i['unique'] ? ' UNIQUE' : '') . ' (' . implode(', ', $cols) . ")\n";
            }
            $fks = Bean::getAll('PRAGMA foreign_key_list(' . self::ident($want) . ')');
            $out .= "\n## Foreign keys\n\n";
            if (!$fks) $out .= "(none)\n";
            foreach ($fks as $f) $out .= "- {$f['from']} → {$f['table']}.{$f['to']} (on delete {$f['on_delete']})\n";
        } else {
            $out .= "| column | type |\n|---|---|\n";
            foreach (Bean::inspect($want) as $col => $type) $out .= "| {$col} | {$type} |\n";
            $out .= "\nIndexes and foreign keys: only listed for SQLite; this database is {$driver}.\n";
        }
        return $out . "\nNames ending in _id are RedBean foreign keys; _eid is a string external id; _ref is a plain integer pointer with no FK (CLAUDE.md).\n";
    }

    private static function driver(): string {
        $adapter = Bean::getDatabaseAdapter();
        return $adapter ? (string) $adapter->getDatabase()->getDatabaseType() : 'unknown';
    }

    private static function ident(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
