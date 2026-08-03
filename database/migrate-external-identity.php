#!/usr/bin/env php
<?php
/**
 * Indexes for the external-identity schema (services/Schema/Seeds/04_ExternalIdentity.php).
 *
 * The seed builds the columns; RedBean's fluid mode indexes *_id columns by
 * convention and nothing else. Every column this schema looks up by is named
 * *_ref on purpose — see the seed for why — so the indexes RedBean would have
 * given us have to be declared here instead.
 *
 * Two of these are correctness, not speed:
 *
 *   idx_extid_unique   makes one handle per connection a DATABASE guarantee.
 *                      Platforms retry webhooks, and two retries arriving at once
 *                      would otherwise both find nothing and both insert.
 *
 *   idx_message_provider  the same guarantee for messages. A redelivered webhook
 *                      must not become a second copy of somebody's message. It is
 *                      a PARTIAL index: most messages have no provider_id, and
 *                      without the WHERE clause every one of them would collide
 *                      with every other on the empty string.
 *
 * Usage: php database/migrate-external-identity.php
 */

require_once __DIR__ . '/../bootstrap.php';

use app\Bean;

$app = new \app\Bootstrap();

echo "External identity migration\n";
echo "===========================\n\n";

$indexes = [
    // One handle per connection. The pair is the identity.
    'idx_extid_unique' =>
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_extid_unique
           ON externalidentity(connection_ref, external_user_id)',

    // "who is in this channel", and the purge query for idle handles.
    'idx_extid_connection' =>
        'CREATE INDEX IF NOT EXISTS idx_extid_connection
           ON externalidentity(connection_ref, last_seen_at)',

    // "which handles belong to this member" — the linked-account lookup, and the
    // one Model_Member::delete() uses to unlink before an account goes.
    'idx_extid_member' =>
        'CREATE INDEX IF NOT EXISTS idx_extid_member
           ON externalidentity(member_ref)',

    // Routing an inbound message to its thread: platform + channel id.
    'idx_thread_external' =>
        'CREATE INDEX IF NOT EXISTS idx_thread_external
           ON thread(connection_ref, external_ref)',

    // Attribution: "everything this handle said".
    'idx_message_extid' =>
        'CREATE INDEX IF NOT EXISTS idx_message_extid
           ON message(external_identity_ref)',

    // Webhook-retry idempotency. Partial, so the 23-of-25 messages with no
    // provider_id are not all fighting over one value.
    'idx_message_provider' =>
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_message_provider
           ON message(transport, provider_id)
         WHERE provider_id IS NOT NULL AND provider_id != ''",
];

$made = 0;
$failed = 0;

foreach ($indexes as $name => $sql) {
    try {
        Bean::exec($sql);
        echo "  ok      $name\n";
        $made++;
    } catch (\Throwable $e) {
        // Loud, and does not stop the rest. A unique index that cannot be built
        // means the data already violates it, which is worth knowing precisely
        // rather than as "migration failed".
        echo "  FAILED  $name — " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n  $made index(es) in place";
echo $failed ? ", $failed FAILED\n" : "\n";

if ($failed) {
    echo "\n  A failed UNIQUE index means existing rows already break it. Find them with:\n";
    echo "    SELECT connection_ref, external_user_id, COUNT(*) FROM externalidentity\n";
    echo "     GROUP BY 1,2 HAVING COUNT(*) > 1;\n";
}

exit($failed ? 1 : 0);
