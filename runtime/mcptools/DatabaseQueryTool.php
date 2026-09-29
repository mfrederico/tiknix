<?php
/**
 * database_query — one read-only SQL statement against this install's database.
 *
 * ADMIN, HTTP only: it reads DATA, so it needs an identity, and it is deliberately NOT on
 * StdioAllowList (the jailed agent gets schema and logs, never rows). What is allowed is
 * decided by guard(): SELECT / WITH…SELECT / EXPLAIN / a fixed set of read-only PRAGMAs,
 * one statement, no comments, no writing keyword anywhere. A SELECT is wrapped so it can
 * never return more than 200 rows whatever LIMIT it carries. Every row goes through
 * Redact::row(): credential columns are withheld by name, credential shapes scrubbed.
 */

namespace app\mcptools;

use app\Bean;
use app\Redact;

class DatabaseQueryTool extends BaseTool {

    public const MAX_ROWS = 200;
    private const WRITE_RE = '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|REPLACE|ATTACH|DETACH|VACUUM|REINDEX|TRUNCATE|GRANT|REVOKE|UPSERT|MERGE|INTO)\b/i';
    private const READ_PRAGMA_RE = '/^PRAGMA\s+(table_info|table_xinfo|table_list|index_list|index_info|index_xinfo|foreign_key_list|database_list|collation_list|compile_options|schema_version|user_version|journal_mode|page_size|page_count|freelist_count|integrity_check|quick_check)\s*(\([^)]*\))?\s*$/i';

    public static string $name = 'database_query';
    public static string $description = 'Run ONE read-only SQL statement against this install\'s database and get the rows back (max 200; a larger LIMIT is capped, not refused). SELECT, WITH … SELECT, EXPLAIN, and read-only PRAGMAs (table_info, index_list, foreign_key_list, …). Writes, comments and multiple statements are refused. Credential columns (password, token, secret, hash, *_enc, …) come back as [redacted]. Requires ADMIN; not available to jailed agents.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'sql' => ['type' => 'string', 'description' => 'The statement, e.g. SELECT id, email, level FROM member ORDER BY id DESC LIMIT 20'],
        ],
        'required' => ['sql'],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $this->requireAdmin();
        try {
            [$sql, $wrapped] = self::guard((string) $args['sql']);
        } catch (\InvalidArgumentException $e) {
            return "# database_query REFUSED\n\n" . $e->getMessage() . "\n";
        }
        $t0 = microtime(true);
        try {
            $rows = Bean::getAll($sql);
        } catch (\Throwable $e) {
            return "# database_query FAILED\n\n" . Redact::secrets($e->getMessage()) . "\n";
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        $out = "# database_query — " . count($rows) . ' row(s)' . ($truncated ? ' (TRUNCATED at ' . self::MAX_ROWS . ')' : '') . ", {$ms} ms\n\n";
        if (!$rows) return $out . "(no rows)\n";
        $cols = array_keys($rows[0]);
        $redacted = array_values(array_filter($cols, fn($c) => Redact::isSensitiveColumn((string) $c)));
        if ($redacted) $out .= '_withheld by column name: ' . implode(', ', $redacted) . "_\n\n";
        $out .= '| ' . implode(' | ', $cols) . " |\n|" . str_repeat('---|', count($cols)) . "\n";
        foreach ($rows as $r) {
            $r = Redact::row($r);
            $out .= '| ' . implode(' | ', array_map(fn($v) => self::cell($v), array_values($r))) . " |\n";
        }
        return $out;
    }

    /**
     * Decide whether a statement may run, and return it in the form it runs in.
     *
     * @return array{0:string,1:bool}  the SQL to execute, and whether it was wrapped for the row cap
     * @throws \InvalidArgumentException naming the rule that refused it
     */
    public static function guard(string $sql): array {
        $sql = trim($sql);
        $sql = rtrim($sql, "; \t\n\r");
        if ($sql === '') throw new \InvalidArgumentException('Empty statement.');
        if (str_contains($sql, ';')) throw new \InvalidArgumentException('One statement only: a ";" inside the text is refused.');
        if (str_contains($sql, '--') || str_contains($sql, '/*')) throw new \InvalidArgumentException('Comments (-- or /*) are refused; send the statement alone.');
        if (preg_match(self::READ_PRAGMA_RE, $sql)) return [$sql, false];
        if (stripos($sql, 'PRAGMA') === 0) throw new \InvalidArgumentException('Only read-only PRAGMAs are allowed (table_info, table_list, index_list, index_info, foreign_key_list, database_list, journal_mode, …), with no "=" assignment.');
        if (preg_match(self::WRITE_RE, $sql, $m)) throw new \InvalidArgumentException("Read-only: the keyword {$m[1]} is refused anywhere in the statement (also inside a string literal — rephrase it).");
        if (preg_match('/^EXPLAIN\b/i', $sql)) return [$sql, false];
        if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) throw new \InvalidArgumentException('The statement must start with SELECT, WITH, EXPLAIN or PRAGMA.');
        // The cap is enforced by wrapping, so no LIMIT the caller wrote (or forgot) can exceed it.
        return ['SELECT * FROM (' . $sql . ') LIMIT ' . (self::MAX_ROWS + 1), true];
    }

    private static function cell($v): string {
        if ($v === null) return 'NULL';
        $s = (string) $v;
        $s = str_replace(["\r\n", "\n", "|"], [' ', ' ', '\\|'], $s);
        return mb_strlen($s) > 160 ? mb_substr($s, 0, 157) . '…' : $s;
    }
}
