<?php
/**
 * 23_MailConnection.php — conf/mailgun.ini becomes a Mailgun CONNECTION (CONNECTOR-CATALOG-PLAN.md
 * decision 9). lib/Mailer, NotifyService and /webhook/mailgun read the install's `mail`
 * binding now, never the ini; this seed carries an install that had the ini into that world
 * once: key → the connection's sealed token, domain / fromEmail / inboundDomain → its fields,
 * signingKey → its webhook secret, endpoint → its base URL. Idempotent: an install with any
 * Mailgun connection is left alone (the ini may be stale by then), and an install with no ini
 * has nothing to migrate.
 *
 * Runs on every install by --build, so a rolled instance keeps sending without anyone
 * re-entering a key. The ini is not deleted: it is gitignored and now unread, and deleting a
 * credential file is a person's decision.
 */
use app\ConnectionStore;
use app\IsolatedPool;

$root = dirname(__DIR__, 3);
// The store is the POOL's file. On an isolated instance --build runs as the tree owner, and
// a store that user creates is read-only for the pool (ConnectionStore::assertOwnerMayWrite).
// So the migration runs as the pool: this same seed, through the instance's php-fpm socket.
if (!defined('TIKNIX_SEED_IN_POOL') && IsolatedPool::ownerOnIsolated($root)) {
    $r = IsolatedPool::runAsPool($root, '<?php define("TIKNIX_SEED_IN_POOL", 1); chdir(' . var_export($root, true) . '); '
        . 'require "bootstrap.php"; new \app\Bootstrap(); include ' . var_export(__FILE__, true) . ';');
    echo $r['output'] !== '' ? $r['output'] . "\n" : "  ERROR mail: the pool answered nothing (cgi-fcgi exit {$r['status']}) — the migration did not run\n";
    return;
}
$ini = "{$root}/conf/mailgun.ini";
if (!is_file($ini)) {
    echo "  mail: no conf/mailgun.ini — nothing to migrate (connect Mailgun under Connections when mail is wanted)\n";
    return;
}
$c = parse_ini_file($ini) ?: [];
$pick = static fn(array $keys): string => (static function () use ($c, $keys) { foreach ($keys as $k) { $v = trim((string) ($c[$k] ?? ''), " \t\n\r\0\x0B\""); if ($v !== '') return $v; } return ''; })();
$key    = $pick(['key', 'apiKey']);
$domain = $pick(['domain']);
if ($key === '' || $domain === '') {
    echo "  mail: conf/mailgun.ini has no key + domain — nothing to migrate\n";
    return;
}
$existing = ConnectionStore::candidates(['mailgun']);
if ($existing) {
    echo '  mail: kept — ' . count($existing) . ' mailgun connection(s) already here (' . implode(', ', array_map(fn($x) => $x['alias'], $existing)) . ")\n";
    return;
}
$endpoint = rtrim($pick(['endpoint', 'apiUrl']), '/');
$payload = [
    'access_token'  => $key,
    'token_type'    => 'basic',
    'scopes'        => 'api_key',
    'auth_type'     => 'api_key',
    'external_eid'  => $domain,
    'external_name' => $domain,
    'external_url'  => 'https://app.mailgun.com',
    'metadata'      => [
        'base_url'  => $endpoint !== '' ? $endpoint : 'https://api.mailgun.net',
        'auth'      => 'basic',
        'auth_name' => '',
        'username'  => 'api',
        'test_path' => '/v3/domains',
        'fields'    => [
            'domain'         => $domain,
            'from_email'     => $pick(['fromEmail']),
            'inbound_domain' => $pick(['inboundDomain', 'inbound_domain']),
        ],
    ],
];
$signing = $pick(['signingKey', 'webhook_signing_key', 'signing_key']);
if ($signing !== '') $payload['webhook_secret'] = $signing;
$id = ConnectionStore::put('mailgun', 'production', $payload);
if ($id <= 0) {
    echo "  ERROR mail: conf/mailgun.ini could not be migrated — ConnectionStore::put returned {$id}; see the log\n";
    return;
}
echo "  mail: conf/mailgun.ini → mailgun connection #{$id} ({$domain}" . ($signing !== '' ? ', webhook signing key' : '') . ") — the ini is no longer read\n";
