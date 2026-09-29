<?php
/**
 * query — run SQL against a connected MySQL or PostgreSQL database.
 *
 * The counterpart to db_query, which talks to the instance's OWN database. This
 * one talks to a database the customer connected: a warehouse, a legacy system, a
 * reporting replica. The connection holds the host and the password; the pipeline
 * holds neither.
 *
 * Runs IN THE INSTANCE, not through the broker. API connectors go via core because
 * core holds the OAuth app registration; a database has none, so routing a
 * customer's rows through the control plane would add a hop and a second place the
 * data exists for no benefit at all.
 *
 * Output matches db_query — {rows, count} for a read, {affected} for a write — so
 * a transform or upsert downstream does not care which of the two produced it.
 */

namespace app\Pipeline\Steps;

use app\ConnectionStore;
use app\services\connectors\DatabaseConnector;

class QueryStep implements StepInterface {

    public static function type(): string { return 'query'; }

    public static function schema(): array {
        return [
            'summary' => 'Run SQL against a connected database (MySQL/PostgreSQL). Credentials come from the connection.',
            'fields'  => [
                ['name' => 'source', 'label' => 'Source', 'type' => 'text',
                 'help'  => 'The name of a source declared on the pipeline. Use this, or name the account directly below.'],
                ['name' => 'account', 'label' => 'Database', 'type' => 'text',
                 'help'  => 'Which connected database, when more than one is connected — the name you gave it, or driver://host:port/db.'],
                ['name' => 'environment', 'label' => 'Environment', 'type' => 'select',
                 'options' => ['production', 'development'], 'help' => 'Default production.'],
                ['name' => 'sql', 'label' => 'SQL', 'type' => 'textarea', 'required' => true,
                 'help'  => 'Use ? placeholders and put values in Params — never build SQL by interpolating {variables}.'],
                ['name' => 'params', 'label' => 'Params', 'type' => 'list',
                 'help'  => 'Bound parameters, in order. These may reference {context.x} or a previous step.'],
                ['name' => 'write', 'label' => 'Write query', 'type' => 'bool',
                 'help'  => 'On for INSERT/UPDATE/DELETE (returns affected rows); off = SELECT.'],
                ['name' => 'limit', 'label' => 'Max rows', 'type' => 'number',
                 'help'  => 'Optional cap on rows returned; default 10000.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $sql = trim((string) ($config['sql'] ?? ''));
        if ($sql === '') return self::fail('no sql');

        $env     = (string) ($config['environment'] ?? 'production');
        $account = trim((string) ($config['account'] ?? ''));

        try {
            $conn = ConnectionStore::for('database', $env, $account !== '' ? $account : null);
        } catch (\Throwable $e) {
            // Ambiguity and "no such account" arrive as exceptions carrying the list
            // of what IS connected. That message is the useful part; keep it.
            return self::fail($e->getMessage());
        }
        if (!$conn || !$conn->id) {
            return self::fail("No database connection for environment '{$env}'"
                . ($account !== '' ? " matching '{$account}'" : '') . '. Connect one first.');
        }

        $meta = json_decode((string) ($conn->metadataJson ?? ''), true) ?: [];
        $driver = (string) ($meta['driver'] ?? 'mysql');
        $host   = (string) ($meta['host'] ?? '');
        $port   = (int)    ($meta['port'] ?? 0);
        $dbname = (string) ($meta['dbname'] ?? '');
        $user   = (string) ($meta['username'] ?? '');
        if ($host === '' || $dbname === '') {
            return self::fail('That database connection is missing its host or database name — reconnect it.');
        }

        // Decrypted here, in the instance whose key it is, and zeroed straight after
        // the connection is open. It is never put in the bag, so it cannot reach a
        // step output, a log line or a run record.
        $password = ConnectionStore::ownToken($conn);

        try {
            $pdo = new \PDO(
                DatabaseConnector::dsn($driver, $host, $port, $dbname),
                $user, $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                 \PDO::ATTR_TIMEOUT => 15,
                 \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        } catch (\PDOException $e) {
            return self::fail('Could not connect to ' . $dbname . ' on ' . $host . ': ' . $e->getMessage());
        } finally {
            if ($password !== '') sodium_memzero($password);
        }

        $params = array_values((array) ($config['params'] ?? []));

        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);

            if (!empty($config['write'])) {
                $n = $st->rowCount();
                return ['ok' => true, 'output' => ['affected' => $n],
                        'stdout' => "affected {$n}", 'stderr' => '', 'exit' => 0];
            }

            // Capped, and the cap is REPORTED. A silently shortened result set is
            // indistinguishable from a table that really has that many rows, and a
            // pipeline would go on to store a partial sync as if it were complete.
            $limit = (int) ($config['limit'] ?? 10000);
            $limit = max(1, min($limit, 100000));
            $rows  = [];
            while (count($rows) < $limit && ($row = $st->fetch()) !== false) $rows[] = $row;
            $truncated = $st->fetch() !== false;

            return ['ok' => true,
                    'output' => ['rows' => $rows, 'count' => count($rows), 'truncated' => $truncated],
                    'stdout' => count($rows) . ' row(s)' . ($truncated ? " (capped at {$limit})" : ''),
                    'stderr' => $truncated ? "result capped at {$limit} rows — raise `limit` or narrow the query" : '',
                    'exit' => 0];
        } catch (\PDOException $e) {
            return self::fail($e->getMessage());
        }
    }

    private static function fail(string $why): array {
        return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $why, 'exit' => 1];
    }
}
