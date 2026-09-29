<?php
/**
 * DatabaseConnector — a MySQL or PostgreSQL database as a CONNECTION.
 *
 * A pipeline that reads from a customer's warehouse needs a host, a database, a
 * user and a password. The obvious place to put them is the pipeline file, and
 * that is exactly wrong: pipeline files live in the instance's git repo, so the
 * password would be committed, shipped in every clone, and visible to anyone who
 * can read the project. It is the same mistake the broker exists to prevent for
 * API keys.
 *
 * So a database is a connection like any other. The password is sealed with the
 * instance's key beside the connection; the host, port, database and user are
 * ordinary metadata; and a pipeline names the connection, never the credential.
 * The connections hub, the account selector and per-instance custody all come for
 * free.
 *
 * WHERE QUERIES RUN. In the INSTANCE, not the broker — see QueryStep. API
 * connectors route through core because core holds the OAuth app registration; a
 * database has no such thing, and sending a customer's warehouse traffic through
 * the control plane would be pure downside: another hop, another place the rows
 * exist, and a database client in core that nothing needs.
 *
 * NO HOST GUARD, deliberately, and unlike RestConnector. A warehouse is usually
 * ON a private network — 10.x is the normal case here, not the attack — so the
 * SSRF rules that protect outbound HTTP would block the feature's main use. The
 * exposure is also different in kind: an HTTP request to an internal service
 * often returns something useful unauthenticated, whereas a database refuses
 * everything without valid credentials. What an instance can reach here, it can
 * already reach with the shell and http steps.
 */

namespace app\services\connectors;

class DatabaseConnector extends AbstractConnector {

    private const DRIVERS = ['mysql' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL'];

    public function key(): string { return 'database'; }

    public function meta(): array {
        return [
            'label'     => 'Database',
            'auth_type' => 'api_key',
            'blurb'     => 'Read from a MySQL or PostgreSQL database in a pipeline — the password is stored encrypted here, never in the pipeline file.',
            'category'  => 'Data',
            'icon'      => 'database',
            'color'     => 'primary',
            'features'  => ['MySQL / MariaDB', 'PostgreSQL', 'Password stays server-side'],

            'key_label'       => 'Password',
            'key_placeholder' => 'the database user\'s password',
            'key_required'    => false,   // a trusted-socket or peer-auth setup has none
            'key_hint'        => 'Stored encrypted on this install. Prefer a read-only user.',
            'fields'          => [
                ['name' => 'driver', 'label' => 'Type', 'type' => 'select', 'required' => true,
                 'options' => self::DRIVERS, 'default' => 'mysql'],
                ['name' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true,
                 'placeholder' => 'db.internal or 10.0.0.7'],
                ['name' => 'port', 'label' => 'Port', 'type' => 'number',
                 'help' => 'Optional — defaults to 3306 for MySQL, 5432 for PostgreSQL.'],
                ['name' => 'dbname', 'label' => 'Database', 'type' => 'text', 'required' => true],
                ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
                ['name' => 'label', 'label' => 'Name this connection', 'type' => 'text',
                 'placeholder' => 'Reporting warehouse',
                 'help' => 'What a pipeline calls it. Useful when you connect more than one database.'],
            ],
        ];
    }

    public function isConfigured(): bool { return true; }

    public function authorizeUrl(array $ctx): string {
        throw new \Exception('A database connects with a host and password, not OAuth.');
    }

    public function exchangeCode(array $ctx): array {
        throw new \Exception('A database connects with a host and password, not OAuth.');
    }

    /**
     * Open the connection for real before storing it.
     *
     * A pipeline that fails at 3am because the host was mistyped is a bad way to
     * find out. One real connect turns that into an error on the form, next to the
     * field that is wrong.
     */
    public function validateApiKey(string $key, array $opts = []): array {
        $driver = strtolower(trim((string) ($opts['driver'] ?? 'mysql')));
        $host   = trim((string) ($opts['host'] ?? ''));
        $dbname = trim((string) ($opts['dbname'] ?? ''));
        $user   = trim((string) ($opts['username'] ?? ''));
        $port   = (int) ($opts['port'] ?? 0);

        if (!isset(self::DRIVERS[$driver])) {
            throw new \Exception('Type must be one of: ' . implode(', ', array_keys(self::DRIVERS)) . '.');
        }
        if ($host === '')   throw new \Exception('A host is required.');
        if ($dbname === '') throw new \Exception('A database name is required.');
        if ($user === '')   throw new \Exception('A username is required.');
        if ($port <= 0)     $port = $driver === 'pgsql' ? 5432 : 3306;
        if ($port > 65535)  throw new \Exception('That port number is not valid.');

        // The driver has to be compiled in, and saying which one is missing beats a
        // PDOException about an unknown driver.
        if (!in_array($driver, \PDO::getAvailableDrivers(), true)) {
            throw new \Exception("This install has no PDO driver for {$driver}. Available: "
                . implode(', ', \PDO::getAvailableDrivers()) . '.');
        }

        $dsn = self::dsn($driver, $host, $port, $dbname);

        try {
            $pdo = new \PDO($dsn, $user, $key, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT            => 10,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\PDOException $e) {
            // The driver's own words. "Access denied for user" and "Unknown database"
            // want completely different things done about them, and a generic
            // "could not connect" hides which.
            throw new \Exception('Could not connect: ' . $e->getMessage());
        }

        $eid   = $driver . '://' . $host . ':' . $port . '/' . $dbname;
        $label = trim((string) ($opts['label'] ?? '')) ?: ($dbname . ' on ' . $host);

        return [
            'access_token'  => $key,          // '' when the server needs no password
            'token_type'    => 'password',
            'scopes'        => 'database',
            'external_eid'  => $eid,
            'external_name' => $label,
            'external_url'  => '',
            'metadata'      => [
                'driver'   => $driver,
                'host'     => $host,
                'port'     => $port,
                'dbname'   => $dbname,
                'username' => $user,
                'server'   => $version,
            ],
        ];
    }

    /** Build a PDO DSN. Public so QueryStep builds it the same way. */
    public static function dsn(string $driver, string $host, int $port, string $dbname): string {
        if ($driver === 'pgsql') {
            return sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);
        }
        // charset matters: without it MySQL may hand back latin1 and a customer's
        // accented names arrive mangled into the pipeline.
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
    }

    public function brokerTools(): array { return []; }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        throw new \Exception('A database connection is queried by the pipeline itself (the query step), '
                           . 'not through the broker — its traffic does not go via the control plane.');
    }
}
